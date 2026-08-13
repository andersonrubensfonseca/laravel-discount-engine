<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Contracts;

use SolutionsTI\DiscountEngine\Core\Rule\Rule;

/**
 * O motor so conhece esta interface. Na camada Laravel isso vira
 * um repositorio Eloquent com cache; nos testes, um array em memoria.
 */
interface RuleRepository
{
    /** @return array<int,Rule> regras automaticas ativas */
    public function automaticRules(): array;

    /**
     * @param  array<int,string>  $codes
     * @return array<int,Rule>    regras de cupom cujos codigos foram informados
     */
    public function rulesForCoupons(array $codes): array;
}
