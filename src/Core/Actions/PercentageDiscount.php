<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Actions;

use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/** Percentual sobre o escopo, com teto opcional. */
final class PercentageDiscount implements DiscountAction
{
    public static function key(): string
    {
        return 'percentage';
    }

    public static function label(): string
    {
        return 'Desconto percentual';
    }

    public function calculate(
        ActionDefinition $definition,
        CartContext $cart,
        DiscountScope $scope,
    ): DiscountAllocation {
        $percent = min(max($definition->value, 0.0), 100.0);
        $discount = $scope->total()->percentage($percent);

        if ($definition->maxDiscount !== null) {
            $discount = $discount->clampTo($definition->maxDiscount);
        }

        return DiscountAllocation::spread($scope, $discount);
    }
}
