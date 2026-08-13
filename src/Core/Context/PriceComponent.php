<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Context;

use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * Uma parcela do preco de um item.
 *
 * Camisa estampada nao tem "um preco": tem o preco da peca mais o da
 * estamparia. Sem essa decomposicao nao ha como dar desconto so na estampa,
 * nem emitir nota fiscal com o desconto no lugar certo.
 *
 *   base   -> a peca crua
 *   print  -> cada estampa
 *
 * Os tipos sao strings livres: o sistema hospedeiro define o vocabulario.
 *
 * $quantity e por unidade do item pai. Uma camisa com 3 estampas tem
 * um componente 'print' com quantity 3; se o cliente leva 2 camisas
 * assim, o total e 6 estampas.
 */
final class PriceComponent
{
    /** @param  array<string,mixed>  $attributes */
    public function __construct(
        public readonly string $type,
        public readonly Money $unitPrice,
        public readonly int $quantity = 1,
        public readonly string|int|null $reference = null,
        public readonly array $attributes = [],
    ) {
    }

    /** Preco desta parcela para UMA unidade do item pai. */
    public function subtotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
