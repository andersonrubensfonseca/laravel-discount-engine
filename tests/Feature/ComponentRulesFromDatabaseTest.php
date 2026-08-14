<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Feature;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\PriceComponent;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Laravel\DiscountManager;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Tests\TestCase;

/**
 * Fecha o buraco que os testes unitarios de componente deixaram.
 *
 * Aqueles chamam as acoes direto, montando o DiscountScope na mao. Aqui o
 * caminho e completo:
 *
 *   JSON no banco -> RuleHydrator -> DiscountEngine::buildScope() -> acao
 *
 * O que so aparece por este caminho:
 *   - o hidratador lendo target 'components' e meta.component_types
 *   - o motor recortando o escopo por tipo de componente
 *   - a base 'current' com componentes, onde a chave "itemId::indice"
 *     precisa casar entre DiscountAllocation::byComponent() e
 *     DiscountScope::forCart(). Divergencia ali faz o acumulo somar errado
 *     sem nenhum teste unitario perceber.
 */
final class ComponentRulesFromDatabaseTest extends TestCase
{
    public function test_alvo_components_hidratado_do_banco_poupa_a_peca(): void
    {
        DiscountRule::create([
            'name' => '20% so na estamparia',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [
                [
                    'type' => 'percentage',
                    'value' => 20,
                    'target' => 'components',
                    'meta' => ['component_types' => ['print']],
                ],
            ],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(estampas: 3));

        // 20% de R$ 45 (3 estampas) = R$ 9. A peca de R$ 40 fica intacta.
        self::assertSame(900, $resultado->itemsDiscount()->cents);

        $porTipo = $resultado->discountByComponentType();
        self::assertSame(900, $porTipo['print']->cents);
        self::assertArrayNotHasKey('base', $porTipo);
    }

    public function test_alvo_cart_espalha_entre_todos_os_componentes(): void
    {
        DiscountRule::create([
            'name' => '10% no carrinho',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(estampas: 3));

        // Subtotal R$ 85 -> 10% = R$ 8,50, rateado 400 na peca e 450 nas estampas.
        self::assertSame(850, $resultado->itemsDiscount()->cents);

        $porTipo = $resultado->discountByComponentType();
        self::assertSame(400, $porTipo['base']->cents);
        self::assertSame(450, $porTipo['print']->cents);
    }

    public function test_preco_promocional_hidratado_do_json(): void
    {
        $this->regraPrimeiraEstampa();

        $resultado = $this->manager()->calculate($this->carrinho(estampas: 3));

        // Uma estampa de R$ 15 passa a R$ 1,99 -> desconto de R$ 13,01.
        self::assertSame(1301, $resultado->itemsDiscount()->cents);
    }

    public function test_cota_por_camisa_atravessa_o_hidratador(): void
    {
        $this->regraPrimeiraEstampa();

        $resultado = $this->manager()->calculate($this->carrinho(estampas: 3, camisas: 2));

        // Duas camisas, uma estampa promocional em cada.
        self::assertSame(2602, $resultado->itemsDiscount()->cents);
    }

    /**
     * O teste que mais me preocupava.
     *
     * Duas regras acumulaveis sobre O MESMO componente, com base 'current'.
     * A segunda so calcula certo se enxergar o saldo que a primeira ja
     * consumiu — e isso depende da chave "itemId::indice" casar entre o
     * que a alocacao produz e o que o escopo consulta.
     */
    public function test_duas_regras_acumulam_sobre_a_mesma_estampa(): void
    {
        $this->regraPrimeiraEstampa(prioridade: 10);

        DiscountRule::create([
            'name' => '10% no que sobrar da estamparia',
            'trigger' => 'automatic',
            'priority' => 20,
            'calculation_base' => 'current',
            'conditions' => [],
            'actions' => [
                [
                    'type' => 'percentage',
                    'value' => 10,
                    'target' => 'components',
                    'meta' => ['component_types' => ['print']],
                ],
            ],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(estampas: 3));

        // Estamparia: R$ 45. Primeira regra tira R$ 13,01, sobram R$ 31,99.
        // 10% disso = R$ 3,20 (arredondado). Total R$ 16,21.
        self::assertSame(1621, $resultado->itemsDiscount()->cents);
        self::assertSame(6879, $resultado->finalTotal()->cents);
    }

    public function test_base_original_ignora_o_desconto_anterior(): void
    {
        foreach ([10, 20] as $prioridade) {
            DiscountRule::create([
                'name' => "10% na estamparia ({$prioridade})",
                'trigger' => 'automatic',
                'priority' => $prioridade,
                'calculation_base' => 'original',
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'percentage',
                        'value' => 10,
                        'target' => 'components',
                        'meta' => ['component_types' => ['print']],
                    ],
                ],
            ]);
        }

        $resultado = $this->manager()->calculate($this->carrinho(estampas: 3));

        // Base original: 10% + 10% sobre R$ 45 = R$ 9. Nao R$ 8,55.
        self::assertSame(900, $resultado->itemsDiscount()->cents);
    }

    public function test_buy_x_get_y_sobre_estampas_vindo_do_banco(): void
    {
        DiscountRule::create([
            'name' => 'A cada 3 estampas, uma gratis',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [
                [
                    'type' => 'buy_x_get_y',
                    'value' => 0,
                    'target' => 'components',
                    'meta' => ['component_types' => ['print'], 'buy' => 2, 'free' => 1],
                ],
            ],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(estampas: 3));

        // 3 estampas = 1 grupo completo, 1 estampa gratis.
        self::assertSame(1500, $resultado->itemsDiscount()->cents);
        self::assertArrayNotHasKey('base', $resultado->discountByComponentType());
    }

    /**
     * A razao de existir de todo o refactor: nota fiscal por item.
     */
    public function test_alocacao_por_item_bate_com_o_total(): void
    {
        DiscountRule::create([
            'name' => '15% na estamparia',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [
                [
                    'type' => 'percentage',
                    'value' => 15,
                    'target' => 'components',
                    'meta' => ['component_types' => ['print']],
                ],
            ],
        ]);

        $carrinho = new CartContext(
            items: [
                $this->camisa(id: 'A', estampas: 2),
                $this->camisa(id: 'B', estampas: 1),
                new CartItem(id: 'C', sku: 'LISA', quantity: 1, unitPrice: Money::fromCents(4000)),
            ],
            shippingCost: Money::zero(),
        );

        $resultado = $this->manager()->calculate($carrinho);
        $porItem = $resultado->itemAllocations();

        $soma = array_sum(array_map(static fn (Money $m): int => $m->cents, $porItem));

        self::assertSame($resultado->itemsDiscount()->cents, $soma);

        // A camisa lisa nao tem estampa: nao entra no rateio.
        self::assertArrayNotHasKey('C', $porItem);
        self::assertArrayHasKey('A', $porItem);
        self::assertArrayHasKey('B', $porItem);
    }

    public function test_snapshot_carrega_a_alocacao_por_componente(): void
    {
        $this->regraPrimeiraEstampa();

        $snapshot = $this->manager()->calculate($this->carrinho(estampas: 2))->toArray();

        self::assertNotEmpty($snapshot['allocation']);

        $entrada = $snapshot['allocation'][0];

        self::assertSame('print', $entrada['component_type']);
        self::assertSame(1301, $entrada['amount_cents']);
        self::assertSame(1, $entrada['component_index']);
    }

    public function test_carrinho_sem_o_componente_alvo_nao_recebe_desconto(): void
    {
        DiscountRule::create([
            'name' => '30% em bordado',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [
                [
                    'type' => 'percentage',
                    'value' => 30,
                    'target' => 'components',
                    'meta' => ['component_types' => ['bordado']],
                ],
            ],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(estampas: 3));

        self::assertFalse($resultado->hasDiscount());
    }

    // ------------------------------------------------------------------

    private function manager(): DiscountManager
    {
        return $this->app->make(DiscountManager::class);
    }

    private function regraPrimeiraEstampa(int $prioridade = 100): DiscountRule
    {
        return DiscountRule::create([
            'name' => 'Primeira estampa a R$ 1,99',
            'trigger' => 'automatic',
            'priority' => $prioridade,
            'calculation_base' => 'current',
            'conditions' => [],
            'actions' => [
                [
                    'type' => 'component_unit_price',
                    'value' => 0,
                    'target' => 'components',
                    'meta' => [
                        'component_types' => ['print'],
                        'first_n' => 1,
                        'unit_price_cents' => 199,
                        'per' => 'item_unit',
                    ],
                ],
            ],
        ]);
    }

    private function camisa(string|int $id, int $estampas, int $camisas = 1): CartItem
    {
        return new CartItem(
            id: $id,
            sku: 'CAMISA-ESTAMPADA',
            quantity: $camisas,
            components: [
                new PriceComponent('base', Money::fromCents(4000)),
                new PriceComponent('print', Money::fromCents(1500), quantity: $estampas),
            ],
        );
    }

    private function carrinho(int $estampas, int $camisas = 1): CartContext
    {
        return new CartContext(
            items: [$this->camisa(id: 1, estampas: $estampas, camisas: $camisas)],
            shippingCost: Money::zero(),
        );
    }
}
