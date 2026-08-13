<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Rule;

use SolutionsTI\DiscountEngine\Core\Enums\Operator;

/**
 * Uma condicao cadastrada: "subtotal do carrinho >= 20000 centavos".
 * O $type e a chave registrada no ConditionRegistry.
 */
final class ConditionDefinition
{
    /** @param  array<string,mixed>  $meta */
    public function __construct(
        public readonly string $type,
        public readonly Operator $operator,
        public readonly mixed $value,
        public readonly array $meta = [],
    ) {
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
