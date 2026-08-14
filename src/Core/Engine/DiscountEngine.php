<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Engine;

use DateTimeImmutable;
use SolutionsTI\DiscountEngine\Core\Allocation\AllocationEntry;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Allocation\ScopedComponent;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\RuleRepository;
use SolutionsTI\DiscountEngine\Core\Contracts\UsageTracker;
use SolutionsTI\DiscountEngine\Core\Enums\ActionTarget;
use SolutionsTI\DiscountEngine\Core\Enums\CalculationBase;
use SolutionsTI\DiscountEngine\Core\Enums\RejectionReason;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Registry\ActionRegistry;
use SolutionsTI\DiscountEngine\Core\Result\AppliedDiscount;
use SolutionsTI\DiscountEngine\Core\Result\DiscountResult;
use SolutionsTI\DiscountEngine\Core\Result\RejectedRule;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;
use SolutionsTI\DiscountEngine\Core\Rule\Rule;

/**
 * O coracao do pacote.
 *
 * Zero IO. Zero framework. Recebe CartContext, devolve DiscountResult.
 *
 * Cada centavo sabe de qual componente de qual item ele saiu, e cada regra
 * descartada sabe por que — os dois alimentam nota fiscal e simulador.
 */
final class DiscountEngine
{
    public function __construct(
        private readonly RuleRepository $rules,
        private readonly ActionRegistry $actions,
        private readonly ConditionMatcher $matcher,
        private readonly ?UsageTracker $usage = null,
        /** Teto de seguranca: nenhum pedido recebe mais que N% de desconto somado. */
        private readonly ?float $globalCapPercentage = null,
    ) {
    }

    public function evaluate(CartContext $cart, ?DateTimeImmutable $now = null): DiscountResult
    {
        $now ??= new DateTimeImmutable();

        $subtotal = $cart->subtotal();
        $candidates = $this->candidates($cart);

        $accumulated = DiscountAllocation::empty();

        /** @var array<int,AppliedDiscount> $applied */
        $applied = [];
        /** @var array<int,RejectedRule> $rejected */
        $rejected = [];
        /** @var array<array-key,string> $appliedRules  id => nome */
        $appliedRules = [];
        /**
         * grupo => as rejeicoes dele ja foram emitidas?
         *
         * true  o grupo foi resolvido por melhor oferta, que ja registrou
         *       vencedor e perdedores. Os membros seguintes sao pulados em
         *       silencio, senao apareceriam rejeitados duas vezes — e o
         *       proprio vencedor entraria na lista de rejeitadas.
         * false o grupo foi resolvido por prioridade: quem chega depois e
         *       rejeitado por conflito, um a um.
         *
         * @var array<string,bool> $resolvedGroups
         */
        $resolvedGroups = [];

        $halted = false;

        foreach ($candidates as $rule) {
            if ($halted) {
                $rejected[] = new RejectedRule($rule->id, $rule->name, RejectionReason::StoppedByPreviousRule);

                continue;
            }

            // Grupo ja decidido: quem chega depois nao entra.
            if ($rule->resolutionGroup !== null && array_key_exists($rule->resolutionGroup, $resolvedGroups)) {
                if ($resolvedGroups[$rule->resolutionGroup] === false) {
                    $rejected[] = new RejectedRule($rule->id, $rule->name, RejectionReason::ExclusivityConflict);
                }

                continue;
            }

            $rejection = $this->reasonToSkip($rule, $cart, $now);

            if ($rejection !== null) {
                $rejected[] = new RejectedRule($rule->id, $rule->name, $rejection);

                continue;
            }

            if ($rule->usesBestOfferResolution()) {
                $outcome = $this->resolveBestOffer($rule, $candidates, $cart, $now, $accumulated);

                $resolvedGroups[$rule->resolutionGroup] = true;
                $rejected = array_merge($rejected, $outcome['rejected']);

                if ($outcome['rule'] === null) {
                    continue;
                }

                $winner = $outcome['rule'];
                $simulation = $outcome['simulation'];
            } else {
                $winner = $rule;
                $simulation = $this->simulate($rule, $cart, $accumulated);

                if ($simulation['allocation']->isEmpty()) {
                    $rejected[] = new RejectedRule($rule->id, $rule->name, RejectionReason::NoDiscountValue);

                    continue;
                }
            }

            /**
             * Exclusivo de verdade: a regra e a UNICA do pedido.
             *
             * Ate a v0.3 isto so bloqueava as regras seguintes — quem tinha
             * aplicado antes continuava valendo, e o cliente levava os dois.
             * Como a simulacao ja foi feita contra uma base limpa, basta
             * descartar o que veio antes.
             */
            if ($winner->isExclusive()) {
                foreach ($appliedRules as $id => $name) {
                    $rejected[] = new RejectedRule($id, $name, RejectionReason::SupersededByExclusiveRule);
                }

                $applied = [];
                $appliedRules = [];
                $accumulated = DiscountAllocation::empty();
                $halted = true;
            }

            $accumulated = $accumulated->merge($simulation['allocation']);
            $applied = array_merge($applied, $simulation['applied']);
            $appliedRules[$winner->id] = $winner->name;

            if ($winner->resolutionGroup !== null && ! array_key_exists($winner->resolutionGroup, $resolvedGroups)) {
                $resolvedGroups[$winner->resolutionGroup] = false;
            }

            if ($winner->stopFurtherProcessing) {
                $halted = true;
            }
        }

        return new DiscountResult(
            subtotal: $subtotal,
            shippingCost: $cart->shippingCost,
            allocation: $this->applyGlobalCap($accumulated, $subtotal),
            applied: $applied,
            rejected: $rejected,
        );
    }

    /**
     * Simula uma regra sem se comprometer com ela.
     *
     * Regra exclusiva simula contra base limpa: se ela vai descartar o que
     * veio antes, calcular sobre o saldo ja reduzido daria um valor menor
     * que o real.
     *
     * @return array{allocation:DiscountAllocation,applied:array<int,AppliedDiscount>}
     */
    private function simulate(Rule $rule, CartContext $cart, DiscountAllocation $accumulated): array
    {
        $baseline = $rule->isExclusive() ? DiscountAllocation::empty() : $accumulated;

        $allocation = DiscountAllocation::empty();
        $applied = [];

        foreach ($rule->actions as $action) {
            $scope = $this->buildScope($rule, $action, $cart, $baseline->merge($allocation));

            if ($scope->isEmpty()) {
                continue;
            }

            $result = $this->actions->get($action->type)->calculate($action, $cart, $scope);

            if ($result->isEmpty()) {
                continue;
            }

            $allocation = $allocation->merge($result);

            $applied[] = new AppliedDiscount(
                ruleId: $rule->id,
                ruleName: $rule->name,
                actionType: $action->type,
                target: $action->target,
                amount: $result->total(),
                couponCode: $rule->couponCode,
                allocation: $result,
            );
        }

        return ['allocation' => $allocation, 'applied' => $applied];
    }

    /**
     * Resolve um grupo inteiro pelo maior desconto.
     *
     * Simula todas as regras elegiveis do grupo contra a MESMA base e
     * aplica a que der mais desconto ao cliente. Empate fica com a de
     * menor prioridade numerica, que ja e a ordem da lista.
     *
     * @param  array<int,Rule>  $candidates
     * @return array{rule:?Rule,simulation:array{allocation:DiscountAllocation,applied:array<int,AppliedDiscount>},rejected:array<int,RejectedRule>}
     */
    private function resolveBestOffer(
        Rule $trigger,
        array $candidates,
        CartContext $cart,
        DateTimeImmutable $now,
        DiscountAllocation $accumulated,
    ): array {
        $winner = null;
        $winnerSimulation = ['allocation' => DiscountAllocation::empty(), 'applied' => []];
        $best = Money::zero();
        $rejected = [];
        $contenders = [];

        foreach ($candidates as $candidate) {
            if ($candidate->resolutionGroup !== $trigger->resolutionGroup) {
                continue;
            }

            $reason = $this->reasonToSkip($candidate, $cart, $now);

            if ($reason !== null) {
                $rejected[] = new RejectedRule($candidate->id, $candidate->name, $reason);

                continue;
            }

            $simulation = $this->simulate($candidate, $cart, $accumulated);
            $total = $simulation['allocation']->total();

            if (! $total->isPositive()) {
                $rejected[] = new RejectedRule($candidate->id, $candidate->name, RejectionReason::NoDiscountValue);

                continue;
            }

            $contenders[] = $candidate;

            if ($total->greaterThan($best)) {
                $best = $total;
                $winner = $candidate;
                $winnerSimulation = $simulation;
            }
        }

        foreach ($contenders as $contender) {
            if ($winner !== null && $contender->id === $winner->id) {
                continue;
            }

            $rejected[] = new RejectedRule(
                $contender->id,
                $contender->name,
                RejectionReason::SupersededByBetterOffer,
            );
        }

        return ['rule' => $winner, 'simulation' => $winnerSimulation, 'rejected' => $rejected];
    }

    /** @return array<int,Rule> candidatas ja ordenadas por prioridade */
    private function candidates(CartContext $cart): array
    {
        $candidates = array_merge(
            $this->rules->automaticRules(),
            $cart->couponCodes === [] ? [] : $this->rules->rulesForCoupons($cart->couponCodes),
        );

        usort($candidates, static fn (Rule $a, Rule $b): int => $a->priority <=> $b->priority);

        return $candidates;
    }

    /**
     * Monta o recorte sobre o qual a acao vai agir.
     *
     * Aqui mora a diferenca entre 10%+10% = 20% e 10%+10% = 19%.
     */
    private function buildScope(
        Rule $rule,
        ActionDefinition $action,
        CartContext $cart,
        DiscountAllocation $used,
    ): DiscountScope {
        $isCurrent = $rule->calculationBase === CalculationBase::Current;

        if ($action->target->isShipping()) {
            $spent = $isCurrent ? $used->shippingTotal() : Money::zero();
            $available = $cart->shippingCost->subtract($spent)->atLeastZero();

            return $available->isPositive()
                ? DiscountScope::of([ScopedComponent::forShipping($available, $cart->shippingCost)])
                : DiscountScope::empty();
        }

        return DiscountScope::forCart(
            cart: $cart,
            componentTypes: $this->componentTypes($action),
            alreadyDiscounted: $isCurrent ? $used->byComponent() : [],
        );
    }

    /** @return array<int,string> */
    private function componentTypes(ActionDefinition $action): array
    {
        if ($action->target !== ActionTarget::Components) {
            return [];
        }

        $types = $action->meta('component_types', []);

        return array_values(array_filter(
            is_array($types) ? $types : [$types],
            static fn ($type): bool => is_string($type) && $type !== '',
        ));
    }

    private function reasonToSkip(Rule $rule, CartContext $cart, DateTimeImmutable $now): ?RejectionReason
    {
        if (! $rule->active) {
            return RejectionReason::Inactive;
        }

        if (! $rule->isWithinDateWindow($now)) {
            return RejectionReason::OutsideDateWindow;
        }

        if ($rule->requiresCoupon() && ($rule->couponCode === null || ! $cart->hasCoupon($rule->couponCode))) {
            return RejectionReason::CouponNotProvided;
        }

        if ($this->usage !== null && ! $this->usage->hasRemainingUses($rule, $cart)) {
            return RejectionReason::UsageLimitReached;
        }

        if (! $this->matcher->matches($rule->conditions, $cart)) {
            return RejectionReason::ConditionsNotMet;
        }

        return null;
    }

    /**
     * O teto incide sobre os itens; frete tem regra propria e fica de fora,
     * senao um frete caro consumiria a cota de desconto dos produtos.
     */
    private function applyGlobalCap(DiscountAllocation $allocation, Money $subtotal): DiscountAllocation
    {
        if ($this->globalCapPercentage === null) {
            return $allocation;
        }

        $ceiling = $subtotal->percentage($this->globalCapPercentage);

        $items = DiscountAllocation::of(array_values(array_filter(
            $allocation->entries,
            static fn (AllocationEntry $entry): bool => ! $entry->isShipping(),
        )));

        if (! $items->total()->greaterThan($ceiling)) {
            return $allocation;
        }

        $shipping = DiscountAllocation::of(array_values(array_filter(
            $allocation->entries,
            static fn (AllocationEntry $entry): bool => $entry->isShipping(),
        )));

        return $items->clampTo($ceiling)->merge($shipping);
    }
}
