<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Enums;

/** Operadores disponiveis para o time comercial montar condicoes no painel. */
enum Operator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case In = 'in';
    case NotIn = 'not_in';
    case ContainsAny = 'contains_any';
    case ContainsNone = 'contains_none';

    /**
     * @param  mixed  $left   valor extraido do carrinho
     * @param  mixed  $right  valor cadastrado na regra
     */
    public function compare(mixed $left, mixed $right): bool
    {
        return match ($this) {
            self::Equals => $left == $right,
            self::NotEquals => $left != $right,
            self::GreaterThan => $left > $right,
            self::GreaterThanOrEqual => $left >= $right,
            self::LessThan => $left < $right,
            self::LessThanOrEqual => $left <= $right,
            self::In => in_array($left, (array) $right, false),
            self::NotIn => ! in_array($left, (array) $right, false),
            self::ContainsAny => array_intersect((array) $left, (array) $right) !== [],
            self::ContainsNone => array_intersect((array) $left, (array) $right) === [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'igual a',
            self::NotEquals => 'diferente de',
            self::GreaterThan => 'maior que',
            self::GreaterThanOrEqual => 'maior ou igual a',
            self::LessThan => 'menor que',
            self::LessThanOrEqual => 'menor ou igual a',
            self::In => 'esta entre',
            self::NotIn => 'nao esta entre',
            self::ContainsAny => 'contem algum de',
            self::ContainsNone => 'nao contem nenhum de',
        };
    }
}
