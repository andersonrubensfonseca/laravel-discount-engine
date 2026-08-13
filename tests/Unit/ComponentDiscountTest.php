<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolutionsTI\DiscountEngine\Core\Actions\ComponentUnitPriceDiscount;
use SolutionsTI\DiscountEngine\Core\Actions\PercentageDiscount;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\PriceComponent;
use SolutionsTI\DiscountEngine\Core\Enums\ActionTarget;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/**
 * Os cenarios de produto customizavel: camisa + estamparia.
 *
 * O preco de uma camisa estampada nao e um numero unico. Sem decompor em
 * componentes nao ha como dar desconto so na estampa, nem emitir nota
 * fiscal com o desconto no lugar certo.
 */
final class ComponentDiscountTest extends TestCase
{
    public function test_item_composto_soma_os_componentes_no_preco_unitario(): void
    {
        $camisa = $this->camisaComEstampas(estampas: 2);

        // R$ 40 da peca + 2 x R$ 15 de estamparia = R$ 70.
        self::assertSame(7000, $camisa->unitPrice->cents);
        self::assertTrue($camisa->isComposed());
    }

    public function test_item_simples_continua_funcionando_sem_componentes(): void
    {
        $item = new CartItem(id: 1, sku: 'LISA', quantity: 2, unitPrice: Money::fromCents(4000));

        self::assertSame(8000, $item->subtotal()->cents);
        self::assertCount(1, $item->components);
        self::assertSame('base', $item->components[0]->type);
        self::assertFalse($item->isComposed());
    }

    public function test_unit_price_divergente_dos_componentes_estoura(): void
    {
        $this->expectExceptionMessageMatches('/nao bate com a soma dos componentes/');

        new CartItem(
            id: 1,
            sku: 'CAMISA',
            quantity: 1,
            unitPrice: Money::fromCents(9999),
            components: [
                new PriceComponent('base', Money::fromCents(4000)),
                new PriceComponent('print', Money::fromCents(1500)),
            ],
        );
    }

    /**
     * O caso central: 20% so na estamparia, sem encostar no preco da peca.
     */
    public function test_desconto_incide_so_na_estamparia(): void
    {
        $carrinho = $this->carrinho([$this->camisaComEstampas(estampas: 2)]);

        $definition = new ActionDefinition(
            type: 'percentage',
            value: 20,
            target: ActionTarget::Components,
            meta: ['component_types' => ['print']],
        );

        $allocation = (new PercentageDiscount())->calculate(
            $definition,
            $carrinho,
            DiscountScope::forCart($carrinho, ['print']),
        );

        // 20% de R$ 30 (as duas estampas) = R$ 6. A peca de R$ 40 fica intacta.
        self::assertSame(600, $allocation->total()->cents);

        $porTipo = $allocation->byComponentType();
        self::assertSame(600, $porTipo['print']->cents);
        self::assertArrayNotHasKey('base', $porTipo);
    }

    public function test_desconto_pode_incidir_so_na_peca_e_poupar_a_estampa(): void
    {
        $carrinho = $this->carrinho([$this->camisaComEstampas(estampas: 2)]);

        $definition = new ActionDefinition(
            type: 'percentage',
            value: 50,
            target: ActionTarget::Components,
            meta: ['component_types' => ['base']],
        );

        $allocation = (new PercentageDiscount())->calculate(
            $definition,
            $carrinho,
            DiscountScope::forCart($carrinho, ['base']),
        );

        self::assertSame(2000, $allocation->total()->cents);
        self::assertSame(2000, $allocation->byComponentType()['base']->cents);
    }

    /**
     * "A primeira estampa de cada camisa sai a R$ 1,99."
     *
     * 1 camisa, 1 estampa de R$ 15 -> desconto de R$ 13,01.
     */
    public function test_primeira_estampa_a_um_real_e_noventa_e_nove(): void
    {
        $carrinho = $this->carrinho([$this->camisaComEstampas(estampas: 1)]);

        self::assertSame(1301, $this->primeiraEstampaPromocional($carrinho)->cents);
    }

    /**
     * 3 estampas na mesma camisa: so a primeira entra na promocao,
     * as outras duas ficam a preco cheio.
     */
    public function test_com_tres_estampas_so_a_primeira_e_promocional(): void
    {
        $carrinho = $this->carrinho([$this->camisaComEstampas(estampas: 3)]);

        // Uma unica estampa descontada: 1500 - 199 = 1301.
        self::assertSame(1301, $this->primeiraEstampaPromocional($carrinho)->cents);
    }

    /**
     * A decisao que voce tomou: a cota e POR CAMISA.
     *
     * 2 camisas com 3 estampas cada = 2 estampas promocionais, nao 1.
     */
    public function test_cota_por_camisa_multiplica_com_a_quantidade(): void
    {
        $carrinho = $this->carrinho([$this->camisaComEstampas(estampas: 3, quantidade: 2)]);

        self::assertSame(2602, $this->primeiraEstampaPromocional($carrinho)->cents);
    }

    public function test_modo_line_da_uma_cota_por_linha_do_carrinho(): void
    {
        $carrinho = $this->carrinho([$this->camisaComEstampas(estampas: 3, quantidade: 2)]);

        $desconto = $this->primeiraEstampaPromocional($carrinho, ['per' => 'line']);

        // Uma unica estampa para a linha inteira, mesmo com 2 camisas.
        self::assertSame(1301, $desconto->cents);
    }

    public function test_modo_cart_da_uma_cota_para_o_pedido_inteiro(): void
    {
        $carrinho = $this->carrinho([
            $this->camisaComEstampas(estampas: 2, id: 1),
            $this->camisaComEstampas(estampas: 2, id: 2),
        ]);

        $desconto = $this->primeiraEstampaPromocional($carrinho, ['per' => 'cart']);

        self::assertSame(1301, $desconto->cents);
    }

    public function test_regra_nunca_encarece_componente_mais_barato_que_a_promocao(): void
    {
        $carrinho = $this->carrinho([
            new CartItem(id: 1, sku: 'CAMISA', quantity: 1, components: [
                new PriceComponent('base', Money::fromCents(4000)),
                new PriceComponent('print', Money::fromCents(100)),
            ]),
        ]);

        // A estampa custa R$ 1,00 e a promocao e R$ 1,99: nao ha desconto.
        self::assertSame(0, $this->primeiraEstampaPromocional($carrinho)->cents);
    }

    /**
     * O ganho que motivou o refactor: a alocacao diz exatamente de onde
     * saiu cada centavo. E isso que a nota fiscal precisa.
     */
    public function test_alocacao_identifica_item_e_componente(): void
    {
        $carrinho = $this->carrinho([
            $this->camisaComEstampas(estampas: 2, id: 'CAMISA-1'),
            $this->camisaComEstampas(estampas: 1, id: 'CAMISA-2'),
        ]);

        $definition = new ActionDefinition(
            type: 'percentage',
            value: 10,
            target: ActionTarget::Components,
            meta: ['component_types' => ['print']],
        );

        $allocation = (new PercentageDiscount())->calculate(
            $definition,
            $carrinho,
            DiscountScope::forCart($carrinho, ['print']),
        );

        $porItem = $allocation->byItem();

        // Camisa 1 tem 2 estampas (R$ 30), camisa 2 tem 1 (R$ 15).
        // 10% do total (R$ 45) = R$ 4,50, rateado 300 / 150.
        self::assertSame(300, $porItem['CAMISA-1']->cents);
        self::assertSame(150, $porItem['CAMISA-2']->cents);
        self::assertSame(450, $allocation->total()->cents);

        // A soma das partes bate com o total: sem centavo perdido.
        self::assertSame(
            $allocation->total()->cents,
            array_sum(array_map(static fn (Money $m): int => $m->cents, $porItem)),
        );
    }

    // ------------------------------------------------------------------

    private function camisaComEstampas(
        int $estampas,
        int $quantidade = 1,
        string|int $id = 1,
    ): CartItem {
        return new CartItem(
            id: $id,
            sku: 'CAMISA-ESTAMPADA',
            quantity: $quantidade,
            components: [
                new PriceComponent('base', Money::fromCents(4000)),
                new PriceComponent('print', Money::fromCents(1500), quantity: $estampas),
            ],
        );
    }

    /** @param  array<int,CartItem>  $items */
    private function carrinho(array $items): CartContext
    {
        return new CartContext(items: $items, shippingCost: Money::zero());
    }

    /** @param  array<string,mixed>  $meta */
    private function primeiraEstampaPromocional(CartContext $carrinho, array $meta = []): Money
    {
        $definition = new ActionDefinition(
            type: 'component_unit_price',
            value: 0,
            target: ActionTarget::Components,
            meta: array_merge([
                'component_types' => ['print'],
                'first_n' => 1,
                'unit_price_cents' => 199,
            ], $meta),
        );

        return (new ComponentUnitPriceDiscount())
            ->calculate($definition, $carrinho, DiscountScope::forCart($carrinho, ['print']))
            ->total();
    }
}
