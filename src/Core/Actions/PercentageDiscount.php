<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Actions;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/** Percentual sobre a base, com teto opcional em valor. */
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

    public function calculate(ActionDefinition $definition, CartContext $cart, Money $base): Money
    {
        $percent = min(max($definition->value, 0.0), 100.0);
        $discount = $base->percentage($percent);

        if ($definition->maxDiscount !== null) {
            $discount = $discount->clampTo($definition->maxDiscount);
        }

        return $discount->clampTo($base);
    }
}
