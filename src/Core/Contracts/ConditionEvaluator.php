<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Contracts;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition;

/**
 * Ponto de extensao numero 1.
 *
 * Nova condicao ("cliente de determinada UF", "carrinho tem item em pre-venda")
 * = criar uma classe, registrar a chave, cadastrar no painel. O motor nao muda.
 */
interface ConditionEvaluator
{
    /** Chave estavel, gravada no banco. Nunca renomear sem migration. */
    public static function key(): string;

    /** Rotulo exibido no construtor de regras do painel. */
    public static function label(): string;

    public function evaluate(ConditionDefinition $definition, CartContext $cart): bool;
}
