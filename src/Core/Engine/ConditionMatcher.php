<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Engine;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Enums\LogicOperator;
use SolutionsTI\DiscountEngine\Core\Registry\ConditionRegistry;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionGroup;

/**
 * Percorre a arvore de condicoes recursivamente.
 *
 * Decisao deliberada: grupo VAZIO retorna true. Uma regra sem condicoes
 * e uma regra que vale sempre — util para cupom simples, onde a unica
 * condicao e o proprio codigo ter sido digitado.
 */
final class ConditionMatcher
{
    public function __construct(private readonly ConditionRegistry $registry)
    {
    }

    public function matches(ConditionGroup $group, CartContext $cart): bool
    {
        if ($group->isEmpty()) {
            return true;
        }

        $requiresAll = $group->logic === LogicOperator::All;

        foreach ($group->children as $child) {
            $result = $child instanceof ConditionGroup
                ? $this->matches($child, $cart)
                : $this->evaluate($child, $cart);

            if ($requiresAll && ! $result) {
                return false;
            }

            if (! $requiresAll && $result) {
                return true;
            }
        }

        return $requiresAll;
    }

    private function evaluate(ConditionDefinition $definition, CartContext $cart): bool
    {
        return $this->registry->get($definition->type)->evaluate($definition, $cart);
    }
}
