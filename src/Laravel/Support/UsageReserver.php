<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Support;

use Illuminate\Support\Facades\DB;
use SolutionsTI\DiscountEngine\Core\Result\DiscountResult;
use SolutionsTI\DiscountEngine\Laravel\Exceptions\CouponUnavailableException;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountCoupon;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountUsage;

/**
 * O ponto onde o limite de uso realmente e garantido.
 *
 * Chamar no fechamento do pedido, DENTRO da transacao do pedido.
 *
 * Por que lockForUpdate e nao "if (used < limit) { used++ }":
 * dois clientes usando o ultimo cupom no mesmo milissegundo leem 0 < 1 ao
 * mesmo tempo e gravam 1 os dois. O lock serializa a leitura-escrita; a
 * unique(order_id, rule_id) da tabela de usos e a segunda linha de defesa
 * contra dupla gravacao por retry.
 *
 * Por que a excecao interna em vez de `return false`:
 * retorno normal de dentro de DB::transaction() COMMITA. Com varias regras
 * aplicadas, a recusa na segunda deixaria a primeira gravada — consumindo
 * cupom de um pedido que nunca existiu. So excecao garante o rollback.
 */
final class UsageReserver
{
    /**
     * @return bool  false quando algum cupom esgotou entre a simulacao e o fechamento
     */
    public function reserve(
        DiscountResult $result,
        string $orderId,
        string|int|null $customerId = null,
    ): bool {
        if (! $result->hasDiscount()) {
            return true;
        }

        try {
            DB::transaction(function () use ($result, $orderId, $customerId): void {
                $this->persist($result, $orderId, $customerId);
            });
        } catch (CouponUnavailableException) {
            // A transacao ja foi revertida pelo Laravel. Nada foi gravado.
            return false;
        }

        return true;
    }

    private function persist(DiscountResult $result, string $orderId, string|int|null $customerId): void
    {
        $snapshot = $result->toArray();
        $registered = [];

        foreach ($result->applied as $applied) {
            // Uma regra pode ter varias acoes; registramos o uso uma vez so.
            if (in_array($applied->ruleId, $registered, true)) {
                continue;
            }

            $coupon = null;

            if ($applied->couponCode !== null) {
                $coupon = DiscountCoupon::query()
                    ->where('code', strtoupper(trim($applied->couponCode)))
                    ->lockForUpdate()
                    ->first();

                if ($coupon === null || ! $coupon->hasGlobalCapacity()) {
                    throw CouponUnavailableException::forCode($applied->couponCode);
                }

                $coupon->increment('used_count');
            }

            DiscountUsage::query()->create([
                'rule_id' => $applied->ruleId,
                'coupon_id' => $coupon?->id,
                'order_id' => $orderId,
                'customer_id' => $customerId === null ? null : (string) $customerId,
                'amount_cents' => $this->totalForRule($result, $applied->ruleId),
                'snapshot' => $snapshot,
            ]);

            $registered[] = $applied->ruleId;
        }
    }

    private function totalForRule(DiscountResult $result, string|int $ruleId): int
    {
        $total = 0;

        foreach ($result->applied as $applied) {
            if ($applied->ruleId === $ruleId) {
                $total += $applied->amount->cents;
            }
        }

        return $total;
    }
}
