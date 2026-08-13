<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Actions;

use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/**
 * Frete gratis, ou subsidio parcial quando maxDiscount e menor que o frete.
 * O escopo aqui contem um unico componente sintetico, montado pelo motor
 * a partir do ActionTarget::Shipping.
 */
final class FreeShippingDiscount implements DiscountAction
{
    public static function key(): string
    {
        return 'free_shipping';
    }

    public static function label(): string
    {
        return 'Frete gratis';
    }

    public function calculate(
        ActionDefinition $definition,
        CartContext $cart,
        DiscountScope $scope,
    ): DiscountAllocation {
        $discount = $scope->total();

        if ($definition->maxDiscount !== null) {
            $discount = $discount->clampTo($definition->maxDiscount);
        }

        return DiscountAllocation::spread($scope, $discount);
    }
}
