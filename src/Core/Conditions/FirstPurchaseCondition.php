<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Conditions;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\ConditionEvaluator;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition;

/**
 * "Primeira compra do cliente".
 *
 * Visitante sem cadastro retorna false de proposito: nao da para
 * garantir que e a primeira compra de alguem que nao identificamos.
 */
final class FirstPurchaseCondition implements ConditionEvaluator
{
    public static function key(): string
    {
        return 'first_purchase';
    }

    public static function label(): string
    {
        return 'Primeira compra do cliente';
    }

    public function evaluate(ConditionDefinition $definition, CartContext $cart): bool
    {
        $customer = $cart->customer;

        if ($customer === null || $customer->isGuest()) {
            return false;
        }

        return $definition->operator->compare(
            $customer->isFirstPurchase(),
            (bool) $definition->value,
        );
    }
}
