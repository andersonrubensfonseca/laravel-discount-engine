<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Repositories;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\UsageTracker;
use SolutionsTI\DiscountEngine\Core\Rule\Rule;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountCoupon;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountUsage;

/**
 * Consulta de saldo durante a SIMULACAO — leitura sem lock, de proposito.
 *
 * Travar linhas a cada recalculo de carrinho seria desastroso em concorrencia.
 * A garantia real do limite esta no UsageReserver, no fechamento do pedido.
 * Aqui o objetivo e so nao mostrar ao cliente um desconto obviamente esgotado.
 */
final class DatabaseUsageTracker implements UsageTracker
{
    public function hasRemainingUses(Rule $rule, CartContext $cart): bool
    {
        if ($rule->couponCode === null) {
            return true;
        }

        $coupon = DiscountCoupon::query()
            ->where('code', strtoupper(trim($rule->couponCode)))
            ->first();

        if ($coupon === null) {
            return false;
        }

        if (! $coupon->hasGlobalCapacity()) {
            return false;
        }

        return $this->hasCustomerCapacity($coupon, $cart);
    }

    private function hasCustomerCapacity(DiscountCoupon $coupon, CartContext $cart): bool
    {
        $limit = $coupon->usage_limit_per_customer;
        $customerId = $cart->customer?->id;

        if ($limit === null) {
            return true;
        }

        // Sem cliente identificado nao da para contar uso individual.
        // Negar e mais seguro do que liberar sem controle.
        if ($customerId === null) {
            return false;
        }

        $used = DiscountUsage::query()
            ->where('coupon_id', $coupon->id)
            ->where('customer_id', (string) $customerId)
            ->count();

        return $used < $limit;
    }
}
