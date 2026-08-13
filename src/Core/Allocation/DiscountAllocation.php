<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Allocation;

use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * O retorno de toda acao de desconto.
 *
 * Substitui o `Money` unico que existia antes. A diferenca importa: com um
 * valor solto, o motor precisava ratear proporcionalmente entre os itens —
 * o que e exato para percentual e ERRADO para "leve 3 pague 2" ou desconto
 * so na estampa. Agora cada acao diz exatamente de onde tirou o dinheiro.
 *
 * Consequencia pratica: nota fiscal com desconto por item passa a bater.
 */
final class DiscountAllocation
{
    /** @param  array<int,AllocationEntry>  $entries */
    private function __construct(public readonly array $entries = [])
    {
    }

    public static function empty(): self
    {
        return new self();
    }

    /** @param  array<int,AllocationEntry>  $entries */
    public static function of(array $entries): self
    {
        return new self(array_values(array_filter(
            $entries,
            static fn (AllocationEntry $e): bool => $e->amount->isPositive(),
        )));
    }

    /**
     * Distribui um valor entre os componentes do escopo, proporcionalmente
     * ao que cada um ainda tem disponivel.
     *
     * Usa Money::allocate (maior resto), entao a soma das fatias e sempre
     * exatamente igual ao valor distribuido — sem centavo perdido.
     */
    public static function spread(DiscountScope $scope, Money $amount): self
    {
        if ($amount->isZero() || $scope->isEmpty()) {
            return self::empty();
        }

        $amount = $amount->clampTo($scope->total());
        $weights = [];

        foreach ($scope->components as $index => $component) {
            $weights[$index] = $component->available->cents;
        }

        if (array_sum($weights) <= 0) {
            return self::empty();
        }

        $shares = $amount->allocate($weights);
        $entries = [];

        foreach ($scope->components as $index => $component) {
            $entries[] = $component->entry($shares[$index]);
        }

        return self::of($entries);
    }

    public function with(AllocationEntry $entry): self
    {
        return new self([...$this->entries, $entry]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->entries, ...$other->entries]);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function total(): Money
    {
        return Money::sum(array_map(
            static fn (AllocationEntry $e): Money => $e->amount,
            $this->entries,
        ));
    }

    public function itemsTotal(): Money
    {
        return $this->filtered(false)->total();
    }

    public function shippingTotal(): Money
    {
        return $this->filtered(true)->total();
    }

    /**
     * Reduz o total ate o teto, mantendo a proporcao entre as entradas.
     * Usado pelo teto global e pelos limites por acao.
     */
    public function clampTo(Money $ceiling): self
    {
        $total = $this->total();

        if (! $total->greaterThan($ceiling) || $total->isZero()) {
            return $this;
        }

        $weights = [];

        foreach ($this->entries as $index => $entry) {
            $weights[$index] = $entry->amount->cents;
        }

        $shares = $ceiling->allocate($weights);
        $entries = [];

        foreach ($this->entries as $index => $entry) {
            $entries[] = $entry->withAmount($shares[$index]);
        }

        return self::of($entries);
    }

    /** @return array<array-key,Money> itemId => total descontado */
    public function byItem(): array
    {
        $totals = [];

        foreach ($this->entries as $entry) {
            if ($entry->isShipping() || $entry->itemId === null) {
                continue;
            }

            $current = $totals[$entry->itemId] ?? Money::zero();
            $totals[$entry->itemId] = $current->add($entry->amount);
        }

        return $totals;
    }

    /** @return array<string,Money> "itemId::indice" => total descontado */
    public function byComponent(): array
    {
        $totals = [];

        foreach ($this->entries as $entry) {
            $current = $totals[$entry->key()] ?? Money::zero();
            $totals[$entry->key()] = $current->add($entry->amount);
        }

        return $totals;
    }

    /** @return array<string,Money> tipo do componente => total descontado */
    public function byComponentType(): array
    {
        $totals = [];

        foreach ($this->entries as $entry) {
            $current = $totals[$entry->componentType] ?? Money::zero();
            $totals[$entry->componentType] = $current->add($entry->amount);
        }

        return $totals;
    }

    /** @return array<int,array<string,mixed>> */
    public function toArray(): array
    {
        return array_map(
            static fn (AllocationEntry $e): array => $e->toArray(),
            $this->entries,
        );
    }

    private function filtered(bool $shipping): self
    {
        return new self(array_values(array_filter(
            $this->entries,
            static fn (AllocationEntry $e): bool => $e->isShipping() === $shipping,
        )));
    }
}
