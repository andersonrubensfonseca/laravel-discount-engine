<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Conditions;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\ConditionEvaluator;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition;

/** "Cliente pertence ao grupo Atacado" — use ContainsAny / ContainsNone. */
final class CustomerGroupCondition implements ConditionEvaluator
{
    public static function key(): string
    {
        return 'customer_group';
    }

    public static function label(): string
    {
        return 'Grupo do cliente';
    }

    public function evaluate(ConditionDefinition $definition, CartContext $cart): bool
    {
        $groups = $cart->customer?->groups ?? [];

        return $definition->operator->compare($groups, $definition->value);
    }
}
