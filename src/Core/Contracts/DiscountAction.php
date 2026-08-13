<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Contracts;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/**
 * Ponto de extensao numero 2.
 *
 * A acao recebe a base ja resolvida pelo motor (original ou corrente) e
 * devolve QUANTO descontar. Nao aplica nada, nao muta o carrinho.
 */
interface DiscountAction
{
    public static function key(): string;

    public static function label(): string;

    public function calculate(ActionDefinition $definition, CartContext $cart, Money $base): Money;
}
