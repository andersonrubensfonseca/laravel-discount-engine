<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Feature;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Enums\RejectionReason;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Laravel\DiscountManager;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountCoupon;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Tests\TestCase;

final class CouponValidationTest extends TestCase
{
    public function test_cupom_valido_e_aceito(): void
    {
        $this->cupom('BEMVINDO', valorCentavos: 5000);

        $validacao = $this->manager()->validateCoupon('BEMVINDO', $this->carrinho(30000));

        self::assertTrue($validacao->accepted);
        self::assertSame(5000, $validacao->discount->cents);
    }

    /**
     * O bug que essa normalizacao evita: sem o mutator de caixa alta, este
     * teste passaria no MySQL (collation case-insensitive) e falharia no
     * SQLite. Comportamento que muda conforme o banco e pior que bug —
     * so aparece em producao.
     */
    public function test_codigo_e_insensivel_a_caixa_e_espacos(): void
    {
        $this->cupom('BEMVINDO', valorCentavos: 5000);

        foreach (['bemvindo', ' BemVindo ', 'BEMVINDO'] as $digitado) {
            self::assertTrue(
                $this->manager()->validateCoupon($digitado, $this->carrinho(30000))->accepted,
                "Falhou para o codigo digitado: [{$digitado}]",
            );
        }
    }

    public function test_codigo_inexistente_recebe_mensagem_propria(): void
    {
        $validacao = $this->manager()->validateCoupon('NAOEXISTE', $this->carrinho(30000));

        self::assertFalse($validacao->accepted);
        self::assertSame('Cupom invalido ou expirado.', $validacao->message());
    }

    public function test_cupom_expirado_nao_e_candidato(): void
    {
        $this->cupom('EXPIRADO', valorCentavos: 5000, expiraEm: now()->subDay());

        $validacao = $this->manager()->validateCoupon('EXPIRADO', $this->carrinho(30000));

        self::assertFalse($validacao->accepted);
    }

    /**
     * Este e o caso que motivou a correcao no DiscountManager.
     *
     * Existe uma regra automatica rejeitada no carrinho. A versao anterior
     * pegava o motivo da PRIMEIRA rejeicao da lista e mostrava ao cliente
     * o erro de uma campanha que ele nem conhece. Agora o motivo tem que
     * vir da regra do cupom digitado.
     */
    public function test_motivo_da_rejeicao_vem_da_regra_do_cupom_nao_de_outra(): void
    {
        // Regra automatica que nao bate — ruido proposital.
        DiscountRule::create([
            'name' => 'Automatica so para VIP',
            'trigger' => 'automatic',
            'priority' => 1,
            'conditions' => [
                'logic' => 'and',
                'children' => [
                    ['type' => 'customer_group', 'operator' => 'contains_any', 'value' => ['vip']],
                ],
            ],
            'actions' => [['type' => 'percentage', 'value' => 5, 'target' => 'cart']],
        ]);

        // Cupom valido, mas exige carrinho maior.
        $this->cupom('MINIMO500', valorCentavos: 5000, condicoes: [
            'logic' => 'and',
            'children' => [
                ['type' => 'cart_subtotal', 'operator' => 'gte', 'value' => 50000],
            ],
        ]);

        $validacao = $this->manager()->validateCoupon('MINIMO500', $this->carrinho(30000));

        self::assertFalse($validacao->accepted);
        self::assertSame(RejectionReason::ConditionsNotMet, $validacao->reason);
    }

    public function test_codigo_vazio_nao_consulta_o_banco(): void
    {
        $validacao = $this->manager()->validateCoupon('   ', $this->carrinho(30000));

        self::assertFalse($validacao->accepted);
        self::assertSame('Informe um codigo de cupom.', $validacao->message());
    }

    public function test_varios_codigos_apontando_para_a_mesma_regra(): void
    {
        $regra = DiscountRule::create([
            'name' => 'Campanha com codigos individuais',
            'trigger' => 'coupon',
            'conditions' => [],
            'actions' => [['type' => 'fixed_amount', 'value' => 2000, 'target' => 'cart']],
        ]);

        foreach (['CLIENTE-A', 'CLIENTE-B'] as $codigo) {
            DiscountCoupon::create(['rule_id' => $regra->id, 'code' => $codigo]);
        }

        foreach (['CLIENTE-A', 'CLIENTE-B'] as $codigo) {
            $validacao = $this->manager()->validateCoupon($codigo, $this->carrinho(30000));

            self::assertTrue($validacao->accepted);
            self::assertSame(2000, $validacao->discount->cents);
        }
    }

    // ------------------------------------------------------------------

    private function manager(): DiscountManager
    {
        return $this->app->make(DiscountManager::class);
    }

    /** @param  array<string,mixed>  $condicoes */
    private function cupom(
        string $codigo,
        int $valorCentavos,
        array $condicoes = [],
        mixed $expiraEm = null,
        ?int $limite = null,
        ?int $limitePorCliente = null,
    ): DiscountCoupon {
        $regra = DiscountRule::create([
            'name' => "Regra do cupom {$codigo}",
            'trigger' => 'coupon',
            'conditions' => $condicoes,
            'actions' => [['type' => 'fixed_amount', 'value' => $valorCentavos, 'target' => 'cart']],
        ]);

        return DiscountCoupon::create([
            'rule_id' => $regra->id,
            'code' => $codigo,
            'expires_at' => $expiraEm,
            'usage_limit' => $limite,
            'usage_limit_per_customer' => $limitePorCliente,
        ]);
    }

    private function carrinho(int $subtotal): CartContext
    {
        return new CartContext(
            items: [new CartItem(id: 1, sku: 'SKU-1', quantity: 1, unitPrice: Money::fromCents($subtotal))],
            shippingCost: Money::zero(),
        );
    }
}
