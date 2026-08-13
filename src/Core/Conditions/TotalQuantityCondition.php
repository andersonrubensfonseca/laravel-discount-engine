<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Conditions;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\ConditionEvaluator;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition;

/** "Carrinho com 3 ou mais unidades" — conta unidades, nao linhas. */
final class TotalQuantityCondition implements ConditionEvaluator
{
    public static function key(): string
    {
        return 'total_quantity';
    }

    public static function label(): string
    {
        return 'Quantidade total de itens';
    }

    public function evaluate(ConditionDefinition $definition, CartContext $cart): bool
    {
        return $definition->operator->compare(
            $cart->totalQuantity(),
            $definition->value,
        );
    }
}
