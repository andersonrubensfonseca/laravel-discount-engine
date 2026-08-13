<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Rule;

use SolutionsTI\DiscountEngine\Core\Enums\ActionTarget;
use SolutionsTI\DiscountEngine\Core\Money\Money;

/** O que a regra faz quando bate: "10% sobre os itens, teto de R$ 50". */
final class ActionDefinition
{
    /** @param  array<string,mixed>  $meta */
    public function __construct(
        public readonly string $type,
        public readonly float $value,
        public readonly ActionTarget $target = ActionTarget::Cart,
        public readonly ?Money $maxDiscount = null,
        public readonly array $meta = [],
    ) {
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
