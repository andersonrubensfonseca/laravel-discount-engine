<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Contracts;

use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/**
 * Ponto de extensao numero 2.
 *
 * A acao recebe o escopo ja recortado pelo motor (componentes elegiveis,
 * com o saldo disponivel de cada um) e devolve a alocacao: quanto sai de
 * cada componente.
 *
 * Mudou na v0.3: antes devolvia um Money unico e o motor rateava
 * proporcionalmente. Isso e exato para percentual e errado para qualquer
 * regra que atue em unidades especificas — "leve 3 pague 2", desconto so
 * na estampa, primeira estampa a 1,99.
 */
interface DiscountAction
{
    public static function key(): string;

    public static function label(): string;

    public function calculate(
        ActionDefinition $definition,
        CartContext $cart,
        DiscountScope $scope,
    ): DiscountAllocation;
}
