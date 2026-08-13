<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Money;

use InvalidArgumentException;

/**
 * Valor monetario imutavel, guardado em centavos (int).
 *
 * Regra da casa: dinheiro nunca trafega como float dentro do motor.
 * 0.1 + 0.2 !== 0.3 em ponto flutuante, e num carrinho com 40 itens
 * isso vira divergencia de centavos entre a soma dos itens e o total do pedido.
 */
final class Money
{
    private function __construct(public readonly int $cents)
    {
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** Converte reais para centavos com arredondamento half-up. */
    public static function fromFloat(float $amount): self
    {
        return new self((int) round($amount * 100));
    }

    /** @param  iterable<self>  $values */
    public static function sum(iterable $values): self
    {
        $total = 0;

        foreach ($values as $value) {
            $total += $value->cents;
        }

        return new self($total);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function multiply(int $factor): self
    {
        return new self($this->cents * $factor);
    }

    /** Percentual sobre o valor, arredondado half-up. */
    public function percentage(float $percent): self
    {
        if ($percent < 0) {
            throw new InvalidArgumentException('Percentual nao pode ser negativo.');
        }

        return new self((int) round($this->cents * $percent / 100));
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function min(self $other): self
    {
        return $this->cents <= $other->cents ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->cents >= $other->cents ? $this : $other;
    }

    /** Impede que um desconto ultrapasse o valor sobre o qual incide. */
    public function clampTo(self $ceiling): self
    {
        return $this->min($ceiling);
    }

    public function atLeastZero(): self
    {
        return $this->cents < 0 ? self::zero() : $this;
    }

    /**
     * Rateia o valor proporcionalmente aos pesos SEM perder nem inventar centavos.
     *
     * Usa o metodo do maior resto: distribui o piso de cada fatia e depois
     * entrega os centavos sobrando a quem tinha a maior parte fracionaria.
     * Garantia: array_sum(allocate(...)) === $this->cents, sempre.
     *
     * @param  array<array-key,int>  $weights
     * @return array<array-key,self>
     */
    public function allocate(array $weights): array
    {
        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0) {
            throw new InvalidArgumentException('A soma dos pesos precisa ser positiva.');
        }

        $shares = [];
        $remainders = [];
        $distributed = 0;

        foreach ($weights as $key => $weight) {
            $exact = $this->cents * $weight / $totalWeight;
            $floor = (int) floor($exact);

            $shares[$key] = $floor;
            $remainders[$key] = $exact - $floor;
            $distributed += $floor;
        }

        $leftover = $this->cents - $distributed;
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($leftover <= 0) {
                break;
            }

            $shares[$key]++;
            $leftover--;
        }

        return array_map(static fn (int $cents): self => new self($cents), $shares);
    }

    public function toFloat(): float
    {
        return $this->cents / 100;
    }

    public function format(string $currency = 'R$'): string
    {
        return $currency . ' ' . number_format($this->toFloat(), 2, ',', '.');
    }
}
