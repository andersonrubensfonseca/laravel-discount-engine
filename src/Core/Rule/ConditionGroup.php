<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Rule;

use SolutionsTI\DiscountEngine\Core\Enums\LogicOperator;

/**
 * Arvore de condicoes. Um grupo pode conter condicoes e outros grupos,
 * o que permite ao painel montar "(A e B) ou C" sem SQL dinamico.
 */
final class ConditionGroup
{
    /** @param  array<int,ConditionDefinition|ConditionGroup>  $children */
    public function __construct(
        public readonly LogicOperator $logic = LogicOperator::All,
        public readonly array $children = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }
}
