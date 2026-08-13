<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Enums;

/**
 * Sobre qual valor o percentual incide.
 *
 * Original -> sempre o subtotal cheio do carrinho.  10% + 10% = 20%
 * Current  -> o subtotal ja reduzido pelas regras anteriores. 10% + 10% = 19%
 *
 * Deixar isso explicito por regra evita a classe de bug mais comum
 * em motores de desconto: ninguem sabe dizer por que o total deu 1 centavo diferente.
 */
enum CalculationBase: string
{
    case Original = 'original';
    case Current = 'current';

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Subtotal original',
            self::Current => 'Subtotal ja descontado',
        };
    }
}
