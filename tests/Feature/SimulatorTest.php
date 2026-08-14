<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountCoupon;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Tests\TestCase;

final class SimulatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_tela_abre(): void
    {
        $this->get(route('discount-engine.simulator.index'))->assertOk();
    }

    public function test_simula_carrinho_composto(): void
    {
        DiscountRule::create([
            'name' => '20% na estamparia',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [[
                'type' => 'percentage',
                'value' => 20,
                'target' => 'components',
                'meta' => ['component_types' => ['print']],
            ]],
        ]);

        $resposta = $this->postJson(route('discount-engine.simulator.run'), [
            'shipping_cents' => 0,
            'items' => [[
                'id' => 'CAMISA-1',
                'sku' => 'CAMISA',
                'quantity' => 1,
                'components' => [
                    ['type' => 'base', 'unit_price_cents' => 4000, 'quantity' => 1],
                    ['type' => 'print', 'unit_price_cents' => 1500, 'quantity' => 2],
                ],
            ]],
        ]);

        $resposta->assertOk()
            ->assertJsonPath('items_discount_cents', 600)
            ->assertJsonPath('by_component_type.print', 600)
            ->assertJsonPath('applied.0.rule_name', '20% na estamparia');
    }

    /**
     * A razao de existir do simulador: mostrar POR QUE uma regra nao entrou.
     */
    public function test_regra_que_nao_bate_aparece_com_o_motivo(): void
    {
        DiscountRule::create([
            'name' => '10% acima de R$ 500',
            'trigger' => 'automatic',
            'conditions' => [
                'logic' => 'and',
                'children' => [['type' => 'cart_subtotal', 'operator' => 'gte', 'value' => 50000]],
            ],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        $this->postJson(route('discount-engine.simulator.run'), [
            'items' => [['sku' => 'X', 'quantity' => 1, 'unit_price_cents' => 10000]],
        ])
            ->assertOk()
            ->assertJsonPath('total_discount_cents', 0)
            ->assertJsonPath('rejected.0.reason', 'conditions_not_met');
    }

    public function test_simula_cupom_informado(): void
    {
        $regra = DiscountRule::create([
            'name' => 'Cupom teste',
            'trigger' => 'coupon',
            'conditions' => [],
            'actions' => [['type' => 'fixed_amount', 'value' => 2500, 'target' => 'cart']],
        ]);

        DiscountCoupon::create(['rule_id' => $regra->id, 'code' => 'TESTE10']);

        $this->postJson(route('discount-engine.simulator.run'), [
            'coupons' => ['teste10'],
            'items' => [['sku' => 'X', 'quantity' => 1, 'unit_price_cents' => 10000]],
        ])
            ->assertOk()
            ->assertJsonPath('items_discount_cents', 2500)
            ->assertJsonPath('applied.0.coupon_code', 'TESTE10');
    }

    public function test_cliente_identificado_habilita_condicao_de_primeira_compra(): void
    {
        DiscountRule::create([
            'name' => 'Primeira compra',
            'trigger' => 'automatic',
            'conditions' => [
                'logic' => 'and',
                'children' => [['type' => 'first_purchase', 'operator' => 'eq', 'value' => true]],
            ],
            'actions' => [['type' => 'percentage', 'value' => 15, 'target' => 'cart']],
        ]);

        $carrinho = ['items' => [['sku' => 'X', 'quantity' => 1, 'unit_price_cents' => 10000]]];

        // Visitante: nao ha como afirmar que e a primeira compra.
        $this->postJson(route('discount-engine.simulator.run'), $carrinho)
            ->assertJsonPath('total_discount_cents', 0);

        $this->postJson(route('discount-engine.simulator.run'), $carrinho + [
            'customer' => ['id' => 1, 'completed_orders' => 0],
        ])->assertJsonPath('total_discount_cents', 1500);
    }

    public function test_carrinho_invalido_devolve_422(): void
    {
        $this->postJson(route('discount-engine.simulator.run'), [
            'items' => [[
                'sku' => 'QUEBRADO',
                'quantity' => 1,
                'unit_price_cents' => 9999,
                'components' => [['type' => 'base', 'unit_price_cents' => 4000, 'quantity' => 1]],
            ]],
        ])->assertStatus(422)->assertJsonStructure(['error']);
    }

    public function test_rateio_por_item_volta_na_resposta(): void
    {
        DiscountRule::create([
            'name' => '10%',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        $this->postJson(route('discount-engine.simulator.run'), [
            'items' => [
                ['id' => 'A', 'sku' => 'A', 'quantity' => 1, 'unit_price_cents' => 6000],
                ['id' => 'B', 'sku' => 'B', 'quantity' => 1, 'unit_price_cents' => 4000],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('by_item.A', 600)
            ->assertJsonPath('by_item.B', 400);
    }
}
