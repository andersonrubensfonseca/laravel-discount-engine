<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Context;

/** Dados do cliente relevantes para elegibilidade. Opcional: carrinho de visitante nao tem. */
final class CustomerContext
{
    /**
     * @param  array<int,string|int>  $groups
     * @param  array<string,mixed>    $attributes
     */
    public function __construct(
        public readonly string|int|null $id = null,
        public readonly array $groups = [],
        public readonly int $completedOrders = 0,
        public readonly array $attributes = [],
    ) {
    }

    public function isFirstPurchase(): bool
    {
        return $this->completedOrders === 0;
    }

    public function isGuest(): bool
    {
        return $this->id === null;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
