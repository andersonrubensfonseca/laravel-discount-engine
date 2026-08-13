<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Actions;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/**
 * Valor fixo. O $value da definicao vem em centavos.
 * Nunca desconta mais do que a base — carrinho nao fica negativo.
 */
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

    public function calculate(ActionDefinition $definition, CartContext $cart, Money $base): Money
    {
        $discount = Money::fromCents((int) round($definition->value));

        return $discount->atLeastZero()->clampTo($base);
    }
}
