<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Feature;

use Illuminate\Database\QueryException;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\CustomerContext;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Laravel\DiscountManager;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountCoupon;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountUsage;
use SolutionsTI\DiscountEngine\Tests\TestCase;

/**
 * A parte do pacote onde um bug custa dinheiro de verdade.
 *
 * Limitacao honesta destes testes: PHPUnit e single-threaded, entao eles
 * NAO provam que o lockForUpdate funciona sob concorrencia real. Provam a
 * logica sequencial e a existencia das redes de protecao. A validacao de
 * concorrencia de verdade exige duas conexoes simultaneas — vale fazer
 * manualmente contra o MySQL antes de ir para producao.
 */
final class UsageReserverTest extends TestCase
{
    public function test_reserva_incrementa_o_contador_e_grava_o_uso(): void
    {
        $cupom = $this->cupom('UNICO', limite: 3);

        $resultado = $this->manager()->calculate($this->carrinho(30000, ['UNICO']));
        $reservou = $this->manager()->reserve($resultado, 'PEDIDO-1', 42);

        self::assertTrue($reservou);
        self::assertSame(1, $cupom->fresh()->used_count);

        $uso = DiscountUsage::query()->first();
        self::assertSame('PEDIDO-1', $uso->order_id);
        self::assertSame('42', $uso->customer_id);
        self::assertSame(5000, $uso->amount_cents);
    }

    public function test_snapshot_e_gravado_junto_com_o_uso(): void
    {
        $this->cupom('SNAP', limite: 5);

        $resultado = $this->manager()->calculate($this->carrinho(30000, ['SNAP']));
        $this->manager()->reserve($resultado, 'PEDIDO-SNAP', 7);

        $snapshot = DiscountUsage::query()->first()->snapshot;

        self::assertIsArray($snapshot);
        self::assertSame(5000, $snapshot['total_discount_cents']);
        self::assertSame(25000, $snapshot['final_total_cents']);
        self::assertNotEmpty($snapshot['applied']);
    }

    /**
     * A corrida de verdade: dois clientes simulam ENQUANTO ainda ha saldo e
     * so depois fecham. Os dois viram o desconto na tela; so um pode leva-lo.
     *
     * Calcular o segundo carrinho depois da primeira reserva nao testaria
     * isto — o tracker ja teria escondido o cupom na simulacao, e a reserva
     * retornaria true por nao ter nada para reservar.
     */
    public function test_limite_global_esgotado_recusa_a_reserva(): void
    {
        $cupom = $this->cupom('ULTIMO', limite: 1);

        $primeiro = $this->manager()->calculate($this->carrinho(30000, ['ULTIMO']));
        $segundo = $this->manager()->calculate($this->carrinho(30000, ['ULTIMO']));

        self::assertTrue($primeiro->hasDiscount());
        self::assertTrue($segundo->hasDiscount());

        self::assertTrue($this->manager()->reserve($primeiro, 'PEDIDO-1', 1));
        self::assertFalse($this->manager()->reserve($segundo, 'PEDIDO-2', 2));

        self::assertSame(1, $cupom->fresh()->used_count);
        self::assertSame(1, DiscountUsage::query()->count());
    }

    /**
     * Regressao do bug encontrado ao investigar a falha acima.
     *
     * `return false` de dentro de DB::transaction() COMMITA. Com duas regras
     * aplicadas, a recusa na segunda deixaria o uso da primeira gravado —
     * consumindo cupom de um pedido que nunca fechou. Tem que ser tudo ou nada.
     */
    public function test_recusa_no_meio_do_caminho_nao_deixa_uso_gravado(): void
    {
        $this->cupom('DISPONIVEL', limite: 10);
        $this->cupom('ESCASSO', limite: 1);

        // Cliente 1 simula com os dois cupons, ambos disponiveis.
        $resultado = $this->manager()->calculate($this->carrinho(30000, ['DISPONIVEL', 'ESCASSO']));
        self::assertCount(2, $resultado->applied);

        // Outro pedido consome o cupom escasso antes do fechamento do cliente 1.
        $outro = $this->manager()->calculate($this->carrinho(30000, ['ESCASSO']));
        self::assertTrue($this->manager()->reserve($outro, 'PEDIDO-OUTRO', 99));

        // O fechamento do cliente 1 falha e nao pode deixar rastro nenhum.
        self::assertFalse($this->manager()->reserve($resultado, 'PEDIDO-1', 1));

        self::assertSame(0, DiscountUsage::query()->where('order_id', 'PEDIDO-1')->count());
        self::assertSame(1, DiscountUsage::query()->count());
        self::assertSame(0, DiscountCoupon::query()->where('code', 'DISPONIVEL')->first()->used_count);
    }

    public function test_simulacao_ja_esconde_cupom_esgotado(): void
    {
        $this->cupom('ESGOTADO', limite: 1);

        $primeiro = $this->manager()->calculate($this->carrinho(30000, ['ESGOTADO']));
        $this->manager()->reserve($primeiro, 'PEDIDO-1', 1);

        $validacao = $this->manager()->validateCoupon('ESGOTADO', $this->carrinho(30000));

        self::assertFalse($validacao->accepted);
        self::assertSame('usage_limit_reached', $validacao->reason->value);
    }

    public function test_limite_por_cliente_e_respeitado(): void
    {
        $this->cupom('PORCLIENTE', limite: 100, limitePorCliente: 1);

        $carrinhoJoao = $this->carrinho(30000, ['PORCLIENTE'], clienteId: 10);

        $primeiro = $this->manager()->calculate($carrinhoJoao);
        self::assertTrue($this->manager()->reserve($primeiro, 'PEDIDO-1', 10));

        // Mesmo cliente, segunda tentativa: a simulacao ja nao concede.
        self::assertFalse($this->manager()->calculate($carrinhoJoao)->hasDiscount());

        // Outro cliente continua podendo usar.
        $carrinhoMaria = $this->carrinho(30000, ['PORCLIENTE'], clienteId: 20);
        self::assertTrue($this->manager()->calculate($carrinhoMaria)->hasDiscount());
    }

    public function test_visitante_nao_usa_cupom_com_limite_por_cliente(): void
    {
        $this->cupom('IDENTIFICADO', limite: 100, limitePorCliente: 1);

        // Sem cliente identificado nao ha como contar uso individual;
        // negar e mais seguro do que liberar sem controle.
        $resultado = $this->manager()->calculate($this->carrinho(30000, ['IDENTIFICADO']));

        self::assertFalse($resultado->hasDiscount());
    }

    /**
     * Segunda linha de defesa: a unique(order_id, rule_id).
     *
     * Se um retry chamar reserve() duas vezes para o mesmo pedido, o banco
     * recusa. Melhor estourar excecao do que consumir o cupom duas vezes.
     */
    public function test_mesmo_pedido_nao_registra_o_uso_duas_vezes(): void
    {
        $this->cupom('IDEMP', limite: 10);

        $resultado = $this->manager()->calculate($this->carrinho(30000, ['IDEMP']));

        self::assertTrue($this->manager()->reserve($resultado, 'PEDIDO-X', 1));

        $this->expectException(QueryException::class);
        $this->manager()->reserve($resultado, 'PEDIDO-X', 1);
    }

    public function test_carrinho_sem_desconto_nao_grava_nada(): void
    {
        $resultado = $this->manager()->calculate($this->carrinho(30000));

        self::assertTrue($this->manager()->reserve($resultado, 'PEDIDO-VAZIO', 1));
        self::assertSame(0, DiscountUsage::query()->count());
    }

    public function test_regra_automatica_sem_cupom_tambem_registra_uso(): void
    {
        DiscountRule::create([
            'name' => 'Automatica 10%',
            'trigger' => 'automatic',
            'conditions' => [],
            'actions' => [['type' => 'percentage', 'value' => 10, 'target' => 'cart']],
        ]);

        $resultado = $this->manager()->calculate($this->carrinho(30000));
        $this->manager()->reserve($resultado, 'PEDIDO-AUTO', 1);

        $uso = DiscountUsage::query()->first();

        self::assertNotNull($uso);
        self::assertNull($uso->coupon_id);
        self::assertSame(3000, $uso->amount_cents);
    }

    // ------------------------------------------------------------------

    private function manager(): DiscountManager
    {
        return $this->app->make(DiscountManager::class);
    }

    private function cupom(
        string $codigo,
        ?int $limite = null,
        ?int $limitePorCliente = null,
    ): DiscountCoupon {
        $regra = DiscountRule::create([
            'name' => "Regra {$codigo}",
            'trigger' => 'coupon',
            'conditions' => [],
            'actions' => [['type' => 'fixed_amount', 'value' => 5000, 'target' => 'cart']],
        ]);

        return DiscountCoupon::create([
            'rule_id' => $regra->id,
            'code' => $codigo,
            'usage_limit' => $limite,
            'usage_limit_per_customer' => $limitePorCliente,
        ]);
    }

    /** @param  array<int,string>  $cupons */
    private function carrinho(int $subtotal, array $cupons = [], ?int $clienteId = null): CartContext
    {
        return new CartContext(
            items: [new CartItem(id: 1, sku: 'SKU-1', quantity: 1, unitPrice: Money::fromCents($subtotal))],
            shippingCost: Money::zero(),
            customer: $clienteId === null ? null : new CustomerContext(id: $clienteId, completedOrders: 5),
            couponCodes: $cupons,
        );
    }
}
