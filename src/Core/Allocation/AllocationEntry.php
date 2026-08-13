<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Allocation;

use SolutionsTI\DiscountEngine\Core\Money\Money;

/** Desconto atribuido a um componente especifico de um item especifico. */
final class AllocationEntry
{
    public const SHIPPING = '__shipping__';

    public function __construct(
        public readonly string|int|null $itemId,
        public readonly int $componentIndex,
        public readonly string $componentType,
        public readonly Money $amount,
    ) {
    }

    public static function shipping(Money $amount): self
    {
        return new self(null, -1, self::SHIPPING, $amount);
    }

    public function isShipping(): bool
    {
        return $this->componentType === self::SHIPPING;
    }

    /** Chave estavel para agrupar/somar. */
    public function key(): string
    {
        return $this->itemId === null
            ? self::SHIPPING
            : $this->itemId . '::' . $this->componentIndex;
    }

    public function withAmount(Money $amount): self
    {
        return new self($this->itemId, $this->componentIndex, $this->componentType, $amount);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'component_index' => $this->componentIndex,
            'component_type' => $this->componentType,
            'amount_cents' => $this->amount->cents,
        ];
    }
}
