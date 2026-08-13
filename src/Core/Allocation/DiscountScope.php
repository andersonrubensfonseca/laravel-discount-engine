<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Allocation;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\PriceComponent;
use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * O recorte do carrinho sobre o qual uma acao pode agir.
 *
 * O motor monta o escopo antes de chamar a acao, ja aplicando:
 *   - o filtro de componentes da regra (ex.: so 'print')
 *   - o desconto ja concedido, quando a base de calculo e 'current'
 *
 * A acao so precisa decidir QUANTO, nunca ONDE procurar.
 */
final class DiscountScope
{
    /** @param  array<int,ScopedComponent>  $components */
    private function __construct(public readonly array $components = [])
    {
    }

    /** @param  array<int,ScopedComponent>  $components */
    public static function of(array $components): self
    {
        return new self(array_values(array_filter(
            $components,
            static fn (ScopedComponent $c): bool => $c->available->isPositive(),
        )));
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Monta o escopo a partir do carrinho.
     *
     * @param  array<int,string>  $componentTypes  vazio = todos
     * @param  array<string,Money>  $alreadyDiscounted  chave "itemId::indice"
     */
    public static function forCart(
        CartContext $cart,
        array $componentTypes = [],
        array $alreadyDiscounted = [],
    ): self {
        $scoped = [];

        foreach ($cart->items as $item) {
            /** @var CartItem $item */
            foreach ($item->components as $index => $component) {
                /** @var PriceComponent $component */
                if ($componentTypes !== [] && ! in_array($component->type, $componentTypes, true)) {
                    continue;
                }

                $gross = $component->unitPrice->multiply($item->quantity * $component->quantity);
                $used = $alreadyDiscounted[$item->id . '::' . $index] ?? Money::zero();

                $scoped[] = new ScopedComponent(
                    itemId: $item->id,
                    componentIndex: $index,
                    type: $component->type,
                    unitPrice: $component->unitPrice,
                    itemQuantity: $item->quantity,
                    componentQuantity: $component->quantity,
                    available: $gross->subtract($used)->atLeastZero(),
                );
            }
        }

        return self::of($scoped);
    }

    public function isEmpty(): bool
    {
        return $this->components === [];
    }

    /** Soma do que ainda pode ser descontado. */
    public function total(): Money
    {
        return Money::sum(array_map(
            static fn (ScopedComponent $c): Money => $c->available,
            $this->components,
        ));
    }

    /** Soma dos valores cheios, ignorando descontos ja concedidos. */
    public function gross(): Money
    {
        return Money::sum(array_map(
            static fn (ScopedComponent $c): Money => $c->gross(),
            $this->components,
        ));
    }

    public function totalUnits(): int
    {
        $units = 0;

        foreach ($this->components as $component) {
            $units += $component->units();
        }

        return $units;
    }

    /**
     * @param  callable(ScopedComponent):bool  $filter
     */
    public function filter(callable $filter): self
    {
        return self::of(array_values(array_filter($this->components, $filter)));
    }
}
