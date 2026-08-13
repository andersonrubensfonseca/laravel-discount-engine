<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Conditions;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Contracts\ConditionEvaluator;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition;

/**
 * "Pelo menos 2 unidades da categoria Camisetas".
 *
 * A categoria alvo vai em meta['category_id'] e o operador/valor
 * comparam a quantidade encontrada.
 */
final class CategoryQuantityCondition implements ConditionEvaluator
{
    public static function key(): string
    {
        return 'category_quantity';
    }

    public static function label(): string
    {
        return 'Quantidade em uma categoria';
    }

    public function evaluate(ConditionDefinition $definition, CartContext $cart): bool
    {
        $categoryId = $definition->meta('category_id');

        if ($categoryId === null) {
            return false;
        }

        $matching = $cart->itemsMatching(
            static fn (CartItem $item): bool => $item->hasCategory($categoryId),
        );

        $quantity = array_sum(array_map(
            static fn (CartItem $item): int => $item->quantity,
            $matching,
        ));

        return $definition->operator->compare($quantity, $definition->value);
    }
}
