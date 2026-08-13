<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Enums;

/** Como as condicoes de um grupo se combinam. */
enum LogicOperator: string
{
    case All = 'and';
    case Any = 'or';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Todas as condicoes',
            self::Any => 'Qualquer condicao',
        };
    }
}
