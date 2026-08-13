<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Enums;

/**
 * Sobre o que o desconto incide.
 *
 * Components e o alvo fino: combinado com meta['component_types'], permite
 * "10% so na estamparia" sem encostar no preco da peca.
 */
enum ActionTarget: string
{
    case Cart = 'cart';
    case Items = 'items';
    case Components = 'components';
    case Shipping = 'shipping';

    public function label(): string
    {
        return match ($this) {
            self::Cart => 'Total do carrinho',
            self::Items => 'Itens selecionados',
            self::Components => 'Componentes especificos',
            self::Shipping => 'Frete',
        };
    }

    public function isShipping(): bool
    {
        return $this === self::Shipping;
    }
}
