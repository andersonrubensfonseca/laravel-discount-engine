<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Conditions;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\ConditionEvaluator;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition;

/** "Subtotal do carrinho >= R$ 200" — o valor cadastrado vem em centavos. */
final class CartSubtotalCondition implements ConditionEvaluator
{
    public static function key(): string
    {
        return 'cart_subtotal';
    }

    public static function label(): string
    {
        return 'Subtotal do carrinho';
    }

    public function evaluate(ConditionDefinition $definition, CartContext $cart): bool
    {
        return $definition->operator->compare(
            $cart->subtotal()->cents,
            $definition->value,
        );
    }
}
