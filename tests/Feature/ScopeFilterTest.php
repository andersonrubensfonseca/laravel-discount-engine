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
 * Recorte por categoria e SKU.
 *
 * Condicao nao substitui isto: condicao decide SE a regra roda, o recorte
 * decide SOBRE QUEM ela roda. Sem o recorte, "R$ 1,99 na primeira estampa
 * das camisas" tambem descontaria estampas de canecas no mesmo carrinho.
 */
final class ScopeFilterTest extends TestCase
{
    public function test_primeira_estampa_promocional_so_na_categoria_alvo(): void
    {
        DiscountRule::create([
            'name' => 'Primeira estampa a R$ 1,99 nas camisas',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [[
                'type' => 'component_unit_price',
                'value' => 0,
                'target' => 'components',
                'meta' => [
                    'component_types' => ['print'],
                    'category_ids' => [7],
                    'first_n' => 1,
                    'unit_price_cents' => 199,
                    'per' => 'item_unit',
                ],
            ]],
        ]);

        $resultado = $this->manager()->calculate($this->carrinhoMisto());

        // So a camisa (categoria 7) entra: 1500 - 199 = 1301.
        // A caneca estampada da categoria 9 fica intacta.
        self::assertSame(1301, $resultado->itemsDiscount()->cents);

        $porItem = $resultado->itemAllocations();
        self::assertArrayHasKey('CAMISA', $porItem);
        self::assertArrayNotHasKey('CANECA', $porItem);
    }

    public function test_leve_3_pague_2_so_na_categoria_alvo(): void
    {
        DiscountRule::create([
            'name' => 'Leve 3 pague 2 nas camisas',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [[
                'type' => 'buy_x_get_y',
                'value' => 0,
                'target' => 'components',
                'meta' => [
                    'component_types' => ['base'],
                    'category_ids' => [7],
                    'buy' => 2,
                    'free' => 1,
                ],
            ]],
        ]);

        $carrinho = new CartContext(
            items: [
                new CartItem(id: 'CAMISA', sku: 'CAMISA', quantity: 3,
                    unitPrice: Money::fromCents(4000), categoryIds: [7]),
                new CartItem(id: 'CANECA', sku: 'CANECA', quantity: 5,
                    unitPrice: Money::fromCents(1000), categoryIds: [9]),
            ],
            shippingCost: Money::zero(),
        );

        // 3 camisas = 1 grupo, 1 brinde de R$ 40. As 5 canecas nao contam.
        self::assertSame(4000, $this->manager()->calculate($carrinho)->itemsDiscount()->cents);
    }

    public function test_recorte_por_sku(): void
    {
        DiscountRule::create([
            'name' => '50% no SKU especifico',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [[
                'type' => 'percentage',
                'value' => 50,
                'target' => 'items',
                'meta' => ['skus' => ['CANECA']],
            ]],
        ]);

        $resultado = $this->manager()->calculate($this->carrinhoMisto());

        // Caneca: base 1000 + print 1500 = 2500. 50% = 1250.
        self::assertSame(1250, $resultado->itemsDiscount()->cents);
    }

    public function test_categoria_inexistente_nao_desconta_nada(): void
    {
        DiscountRule::create([
            'name' => 'Categoria que ninguem tem',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [[
                'type' => 'percentage',
                'value' => 30,
                'target' => 'items',
                'meta' => ['category_ids' => [999]],
            ]],
        ]);

        self::assertFalse($this->manager()->calculate($this->carrinhoMisto())->hasDiscount());
    }

    /**
     * ID de categoria chega como int do banco e como string do formulario
     * do painel. Comparacao estrita quebraria so em producao.
     */
    public function test_id_de_categoria_como_string_funciona_igual(): void
    {
        DiscountRule::create([
            'name' => 'Categoria como string',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [[
                'type' => 'percentage',
                'value' => 10,
                'target' => 'items',
                'meta' => ['category_ids' => ['7']],
            ]],
        ]);

        // Camisa: base 4000 + print 1500 = 5500. 10% = 550.
        self::assertSame(550, $this->manager()->calculate($this->carrinhoMisto())->itemsDiscount()->cents);
    }

    public function test_sem_recorte_todos_os_itens_participam(): void
    {
        DiscountRule::create([
            'name' => 'Dez por cento em tudo',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        // 5500 da camisa + 2500 da caneca = 8000. 10% = 800.
        self::assertSame(800, $this->manager()->calculate($this->carrinhoMisto())->itemsDiscount()->cents);
    }

    private function manager(): DiscountManager
    {
        return $this->app->make(DiscountManager::class);
    }

    private function carrinhoMisto(): CartContext
    {
        return new CartContext(
            items: [
                new CartItem(
                    id: 'CAMISA', sku: 'CAMISA', quantity: 1, categoryIds: [7],
                    components: [
                        new PriceComponent('base', Money::fromCents(4000)),
                        new PriceComponent('print', Money::fromCents(1500)),
                    ],
                ),
                new CartItem(
                    id: 'CANECA', sku: 'CANECA', quantity: 1, categoryIds: [9],
                    components: [
                        new PriceComponent('base', Money::fromCents(1000)),
                        new PriceComponent('print', Money::fromCents(1500)),
                    ],
                ),
            ],
            shippingCost: Money::zero(),
        );
    }
}
