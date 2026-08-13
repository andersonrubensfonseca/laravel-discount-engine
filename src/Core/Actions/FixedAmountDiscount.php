<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Actions;

use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/** Valor fixo em centavos, rateado proporcionalmente pelo escopo. */
final class FixedAmountDiscount implements DiscountAction
{
    public static function key(): string
    {
        return 'fixed_amount';
    }

    public static function label(): string
    {
        return 'Desconto de valor fixo';
    }

    public function calculate(
        ActionDefinition $definition,
        CartContext $cart,
        DiscountScope $scope,
    ): DiscountAllocation {
        $discount = Money::fromCents((int) round($definition->value))->atLeastZero();

        return DiscountAllocation::spread($scope, $discount);
    }
}
