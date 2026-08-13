<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Feature;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Laravel\DiscountManager;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Tests\TestCase;

/**
 * Cache furado no momento certo.
 *
 * Sem isso o time comercial edita uma campanha no painel e passa cinco
 * minutos achando que o sistema quebrou — o pior tipo de bug, porque
 * gera chamado em vez de erro.
 */
final class CacheInvalidationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('discount-engine.cache.enabled', true);
        $app['config']->set('cache.default', 'array');
    }

    public function test_editar_uma_regra_invalida_o_cache(): void
    {
        $regra = DiscountRule::create([
            'name' => '10%',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        // Primeira chamada popula o cache.
        self::assertSame(1000, $this->manager()->calculate($this->carrinho())->itemsDiscount()->cents);

        $regra->update([
            'actions' => [['type' => 'percentage', 'value' => 25, 'target' => 'cart']],
        ]);

        self::assertSame(2500, $this->manager()->calculate($this->carrinho())->itemsDiscount()->cents);
    }

    public function test_criar_uma_regra_nova_invalida_o_cache(): void
    {
        self::assertFalse($this->manager()->calculate($this->carrinho())->hasDiscount());

        DiscountRule::create([
            'name' => 'Nova campanha',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 15, 'target' => 'cart']],
        ]);

        self::assertSame(1500, $this->manager()->calculate($this->carrinho())->itemsDiscount()->cents);
    }

    public function test_apagar_uma_regra_invalida_o_cache(): void
    {
        $regra = DiscountRule::create([
            'name' => 'Temporaria',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        self::assertTrue($this->manager()->calculate($this->carrinho())->hasDiscount());

        $regra->delete();

        self::assertFalse($this->manager()->calculate($this->carrinho())->hasDiscount());
    }

    public function test_desativar_uma_regra_tira_ela_do_ar_na_hora(): void
    {
        $regra = DiscountRule::create([
            'name' => 'Sera desativada',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        self::assertTrue($this->manager()->calculate($this->carrinho())->hasDiscount());

        $regra->update(['active' => false]);

        self::assertFalse($this->manager()->calculate($this->carrinho())->hasDiscount());
    }

    private function manager(): DiscountManager
    {
        return $this->app->make(DiscountManager::class);
    }

    private function carrinho(): CartContext
    {
        return new CartContext(
            items: [new CartItem(id: 1, sku: 'SKU-1', quantity: 1, unitPrice: Money::fromCents(10000))],
            shippingCost: Money::zero(),
        );
    }
}
