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
 * Fluxo: reune candidatas -> ordena por prioridade -> filtra -> monta o
 * escopo de cada acao -> aplica -> respeita exclusividade -> teto global.
 *
 * Zero IO. Zero framework. Recebe CartContext, devolve DiscountResult.
 *
 * Desde a v0.3 o motor acumula uma DiscountAllocation em vez de somas
 * soltas: cada centavo sabe de qual componente de qual item ele saiu.
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
        $accumulated = DiscountAllocation::empty();

        /** @var array<int,AppliedDiscount> $applied */
        $applied = [];
        /** @var array<int,RejectedRule> $rejected */
        $rejected = [];
        /** @var array<int,string> $usedExclusivityGroups */
        $usedExclusivityGroups = [];

        $halted = false;
        $exclusiveApplied = false;

        foreach ($this->candidates($cart) as $rule) {
            if ($halted) {
                $rejected[] = new RejectedRule($rule->id, $rule->name, RejectionReason::StoppedByPreviousRule);

                continue;
            }

            $rejection = $this->reasonToSkip($rule, $cart, $now, $exclusiveApplied, $usedExclusivityGroups);

            if ($rejection !== null) {
                $rejected[] = new RejectedRule($rule->id, $rule->name, $rejection);

                continue;
            }

            $ruleAllocation = DiscountAllocation::empty();

            foreach ($rule->actions as $action) {
                $scope = $this->buildScope($rule, $action, $cart, $accumulated->merge($ruleAllocation));

                if ($scope->isEmpty()) {
                    continue;
                }

                $result = $this->actions->get($action->type)->calculate($action, $cart, $scope);

                if ($result->isEmpty()) {
                    continue;
                }

                $ruleAllocation = $ruleAllocation->merge($result);

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

            if ($ruleAllocation->isEmpty()) {
                $rejected[] = new RejectedRule($rule->id, $rule->name, RejectionReason::NoDiscountValue);

                continue;
            }

            $accumulated = $accumulated->merge($ruleAllocation);

            if ($rule->exclusivityGroup !== null) {
                $usedExclusivityGroups[] = $rule->exclusivityGroup;
            }

            if ($rule->isExclusive()) {
                $exclusiveApplied = true;
            }

            if ($rule->stopFurtherProcessing) {
                $halted = true;
            }
        }

        $accumulated = $this->applyGlobalCap($accumulated, $subtotal);

        return new DiscountResult(
            subtotal: $subtotal,
            shippingCost: $cart->shippingCost,
            allocation: $accumulated,
            applied: $applied,
            rejected: $rejected,
        );
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
     * Aqui mora a diferenca entre 10%+10% = 20% e 10%+10% = 19%: com base
     * 'current' o saldo ja concedido e descontado do disponivel; com
     * 'original' o escopo ignora o que veio antes.
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

    /**
     * O filtro de componentes so vale para o alvo Components. Nos demais
     * alvos o desconto incide no item inteiro, como sempre incidiu.
     *
     * @return array<int,string>
     */
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

    /** @param  array<int,string>  $usedExclusivityGroups */
    private function reasonToSkip(
        Rule $rule,
        CartContext $cart,
        DateTimeImmutable $now,
        bool $exclusiveApplied,
        array $usedExclusivityGroups,
    ): ?RejectionReason {
        if (! $rule->active) {
            return RejectionReason::Inactive;
        }

        if (! $rule->isWithinDateWindow($now)) {
            return RejectionReason::OutsideDateWindow;
        }

        if ($rule->requiresCoupon() && ($rule->couponCode === null || ! $cart->hasCoupon($rule->couponCode))) {
            return RejectionReason::CouponNotProvided;
        }

        if ($exclusiveApplied) {
            return RejectionReason::ExclusivityConflict;
        }

        if ($rule->exclusivityGroup !== null && in_array($rule->exclusivityGroup, $usedExclusivityGroups, true)) {
            return RejectionReason::ExclusivityConflict;
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
