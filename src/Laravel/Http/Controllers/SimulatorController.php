<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use SolutionsTI\DiscountEngine\Core\Result\AppliedDiscount;
use SolutionsTI\DiscountEngine\Core\Result\RejectedRule;
use SolutionsTI\DiscountEngine\Laravel\DiscountManager;
use SolutionsTI\DiscountEngine\Laravel\Support\CartContextFactory;
use Throwable;

/**
 * O simulador.
 *
 * Monta um carrinho de mentira, roda o motor de verdade e mostra o que
 * bateu — e, principalmente, o que NAO bateu e por que.
 *
 * A lista de rejeicoes e a razao de existir desta tela: sem ela, o time
 * comercial cadastra uma regra, o desconto nao aparece no site e nao ha
 * como descobrir se foi a condicao, a vigencia, o cupom ou um grupo.
 */
final class SimulatorController extends Controller
{
    public function index(): View
    {
        return view('discount-engine::simulator.index', [
            'insecure' => $this->panelLooksUnprotected(),
        ]);
    }

    public function run(Request $request, DiscountManager $manager, CartContextFactory $factory): JsonResponse
    {
        try {
            $cart = $factory->fromArray($request->all());
            $result = $manager->calculate($cart);
        } catch (Throwable $e) {
            // Carrinho invalido e erro do usuario do painel, nao do servidor.
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'subtotal_cents' => $result->subtotal->cents,
            'shipping_cents' => $result->shippingCost->cents,
            'items_discount_cents' => $result->itemsDiscount()->cents,
            'shipping_discount_cents' => $result->shippingDiscount()->cents,
            'total_discount_cents' => $result->totalDiscount()->cents,
            'final_total_cents' => $result->finalTotal()->cents,
            'applied' => array_map(
                static fn (AppliedDiscount $d): array => [
                    'rule_name' => $d->ruleName,
                    'action_type' => $d->actionType,
                    'target' => $d->target->label(),
                    'coupon_code' => $d->couponCode,
                    'amount_cents' => $d->amount->cents,
                ],
                $result->applied,
            ),
            'rejected' => array_map(
                static fn (RejectedRule $r): array => [
                    'rule_name' => $r->ruleName,
                    'reason' => $r->reason->value,
                    'message' => $r->message(),
                ],
                $result->rejected,
            ),
            'by_item' => array_map(
                static fn ($money): int => $money->cents,
                $result->itemAllocations(),
            ),
            'by_component_type' => array_map(
                static fn ($money): int => $money->cents,
                $result->discountByComponentType(),
            ),
        ]);
    }

    private function panelLooksUnprotected(): bool
    {
        $middleware = (array) config('discount-engine.panel.middleware', ['web']);

        return array_values(array_diff($middleware, ['web'])) === [];
    }
}
