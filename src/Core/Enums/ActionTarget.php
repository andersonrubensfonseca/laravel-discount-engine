<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Enums;

/** Sobre o que o desconto incide. Frete e separado porque quase sempre tem regra fiscal propria. */
enum ActionTarget: string
{
    case Cart = 'cart';
    case Items = 'items';
    case Shipping = 'shipping';

    public function label(): string
    {
        return match ($this) {
            self::Cart => 'Total do carrinho',
            self::Items => 'Itens selecionados',
            self::Shipping => 'Frete',
        };
    }
}
