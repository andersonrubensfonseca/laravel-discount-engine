<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Context;

use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * Fotografia imutavel do carrinho no instante da avaliacao.
 *
 * O motor recebe isso e devolve um DiscountResult. Nao le banco,
 * nao chama HTTP, nao toca em sessao. Entrada -> saida, testavel.
 */
final class CartContext
{
    /**
     * @param  array<int,CartItem>  $items
     * @param  array<int,string>    $couponCodes  codigos digitados pelo cliente
     * @param  array<string,mixed>  $attributes   canal, UF de entrega, cupom de campanha...
     */
    public function __construct(
        public readonly array $items,
        public readonly Money $shippingCost,
        public readonly ?CustomerContext $customer = null,
        public readonly array $couponCodes = [],
        public readonly string $currency = 'BRL',
        public readonly array $attributes = [],
    ) {
    }

    /** Soma dos itens, sem frete. */
    public function subtotal(): Money
    {
        return Money::sum(array_map(
            static fn (CartItem $item): Money => $item->subtotal(),
            $this->items,
        ));
    }

    /** Subtotal + frete. */
    public function total(): Money
    {
        return $this->subtotal()->add($this->shippingCost);
    }

    /** Quantidade total de unidades (nao de linhas). */
    public function totalQuantity(): int
    {
        return array_sum(array_map(
            static fn (CartItem $item): int => $item->quantity,
            $this->items,
        ));
    }

    public function lineCount(): int
    {
        return count($this->items);
    }

    /**
     * @param  callable(CartItem):bool  $filter
     * @return array<int,CartItem>
     */
    public function itemsMatching(callable $filter): array
    {
        return array_values(array_filter($this->items, $filter));
    }

    public function hasCoupon(string $code): bool
    {
        foreach ($this->couponCodes as $provided) {
            if (strcasecmp(trim($provided), trim($code)) === 0) {
                return true;
            }
        }

        return false;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
