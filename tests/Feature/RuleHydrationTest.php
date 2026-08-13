<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Feature;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\CustomerContext;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Laravel\DiscountManager;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Tests\TestCase;

/**
 * Cobre a fronteira mais arriscada do pacote: transformar JSON de coluna
 * em objetos de dominio. Se o RuleHydrator errar, o motor calcula certo
 * a coisa errada — e nenhum teste unitario do Core percebe.
 */
final class RuleHydrationTest extends TestCase
{
    public function test_regra_automatica_simples_sai_do_banco_e_aplica(): void
    {
        DiscountRule::create([
            'name' => '10% acima de R$ 200',
            'trigger' => 'automatic',
            'priority' => 10,
            'conditions' => [
                'logic' => 'and',
                'children' => [
                    ['type' => 'cart_subtotal', 'operator' => 'gte', 'value' => 20000],
                ],
            ],
            'actions' => [
                ['type' => 'percentage', 'value' => 10, 'target' => 'cart'],
            ],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(25000));

        self::assertSame(2500, $resultado->itemsDiscount->cents);
        self::assertSame(22500, $resultado->finalTotal()->cents);
    }

    public function test_arvore_aninhada_and_dentro_de_or_e_hidratada_corretamente(): void
    {
        DiscountRule::create([
            'name' => 'Acima de R$ 100 E (primeira compra OU grupo vip)',
            'trigger' => 'automatic',
            'conditions' => [
                'logic' => 'and',
                'children' => [
                    ['type' => 'cart_subtotal', 'operator' => 'gte', 'value' => 10000],
                    [
                        'logic' => 'or',
                        'children' => [
                            ['type' => 'first_purchase', 'operator' => 'eq', 'value' => true],
                            ['type' => 'customer_group', 'operator' => 'contains_any', 'value' => ['vip']],
                        ],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'percentage', 'value' => 20, 'target' => 'cart'],
            ],
        ]);

        // Cliente veterano, mas VIP: o ramo OR salva.
        $vip = $this->carrinho(20000, cliente: new CustomerContext(
            id: 1,
            groups: ['vip'],
            completedOrders: 30,
        ));

        // Cliente veterano e nao-VIP: nenhum ramo do OR bate.
        $comum = $this->carrinho(20000, cliente: new CustomerContext(
            id: 2,
            groups: ['padrao'],
            completedOrders: 30,
        ));

        self::assertSame(4000, $this->manager()->calculate($vip)->itemsDiscount->cents);
        self::assertFalse($this->manager()->calculate($comum)->hasDiscount());
    }

    public function test_teto_da_acao_e_respeitado_apos_hidratacao(): void
    {
        DiscountRule::create([
            'name' => '50% limitado a R$ 30',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [
                ['type' => 'percentage', 'value' => 50, 'target' => 'cart', 'max_discount_cents' => 3000],
            ],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(100000));

        self::assertSame(3000, $resultado->itemsDiscount->cents);
    }

    public function test_regra_inativa_nao_e_sequer_candidata(): void
    {
        DiscountRule::create([
            'name' => 'Desativada',
            'trigger' => 'automatic',
            'active' => false,
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        self::assertFalse($this->manager()->calculate($this->carrinho(10000))->hasDiscount());
    }

    public function test_regra_fora_da_vigencia_e_rejeitada_com_o_motivo_certo(): void
    {
        DiscountRule::create([
            'name' => 'Campanha encerrada',
            'trigger' => 'automatic',
            'valid_until' => now()->subDay(),
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(10000));

        self::assertFalse($resultado->hasDiscount());
        self::assertSame('outside_date_window', $resultado->rejected[0]->reason->value);
    }

    public function test_prioridade_do_banco_define_a_ordem(): void
    {
        DiscountRule::create([
            'name' => 'Segunda',
            'trigger' => 'automatic',
            'priority' => 50,
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 50, 'target' => 'cart']],
        ]);

        DiscountRule::create([
            'name' => 'Primeira',
            'trigger' => 'automatic',
            'priority' => 10,
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 50, 'target' => 'cart']],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(10000));

        self::assertSame('Primeira', $resultado->applied[0]->ruleName);
        self::assertSame(7500, $resultado->itemsDiscount->cents);
    }

    public function test_desconto_de_frete_nao_encosta_no_subtotal(): void
    {
        DiscountRule::create([
            'name' => 'Frete gratis',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'free_shipping', 'value' => 100, 'target' => 'shipping']],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(10000, frete: 2500));

        self::assertSame(0, $resultado->itemsDiscount->cents);
        self::assertSame(2500, $resultado->shippingDiscount->cents);
        self::assertSame(10000, $resultado->finalTotal()->cents);
    }

    // ------------------------------------------------------------------

    private function manager(): DiscountManager
    {
        return $this->app->make(DiscountManager::class);
    }

    private function carrinho(
        int $subtotal,
        int $frete = 0,
        ?CustomerContext $cliente = null,
    ): CartContext {
        return new CartContext(
            items: [new CartItem(
                id: 1,
                sku: 'SKU-1',
                quantity: 1,
                unitPrice: Money::fromCents($subtotal),
            )],
            shippingCost: Money::fromCents($frete),
            customer: $cliente,
        );
    }
}
