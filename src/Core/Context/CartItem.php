<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Context;

use InvalidArgumentException;
use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * Um item do carrinho, ja normalizado.
 *
 * O motor NAO conhece o Eloquent. Quem monta esse DTO e a camada de
 * integracao (Laravel, API, teste).
 *
 * O preco pode vir de duas formas:
 *
 *   1. unitPrice direto — item simples, sem composicao
 *   2. components[] — item composto (camisa + estampas)
 *
 * Nos dois casos o motor enxerga uma lista de componentes: quando so ha
 * unitPrice, ele vira um componente 'base'. Isso mantem o codigo antigo
 * funcionando sem alteracao.
 */
final class CartItem
{
    public readonly Money $unitPrice;

    /** @var array<int,PriceComponent> */
    public readonly array $components;

    /**
     * @param  array<int,string|int>     $categoryIds
     * @param  array<string,mixed>       $attributes
     * @param  array<int,PriceComponent> $components
     */
    public function __construct(
        public readonly string|int $id,
        public readonly string $sku,
        public readonly int $quantity,
        ?Money $unitPrice = null,
        public readonly array $categoryIds = [],
        public readonly array $attributes = [],
        array $components = [],
    ) {
        if ($components === []) {
            if ($unitPrice === null) {
                throw new InvalidArgumentException(
                    "Item [{$sku}]: informe unitPrice ou components.",
                );
            }

            $this->unitPrice = $unitPrice;
            $this->components = [new PriceComponent('base', $unitPrice)];

            return;
        }

        $derived = Money::sum(array_map(
            static fn (PriceComponent $c): Money => $c->subtotal(),
            $components,
        ));

        // Divergencia aqui e bug de integracao, nao caso de negocio:
        // melhor estourar na hora do que fechar pedido com valor errado.
        if ($unitPrice !== null && ! $unitPrice->equals($derived)) {
            throw new InvalidArgumentException(sprintf(
                'Item [%s]: unitPrice (%d) nao bate com a soma dos componentes (%d).',
                $sku,
                $unitPrice->cents,
                $derived->cents,
            ));
        }

        $this->unitPrice = $derived;
        $this->components = array_values($components);
    }

    /** Preco unitario x quantidade. */
    public function subtotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    public function isComposed(): bool
    {
        return count($this->components) > 1
            || ($this->components[0]->type ?? 'base') !== 'base';
    }

    public function hasCategory(string|int $categoryId): bool
    {
        return in_array($categoryId, $this->categoryIds, false);
    }

    /** @param  array<int,string>  $types */
    public function hasComponentOfType(array $types): bool
    {
        foreach ($this->components as $component) {
            if (in_array($component->type, $types, true)) {
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
