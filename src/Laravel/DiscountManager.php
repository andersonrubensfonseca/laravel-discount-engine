<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\RuleRepository;
use SolutionsTI\DiscountEngine\Core\Engine\DiscountEngine;
use SolutionsTI\DiscountEngine\Core\Result\DiscountResult;
use SolutionsTI\DiscountEngine\Core\Rule\Rule;
use SolutionsTI\DiscountEngine\Laravel\Support\CouponValidation;
use SolutionsTI\DiscountEngine\Laravel\Support\UsageReserver;

/**
 * A porta de entrada da aplicacao. E isto que os controllers usam.
 */
final class DiscountManager
{
    public function __construct(
        private readonly DiscountEngine $engine,
        private readonly RuleRepository $rules,
        private readonly UsageReserver $reserver,
    ) {
    }

    /** Calcula os descontos do carrinho. Chamar a cada mutacao. */
    public function calculate(CartContext $cart): DiscountResult
    {
        return $this->engine->evaluate($cart);
    }

    /**
     * Valida um codigo digitado pelo cliente.
     *
     * Devolve um motivo especifico em vez de um booleano: "cupom expirado" e
     * "seu carrinho nao atinge o minimo" sao mensagens muito diferentes para
     * quem esta tentando comprar.
     */
    public function validateCoupon(string $code, CartContext $cart): CouponValidation
    {
        $code = trim($code);

        if ($code === '') {
            return CouponValidation::invalid('Informe um codigo de cupom.');
        }

        // Primeiro: o codigo existe, esta ativo e dentro da validade?
        // Se nem regra candidata sai daqui, o problema e o codigo em si.
        $candidates = $this->rules->rulesForCoupons([$code]);

        if ($candidates === []) {
            return CouponValidation::invalid('Cupom invalido ou expirado.');
        }

        $candidateIds = array_map(static fn (Rule $rule): string|int => $rule->id, $candidates);

        $result = $this->engine->evaluate($this->withCoupon($cart, $code));

        foreach ($result->applied as $applied) {
            if ($applied->couponCode !== null && strcasecmp($applied->couponCode, $code) === 0) {
                return CouponValidation::accepted($applied->amount, $result);
            }
        }

        // O cupom existe mas nao entrou. O motivo tem que ser o da regra DELE,
        // nao o da primeira regra rejeitada da lista — que pode ser automatica
        // e nao ter relacao nenhuma com o que o cliente digitou.
        foreach ($result->rejected as $rejected) {
            if (in_array($rejected->ruleId, $candidateIds, true)) {
                return CouponValidation::rejected($rejected->reason, $result);
            }
        }

        return CouponValidation::invalid('Cupom nao aplicavel a este carrinho.');
    }

    /**
     * Confirma o consumo dos cupons no fechamento do pedido.
     * Chamar dentro da transacao que grava o pedido.
     */
    public function reserve(DiscountResult $result, string $orderId, string|int|null $customerId = null): bool
    {
        return $this->reserver->reserve($result, $orderId, $customerId);
    }

    private function withCoupon(CartContext $cart, string $code): CartContext
    {
        $codes = $cart->couponCodes;

        if (! $cart->hasCoupon($code)) {
            $codes[] = $code;
        }

        return new CartContext(
            items: $cart->items,
            shippingCost: $cart->shippingCost,
            customer: $cart->customer,
            couponCodes: array_values($codes),
            currency: $cart->currency,
            attributes: $cart->attributes,
        );
    }
}
