<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolutionsTI\DiscountEngine\Core\Actions\BuyXGetYDiscount;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Actions\TieredDiscount;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\PriceComponent;
use SolutionsTI\DiscountEngine\Core\Enums\ActionTarget;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

final class AdvancedActionsTest extends TestCase
{
    // ------------------------------------------------------------------
    // Leve X pague Y
    // ------------------------------------------------------------------

    public function test_leve_3_pague_2_com_itens_de_mesmo_preco(): void
    {
        $carrinho = $this->carrinho([
            ['sku' => 'A', 'qtd' => 3, 'preco' => 3000],
        ]);

        // 3 unidades de R$ 30 = 1 grupo, 1 unidade gratis.
        self::assertSame(3000, $this->buyXGetY($carrinho)->cents);
    }

    public function test_grupo_incompleto_nao_gera_brinde(): void
    {
        $carrinho = $this->carrinho([
            ['sku' => 'A', 'qtd' => 2, 'preco' => 3000],
        ]);

        self::assertSame(0, $this->buyXGetY($carrinho)->cents);
    }

    public function test_dois_grupos_completos_geram_dois_brindes(): void
    {
        $carrinho = $this->carrinho([
            ['sku' => 'A', 'qtd' => 7, 'preco' => 1000],
        ]);

        // 7 unidades = 2 grupos completos (6) + 1 sobrando.
        self::assertSame(2000, $this->buyXGetY($carrinho)->cents);
    }

    /**
     * O ponto sutil: com precos diferentes, quem sai de graca?
     *
     * Padrao 'cheapest' — dentro de cada grupo de 3, a unidade mais barata
     * e a gratuita. Com R$ 50, R$ 30 e R$ 20, o brinde e o de R$ 20.
     */
    public function test_por_padrao_o_item_gratuito_e_o_mais_barato(): void
    {
        $carrinho = $this->carrinho([
            ['sku' => 'CARO', 'qtd' => 1, 'preco' => 5000],
            ['sku' => 'MEDIO', 'qtd' => 1, 'preco' => 3000],
            ['sku' => 'BARATO', 'qtd' => 1, 'preco' => 2000],
        ]);

        self::assertSame(2000, $this->buyXGetY($carrinho)->cents);
    }

    public function test_modo_most_expensive_libera_o_item_mais_caro(): void
    {
        $carrinho = $this->carrinho([
            ['sku' => 'CARO', 'qtd' => 1, 'preco' => 5000],
            ['sku' => 'MEDIO', 'qtd' => 1, 'preco' => 3000],
            ['sku' => 'BARATO', 'qtd' => 1, 'preco' => 2000],
        ]);

        $desconto = $this->buyXGetY($carrinho, ['free_item' => 'most_expensive']);

        self::assertSame(5000, $desconto->cents);
    }

    /**
     * Com 6 unidades e precos variados, o agrupamento importa: dentro de
     * cada trio o mais barato sai de graca. Ordenado do mais caro para o
     * mais barato: (50, 40, 30) e (20, 10, 10) -> gratis o 30 e o 10.
     */
    public function test_agrupamento_respeita_a_ordem_dentro_de_cada_trio(): void
    {
        $carrinho = $this->carrinho([
            ['sku' => 'A', 'qtd' => 1, 'preco' => 5000],
            ['sku' => 'B', 'qtd' => 1, 'preco' => 4000],
            ['sku' => 'C', 'qtd' => 1, 'preco' => 3000],
            ['sku' => 'D', 'qtd' => 1, 'preco' => 2000],
            ['sku' => 'E', 'qtd' => 2, 'preco' => 1000],
        ]);

        self::assertSame(4000, $this->buyXGetY($carrinho)->cents);
    }

    /**
     * O recorte por componente agora e feito pelo MOTOR, nao pela acao.
     * A acao recebe o escopo ja filtrado e nao precisa saber de categorias.
     */
    public function test_escopo_restrito_ignora_os_demais_componentes(): void
    {
        $carrinho = new CartContext(
            items: [
                new CartItem(id: 1, sku: 'CAMISA', quantity: 3, components: [
                    new PriceComponent('base', Money::fromCents(4000)),
                ]),
                new CartItem(id: 2, sku: 'CANECA', quantity: 5, components: [
                    new PriceComponent('brinde', Money::fromCents(1000)),
                ]),
            ],
            shippingCost: Money::zero(),
        );

        $definition = new ActionDefinition(
            type: 'buy_x_get_y',
            value: 0,
            target: ActionTarget::Components,
            meta: ['buy' => 2, 'free' => 1],
        );

        // So as 3 camisas entram no escopo: 1 grupo, 1 brinde de R$ 40.
        $desconto = (new BuyXGetYDiscount())
            ->calculate($definition, $carrinho, DiscountScope::forCart($carrinho, ['base']))
            ->total();

        self::assertSame(4000, $desconto->cents);
    }

    public function test_leve_2_pague_1(): void
    {
        $carrinho = $this->carrinho([
            ['sku' => 'A', 'qtd' => 3, 'preco' => 1000],
        ]);

        $desconto = $this->buyXGetY($carrinho, ['buy' => 1, 'free' => 1]);

        self::assertSame(1000, $desconto->cents);
    }

    public function test_teto_limita_o_valor_do_brinde(): void
    {
        $carrinho = $this->carrinho([
            ['sku' => 'A', 'qtd' => 3, 'preco' => 10000],
        ]);

        $definition = new ActionDefinition(
            type: 'buy_x_get_y',
            value: 0,
            target: ActionTarget::Items,
            maxDiscount: Money::fromCents(5000),
            meta: ['buy' => 2, 'free' => 1],
        );

        $desconto = (new BuyXGetYDiscount())
            ->calculate($definition, $carrinho, DiscountScope::forCart($carrinho))
            ->total();

        self::assertSame(5000, $desconto->cents);
    }

    // ------------------------------------------------------------------
    // Escalonado por faixa
    // ------------------------------------------------------------------

    public function test_aplica_a_faixa_alcancada(): void
    {
        $carrinho = $this->carrinho([['sku' => 'A', 'qtd' => 1, 'preco' => 15000]]);

        self::assertSame(750, $this->tiered($carrinho)->cents);
    }

    public function test_vale_a_faixa_mais_alta_e_nao_a_soma(): void
    {
        $carrinho = $this->carrinho([['sku' => 'A', 'qtd' => 1, 'preco' => 60000]]);

        // 15% de R$ 600 = R$ 90. Nao 5 + 10 + 15.
        self::assertSame(9000, $this->tiered($carrinho)->cents);
    }

    public function test_abaixo_da_primeira_faixa_nao_ha_desconto(): void
    {
        $carrinho = $this->carrinho([['sku' => 'A', 'qtd' => 1, 'preco' => 5000]]);

        self::assertSame(0, $this->tiered($carrinho)->cents);
    }

    public function test_faixas_fora_de_ordem_no_cadastro_funcionam_igual(): void
    {
        $carrinho = $this->carrinho([['sku' => 'A', 'qtd' => 1, 'preco' => 60000]]);

        $desordenadas = [
            ['min' => 30000, 'percent' => 10],
            ['min' => 50000, 'percent' => 15],
            ['min' => 10000, 'percent' => 5],
        ];

        self::assertSame(9000, $this->tiered($carrinho, ['tiers' => $desordenadas])->cents);
    }

    public function test_faixa_por_quantidade_em_vez_de_valor(): void
    {
        $carrinho = $this->carrinho([['sku' => 'A', 'qtd' => 12, 'preco' => 1000]]);

        $desconto = $this->tiered($carrinho, [
            'basis' => 'quantity',
            'tiers' => [
                ['min' => 5, 'percent' => 5],
                ['min' => 10, 'percent' => 20],
            ],
        ]);

        // 12 unidades alcancam a faixa de 10 -> 20% de R$ 120.
        self::assertSame(2400, $desconto->cents);
    }

    public function test_faixa_com_valor_fixo_em_vez_de_percentual(): void
    {
        $carrinho = $this->carrinho([['sku' => 'A', 'qtd' => 1, 'preco' => 40000]]);

        $desconto = $this->tiered($carrinho, [
            'tiers' => [
                ['min' => 10000, 'amount_cents' => 1500],
                ['min' => 30000, 'amount_cents' => 5000],
            ],
        ]);

        self::assertSame(5000, $desconto->cents);
    }

    public function test_sem_faixas_cadastradas_devolve_zero(): void
    {
        $carrinho = $this->carrinho([['sku' => 'A', 'qtd' => 1, 'preco' => 40000]]);

        self::assertSame(0, $this->tiered($carrinho, ['tiers' => []])->cents);
    }

    // ------------------------------------------------------------------

    /** @param  array<string,mixed>  $meta */
    private function buyXGetY(CartContext $carrinho, array $meta = []): Money
    {
        $definition = new ActionDefinition(
            type: 'buy_x_get_y',
            value: 0,
            target: ActionTarget::Items,
            meta: array_merge(['buy' => 2, 'free' => 1], $meta),
        );

        return (new BuyXGetYDiscount())
            ->calculate($definition, $carrinho, DiscountScope::forCart($carrinho))
            ->total();
    }

    /** @param  array<string,mixed>  $meta */
    private function tiered(CartContext $carrinho, array $meta = []): Money
    {
        $padrao = [
            'tiers' => [
                ['min' => 10000, 'percent' => 5],
                ['min' => 30000, 'percent' => 10],
                ['min' => 50000, 'percent' => 15],
            ],
        ];

        $definition = new ActionDefinition(
            type: 'tiered',
            value: 0,
            target: ActionTarget::Cart,
            meta: array_merge($padrao, $meta),
        );

        return (new TieredDiscount())
            ->calculate($definition, $carrinho, DiscountScope::forCart($carrinho))
            ->total();
    }

    /** @param  array<int,array<string,mixed>>  $linhas */
    private function carrinho(array $linhas): CartContext
    {
        $items = [];

        foreach ($linhas as $index => $linha) {
            $items[] = new CartItem(
                id: $index + 1,
                sku: (string) $linha['sku'],
                quantity: (int) $linha['qtd'],
                unitPrice: Money::fromCents((int) $linha['preco']),
            );
        }

        return new CartContext(items: $items, shippingCost: Money::zero());
    }
}
