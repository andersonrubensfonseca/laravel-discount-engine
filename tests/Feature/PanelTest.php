<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountCoupon;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountUsage;
use SolutionsTI\DiscountEngine\Tests\TestCase;

final class PanelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_listagem_abre(): void
    {
        DiscountRule::create([
            'name' => 'Campanha de inverno',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        $this->get(route('discount-engine.rules.index'))
            ->assertOk()
            ->assertSee('Campanha de inverno');
    }

    public function test_formulario_de_criacao_abre(): void
    {
        $this->get(route('discount-engine.rules.create'))
            ->assertOk()
            ->assertSee('cart_subtotal')       // condicoes disponiveis
            ->assertSee('buy_x_get_y');        // acoes disponiveis
    }

    public function test_cria_regra_automatica(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload())
            ->assertRedirect();

        $regra = DiscountRule::query()->firstOrFail();

        self::assertSame('Nova campanha', $regra->name);
        self::assertSame('percentage', $regra->actions[0]['type']);
        self::assertSame('and', $regra->conditions['logic']);
    }

    public function test_json_malformado_e_recusado(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'actions_json' => '[{"type": "percentage",,,}]',
        ]))->assertSessionHasErrors('actions_json');

        self::assertSame(0, DiscountRule::query()->count());
    }

    public function test_acao_inexistente_e_recusada(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'actions_json' => '[{"type":"desconto_magico","value":10,"target":"cart"}]',
        ]))->assertSessionHasErrors('actions_json');
    }

    public function test_condicao_inexistente_e_recusada(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'conditions_json' => '{"logic":"and","children":[{"type":"fase_da_lua","operator":"eq","value":1}]}',
        ]))->assertSessionHasErrors('conditions_json');
    }

    public function test_operador_invalido_e_recusado(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'conditions_json' => '{"logic":"and","children":[{"type":"cart_subtotal","operator":"maior","value":100}]}',
        ]))->assertSessionHasErrors('conditions_json');
    }

    /**
     * O erro que so apareceria no checkout de um cliente real: alvo
     * 'components' sem dizer quais componentes.
     */
    public function test_alvo_components_sem_tipos_e_recusado(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'actions_json' => '[{"type":"percentage","value":10,"target":"components"}]',
        ]))->assertSessionHasErrors('actions_json');
    }

    public function test_tiered_sem_faixas_e_recusado(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'actions_json' => '[{"type":"tiered","value":0,"target":"cart"}]',
        ]))->assertSessionHasErrors('actions_json');
    }

    public function test_regra_de_cupom_sem_codigo_e_recusada(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'trigger' => 'coupon',
            'coupon_codes' => '',
        ]))->assertSessionHasErrors('coupon_codes');
    }

    public function test_cria_regra_de_cupom_com_varios_codigos(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'trigger' => 'coupon',
            'coupon_codes' => "bemvindo\nVOLTA10\n bemvindo ",
            'usage_limit' => 50,
        ]))->assertRedirect();

        $codigos = DiscountCoupon::query()->pluck('code')->sort()->values()->all();

        // Normalizados em caixa alta e sem duplicata.
        self::assertSame(['BEMVINDO', 'VOLTA10'], $codigos);
        self::assertSame(50, DiscountCoupon::query()->first()->usage_limit);
    }

    public function test_edicao_remove_codigo_que_saiu_da_lista(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'trigger' => 'coupon',
            'coupon_codes' => "UM\nDOIS",
        ]));

        $regra = DiscountRule::query()->firstOrFail();

        $this->put(route('discount-engine.rules.update', $regra), $this->payload([
            'trigger' => 'coupon',
            'coupon_codes' => 'UM',
        ]))->assertRedirect();

        self::assertSame(['UM'], DiscountCoupon::query()->pluck('code')->all());
    }

    /**
     * Cupom ja usado nao pode sumir: apagar a linha quebraria a referencia
     * dos usos gravados e o historico do pedido.
     */
    public function test_cupom_ja_usado_e_apenas_desativado(): void
    {
        $this->post(route('discount-engine.rules.store'), $this->payload([
            'trigger' => 'coupon',
            'coupon_codes' => "UM\nDOIS",
        ]));

        $regra = DiscountRule::query()->firstOrFail();
        $cupom = DiscountCoupon::query()->where('code', 'DOIS')->firstOrFail();
        $cupom->update(['used_count' => 3]);

        $this->put(route('discount-engine.rules.update', $regra), $this->payload([
            'trigger' => 'coupon',
            'coupon_codes' => 'UM',
        ]));

        $cupom->refresh();

        self::assertNotNull($cupom);
        self::assertFalse($cupom->active);
    }

    public function test_alternar_status(): void
    {
        $regra = DiscountRule::create([
            'name' => 'X',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        $this->post(route('discount-engine.rules.toggle', $regra));

        self::assertFalse($regra->fresh()->active);
    }

    public function test_regra_ja_usada_nao_pode_ser_apagada(): void
    {
        $regra = DiscountRule::create([
            'name' => 'Usada',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        DiscountUsage::create([
            'rule_id' => $regra->id,
            'order_id' => 'PEDIDO-1',
            'amount_cents' => 1000,
        ]);

        $this->delete(route('discount-engine.rules.destroy', $regra))
            ->assertSessionHasErrors('rule');

        self::assertNotNull($regra->fresh());
    }

    public function test_regra_sem_uso_pode_ser_apagada(): void
    {
        $regra = DiscountRule::create([
            'name' => 'Descartavel',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        $this->delete(route('discount-engine.rules.destroy', $regra))->assertRedirect();

        self::assertNull($regra->fresh());
    }

    /** @param  array<string,mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nova campanha',
            'description' => '',
            'trigger' => 'automatic',
            'priority' => 100,
            'combination_mode' => 'stackable',
            'resolution_group' => '',
            'resolution_strategy' => 'first_by_priority',
            'calculation_base' => 'current',
            'stop_further_processing' => 0,
            'active' => 1,
            'conditions_json' => '{"logic":"and","children":[{"type":"cart_subtotal","operator":"gte","value":20000}]}',
            'actions_json' => '[{"type":"percentage","value":10,"target":"cart"}]',
        ], $overrides);
    }
}
