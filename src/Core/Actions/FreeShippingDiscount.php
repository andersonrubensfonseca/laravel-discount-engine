<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Actions;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/**
 * Frete gratis (ou subsidio parcial, se maxDiscount for menor que o frete).
 * A base recebida aqui e o valor do frete, nao o subtotal — o motor resolve isso
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

    public function calculate(ActionDefinition $definition, CartContext $cart, Money $base): Money
    {
        if ($definition->maxDiscount !== null) {
            return $base->clampTo($definition->maxDiscount);
        }

        return $base;
    }
}
