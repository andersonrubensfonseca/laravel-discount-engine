<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Result;

use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * Saida do motor. Imutavel e serializavel — e exatamente isso que a
 * camada Laravel grava como snapshot no pedido.
 *
 * Nunca recalcule o desconto de um pedido antigo a partir da regra atual:
 * editar uma regra reescreveria o historico financeiro.
 *
 * Desde a v0.3 o rateio por item e EXATO, nao proporcional: vem da
 * alocacao que cada acao produziu. E o que permite emitir nota fiscal com
 * desconto discriminado por item sem divergencia.
 */
final class DiscountResult
{
    /**
     * @param  array<int,AppliedDiscount>  $applied
     * @param  array<int,RejectedRule>     $rejected
     */
    public function __construct(
        public readonly Money $subtotal,
        public readonly Money $shippingCost,
        public readonly DiscountAllocation $allocation,
        public readonly array $applied = [],
        public readonly array $rejected = [],
    ) {
    }

    public function itemsDiscount(): Money
    {
        return $this->allocation->itemsTotal();
    }

    public function shippingDiscount(): Money
    {
        return $this->allocation->shippingTotal();
    }

    public function totalDiscount(): Money
    {
        return $this->allocation->total();
    }

    public function finalSubtotal(): Money
    {
        return $this->subtotal->subtract($this->itemsDiscount());
    }

    public function finalShipping(): Money
    {
        return $this->shippingCost->subtract($this->shippingDiscount());
    }

    public function finalTotal(): Money
    {
        return $this->finalSubtotal()->add($this->finalShipping());
    }

    public function hasDiscount(): bool
    {
        return $this->totalDiscount()->isPositive();
    }

    /** @return array<array-key,Money> itemId => desconto exato */
    public function itemAllocations(): array
    {
        return $this->allocation->byItem();
    }

    /** @return array<string,Money> "itemId::indice" => desconto exato */
    public function componentAllocations(): array
    {
        return $this->allocation->byComponent();
    }

    /** @return array<string,Money> ex.: ['base' => .., 'print' => ..] */
    public function discountByComponentType(): array
    {
        return $this->allocation->byComponentType();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'subtotal_cents' => $this->subtotal->cents,
            'shipping_cents' => $this->shippingCost->cents,
            'items_discount_cents' => $this->itemsDiscount()->cents,
            'shipping_discount_cents' => $this->shippingDiscount()->cents,
            'total_discount_cents' => $this->totalDiscount()->cents,
            'final_total_cents' => $this->finalTotal()->cents,
            'applied' => array_map(static fn (AppliedDiscount $d): array => $d->toArray(), $this->applied),
            'rejected' => array_map(static fn (RejectedRule $r): array => $r->toArray(), $this->rejected),
            'allocation' => $this->allocation->toArray(),
            'item_allocations' => array_map(
                static fn (Money $m): int => $m->cents,
                $this->itemAllocations(),
            ),
        ];
    }
}
