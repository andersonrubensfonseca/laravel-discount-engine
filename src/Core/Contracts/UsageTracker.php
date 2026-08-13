<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Contracts;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Rule\Rule;

/**
 * Consulta de limite de uso durante a SIMULACAO.
 *
 * Atencao: isto responde "ainda ha saldo?", nao reserva nada.
 * A reserva definitiva acontece no fechamento do pedido, dentro de
 * transacao com lock — checar aqui e confiar seria race condition classica
 * (dois clientes usando o ultimo cupom no mesmo milissegundo).
 */
interface UsageTracker
{
    public function hasRemainingUses(Rule $rule, CartContext $cart): bool;
}
