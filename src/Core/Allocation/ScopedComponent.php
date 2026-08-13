<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Allocation;

use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * Um componente de um item, visto pelo motor no momento do calculo.
 *
 * Guarda itemQuantity e componentQuantity separados de proposito: regras
 * como "a primeira estampa de CADA camisa sai a 1,99" precisam saber
 * quantas camisas existem e quantas estampas cada uma tem — o produto
 * das duas perderia essa informacao.
 */
final class ScopedComponent
{
    public function __construct(
        public readonly string|int|null $itemId,
        public readonly int $componentIndex,
        public readonly string $type,
        public readonly Money $unitPrice,
        public readonly int $itemQuantity,
        public readonly int $componentQuantity,
        public readonly Money $available,
    ) {
    }

    public static function forShipping(Money $available, Money $gross): self
    {
        return new self(
            itemId: null,
            componentIndex: -1,
            type: AllocationEntry::SHIPPING,
            unitPrice: $gross,
            itemQuantity: 1,
            componentQuantity: 1,
            available: $available,
        );
    }

    /** Total de unidades deste componente no carrinho. */
    public function units(): int
    {
        return $this->itemQuantity * $this->componentQuantity;
    }

    /** Valor cheio, antes de qualquer desconto. */
    public function gross(): Money
    {
        return $this->unitPrice->multiply($this->units());
    }

    public function entry(Money $amount): AllocationEntry
    {
        return new AllocationEntry(
            $this->itemId,
            $this->componentIndex,
            $this->type,
            $amount->clampTo($this->available),
        );
    }
}
