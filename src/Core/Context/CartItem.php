<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Context;

use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * Um item do carrinho, ja normalizado.
 *
 * O motor NAO conhece o Eloquent. Quem monta esse DTO e a camada de
 * integracao (Laravel, API, teste). Isso e o que permite testar o motor
 * inteiro sem banco e migrar de Laravel 8 para 13 sem tocar no Core.
 */
final class CartItem
{
    /**
     * @param  array<int,string|int>  $categoryIds
     * @param  array<string,mixed>    $attributes  espaco livre: marca, peso, flag de brinde...
     */
    public function __construct(
        public readonly string|int $id,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly Money $unitPrice,
        public readonly array $categoryIds = [],
        public readonly array $attributes = [],
    ) {
    }

    /** Preco unitario x quantidade. */
    public function subtotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    public function hasCategory(string|int $categoryId): bool
    {
        return in_array($categoryId, $this->categoryIds, false);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
