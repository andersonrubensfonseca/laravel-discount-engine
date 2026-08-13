<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Engine;

use DateTimeImmutable;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
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
use SolutionsTI\DiscountEngine\Core\Rule\Rule;

/**
 * O coracao do pacote.
 *
 * Fluxo: reune candidatas -> ordena por prioridade -> filtra -> aplica ->
 * respeita exclusividade -> aplica teto global -> rateia por item.
 *
 * Zero IO. Zero framework. Recebe CartContext, devolve DiscountResult.
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
        $shipping = $cart->shippingCost;

        $itemsDiscount = Money::zero();
        $shippingDiscount = Money::zero();

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

            $ruleDiscount = Money::zero();

            foreach ($rule->actions as $action) {
                $handler = $this->actions->get($action->type);

                $base = $this->resolveBase(
                    $rule->calculationBase,
                    $action->target,
                    $subtotal,
                    $shipping,
                    $itemsDiscount,
                    $shippingDiscount,
                );

                if (! $base->isPositive()) {
                    continue;
                }

                $amount = $handler->calculate($action, $cart, $base);

                if (! $amount->isPositive()) {
                    continue;
                }

                if ($action->target === ActionTarget::Shipping) {
                    $shippingDiscount = $shippingDiscount->add($amount);
                } else {
                    $itemsDiscount = $itemsDiscount->add($amount);
                }

                $ruleDiscount = $ruleDiscount->add($amount);

                $applied[] = new AppliedDiscount(
                    ruleId: $rule->id,
                    ruleName: $rule->name,
                    actionType: $action->type,
                    target: $action->target,
                    amount: $amount,
                    couponCode: $rule->couponCode,
                );
            }

            if ($ruleDiscount->isZero()) {
                $rejected[] = new RejectedRule($rule->id, $rule->name, RejectionReason::NoDiscountValue);

                continue;
            }

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

        // Redes de seguranca: nunca descontar mais do que existe.
        $itemsDiscount = $itemsDiscount->clampTo($subtotal);
        $shippingDiscount = $shippingDiscount->clampTo($shipping);
        $itemsDiscount = $this->applyGlobalCap($itemsDiscount, $subtotal);

        return new DiscountResult(
            subtotal: $subtotal,
            shippingCost: $shipping,
            itemsDiscount: $itemsDiscount,
            shippingDiscount: $shippingDiscount,
            applied: $applied,
            rejected: $rejected,
            itemAllocations: $this->allocateToItems($cart, $itemsDiscount),
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
     * Aqui mora a diferenca entre 10%+10% = 20% e 10%+10% = 19%.
     * A regra declara qual comportamento quer; o motor nao adivinha.
     */
    private function resolveBase(
        CalculationBase $calculationBase,
        ActionTarget $target,
        Money $subtotal,
        Money $shipping,
        Money $itemsDiscount,
        Money $shippingDiscount,
    ): Money {
        if ($target === ActionTarget::Shipping) {
            return $calculationBase === CalculationBase::Original
                ? $shipping
                : $shipping->subtract($shippingDiscount)->atLeastZero();
        }

        return $calculationBase === CalculationBase::Original
            ? $subtotal
            : $subtotal->subtract($itemsDiscount)->atLeastZero();
    }

    private function applyGlobalCap(Money $discount, Money $subtotal): Money
    {
        if ($this->globalCapPercentage === null) {
            return $discount;
        }

        return $discount->clampTo($subtotal->percentage($this->globalCapPercentage));
    }

    /**
     * Rateia o desconto de itens proporcionalmente ao subtotal de cada linha.
     * Usa o metodo do maior resto: a soma das fatias bate exatamente com o total.
     *
     * @return array<array-key,Money>
     */
    private function allocateToItems(CartContext $cart, Money $itemsDiscount): array
    {
        if ($itemsDiscount->isZero() || $cart->items === []) {
            return [];
        }

        $weights = [];

        foreach ($cart->items as $index => $item) {
            $weights[$index] = $item->subtotal()->cents;
        }

        if (array_sum($weights) <= 0) {
            return [];
        }

        $shares = $itemsDiscount->allocate($weights);
        $allocations = [];

        foreach ($cart->items as $index => $item) {
            /** @var CartItem $item */
            $allocations[$item->id] = $shares[$index];
        }

        return $allocations;
    }
}
