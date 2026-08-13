<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Enums;

/**
 * Stackable  -> convive com outras regras ja aplicadas.
 * Exclusive  -> se aplicar, nenhuma outra regra entra depois dela.
 */
enum CombinationMode: string
{
    case Stackable = 'stackable';
    case Exclusive = 'exclusive';

    public function label(): string
    {
        return match ($this) {
            self::Stackable => 'Acumulavel',
            self::Exclusive => 'Exclusivo',
        };
    }
}
