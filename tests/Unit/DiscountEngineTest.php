<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolutionsTI\DiscountEngine\Core\Actions\FixedAmountDiscount;
use SolutionsTI\DiscountEngine\Core\Actions\FreeShippingDiscount;
use SolutionsTI\DiscountEngine\Core\Actions\PercentageDiscount;
use SolutionsTI\DiscountEngine\Core\Conditions\CartSubtotalCondition;
use SolutionsTI\DiscountEngine\Core\Conditions\CustomerGroupCondition;
use SolutionsTI\DiscountEngine\Core\Conditions\FirstPurchaseCondition;
use SolutionsTI\DiscountEngine\Core\Conditions\TotalQuantityCondition;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\CustomerContext;
use SolutionsTI\DiscountEngine\Core\Engine\ConditionMatcher;
use SolutionsTI\DiscountEngine\Core\Engine\DiscountEngine;
use SolutionsTI\DiscountEngine\Core\Enums\ActionTarget;
use SolutionsTI\DiscountEngine\Core\Enums\CalculationBase;
use SolutionsTI\DiscountEngine\Core\Enums\CombinationMode;
use SolutionsTI\DiscountEngine\Core\Enums\LogicOperator;
use SolutionsTI\DiscountEngine\Core\Enums\Operator;
use SolutionsTI\DiscountEngine\Core\Enums\RejectionReason;
use SolutionsTI\DiscountEngine\Core\Enums\TriggerType;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Registry\ActionRegistry;
use SolutionsTI\DiscountEngine\Core\Registry\ConditionRegistry;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionGroup;
use SolutionsTI\DiscountEngine\Core\Rule\Rule;
use SolutionsTI\DiscountEngine\Tests\Support\ArrayRuleRepository;

final class DiscountEngineTest extends TestCase
{
    public function test_regra_automatica_aplica_quando_subtotal_atinge_o_minimo(): void
    {
        $regra = $this->regraPercentual(
            id: 1,
            nome: '10% acima de R$ 200',
            percentual: 10,
            condicoes: new ConditionGroup(LogicOperator::All, [
                new ConditionDefinition('cart_subtotal', Operator::GreaterThanOrEqual, 20000),
            ]),
        );

        $resultado = $this->motor([$regra])->evaluate($this->carrinho(subtotalCentavos: 25000));

        self::assertSame(2500, $resultado->itemsDiscount->cents);
        self::assertSame(22500, $resultado->finalTotal()->cents);
        self::assertCount(1, $resultado->applied);
    }

    public function test_regra_nao_aplica_e_informa_o_motivo(): void
    {
        $regra = $this->regraPercentual(
            id: 1,
            nome: '10% acima de R$ 200',
            percentual: 10,
            condicoes: new ConditionGroup(LogicOperator::All, [
                new ConditionDefinition('cart_subtotal', Operator::GreaterThanOrEqual, 20000),
            ]),
        );

        $resultado = $this->motor([$regra])->evaluate($this->carrinho(subtotalCentavos: 15000));

        self::assertFalse($resultado->hasDiscount());
        self::assertCount(1, $resultado->rejected);
        self::assertSame(RejectionReason::ConditionsNotMet, $resultado->rejected[0]->reason);
    }

    public function test_cupom_so_entra_quando_o_codigo_e_informado(): void
    {
        $regra = new Rule(
            id: 7,
            name: 'Cupom BEMVINDO',
            trigger: TriggerType::Coupon,
            conditions: new ConditionGroup(),
            actions: [new ActionDefinition('fixed_amount', 5000, ActionTarget::Cart)],
            couponCode: 'BEMVINDO',
        );

        $semCupom = $this->motor([$regra])->evaluate($this->carrinho(subtotalCentavos: 30000));
        self::assertFalse($semCupom->hasDiscount());

        $comCupom = $this->motor([$regra])->evaluate(
            $this->carrinho(subtotalCentavos: 30000, cupons: ['bemvindo']),
        );

        self::assertSame(5000, $comCupom->itemsDiscount->cents);
        self::assertSame('BEMVINDO', $comCupom->applied[0]->couponCode);
    }

    /**
     * O caso que o time comercial mais erra ao cadastrar.
     * Base Original: 10% + 10% sobre R$ 100 = R$ 20.
     * Base Current:  10% + 10% sobre R$ 100 = R$ 19.
     */
    public function test_base_de_calculo_muda_o_resultado_do_acumulo(): void
    {
        $fabricar = fn (CalculationBase $base): array => [
            $this->regraPercentual(id: 1, nome: 'A', percentual: 10, prioridade: 10, base: $base),
            $this->regraPercentual(id: 2, nome: 'B', percentual: 10, prioridade: 20, base: $base),
        ];

        $original = $this->motor($fabricar(CalculationBase::Original))
            ->evaluate($this->carrinho(subtotalCentavos: 10000));

        $corrente = $this->motor($fabricar(CalculationBase::Current))
            ->evaluate($this->carrinho(subtotalCentavos: 10000));

        self::assertSame(2000, $original->itemsDiscount->cents);
        self::assertSame(1900, $corrente->itemsDiscount->cents);
    }

    public function test_regra_exclusiva_bloqueia_as_seguintes(): void
    {
        $exclusiva = $this->regraPercentual(
            id: 1,
            nome: 'Exclusiva 15%',
            percentual: 15,
            prioridade: 10,
            modo: CombinationMode::Exclusive,
        );

        $acumulavel = $this->regraPercentual(id: 2, nome: 'Acumulavel 5%', percentual: 5, prioridade: 20);

        $resultado = $this->motor([$exclusiva, $acumulavel])
            ->evaluate($this->carrinho(subtotalCentavos: 10000));

        self::assertSame(1500, $resultado->itemsDiscount->cents);
        self::assertCount(1, $resultado->applied);
        self::assertSame(RejectionReason::ExclusivityConflict, $resultado->rejected[0]->reason);
    }

    public function test_grupo_de_exclusividade_permite_apenas_uma_regra_por_grupo(): void
    {
        $freteA = $this->regraFrete(id: 1, nome: 'Frete gratis campanha', prioridade: 10, grupo: 'frete');
        $freteB = $this->regraFrete(id: 2, nome: 'Frete gratis fidelidade', prioridade: 20, grupo: 'frete');

        $resultado = $this->motor([$freteA, $freteB])
            ->evaluate($this->carrinho(subtotalCentavos: 10000, freteCentavos: 3000));

        self::assertSame(3000, $resultado->shippingDiscount->cents);
        self::assertSame(0, $resultado->finalShipping()->cents);
        self::assertCount(1, $resultado->applied);
    }

    public function test_prioridade_define_a_ordem_de_aplicacao(): void
    {
        $primeira = $this->regraPercentual(id: 1, nome: 'Primeira', percentual: 50, prioridade: 1);
        $segunda = $this->regraPercentual(id: 2, nome: 'Segunda', percentual: 50, prioridade: 2);

        $resultado = $this->motor([$segunda, $primeira])
            ->evaluate($this->carrinho(subtotalCentavos: 10000));

        self::assertSame('Primeira', $resultado->applied[0]->ruleName);
        self::assertSame(5000, $resultado->applied[0]->amount->cents);
        self::assertSame(2500, $resultado->applied[1]->amount->cents);
    }

    public function test_teto_global_limita_o_desconto_somado(): void
    {
        $regras = [
            $this->regraPercentual(id: 1, nome: 'A', percentual: 30, prioridade: 10, base: CalculationBase::Original),
            $this->regraPercentual(id: 2, nome: 'B', percentual: 30, prioridade: 20, base: CalculationBase::Original),
        ];

        $resultado = $this->motor($regras, tetoGlobal: 40.0)
            ->evaluate($this->carrinho(subtotalCentavos: 10000));

        self::assertSame(4000, $resultado->itemsDiscount->cents);
    }

    public function test_desconto_nunca_deixa_o_carrinho_negativo(): void
    {
        $regra = new Rule(
            id: 1,
            name: 'Cupom absurdo',
            trigger: TriggerType::Automatic,
            conditions: new ConditionGroup(),
            actions: [new ActionDefinition('fixed_amount', 999999, ActionTarget::Cart)],
        );

        $resultado = $this->motor([$regra])->evaluate($this->carrinho(subtotalCentavos: 5000));

        self::assertSame(5000, $resultado->itemsDiscount->cents);
        self::assertSame(0, $resultado->finalSubtotal()->cents);
        self::assertFalse($resultado->finalTotal()->isNegative());
    }

    public function test_desconto_e_rateado_entre_os_itens_sem_perder_centavos(): void
    {
        $carrinho = new CartContext(
            items: [
                new CartItem(id: 'A', sku: 'A', quantity: 1, unitPrice: Money::fromCents(3333)),
                new CartItem(id: 'B', sku: 'B', quantity: 1, unitPrice: Money::fromCents(3333)),
                new CartItem(id: 'C', sku: 'C', quantity: 1, unitPrice: Money::fromCents(3334)),
            ],
            shippingCost: Money::zero(),
        );

        $regra = $this->regraPercentual(id: 1, nome: '10%', percentual: 10);
        $resultado = $this->motor([$regra])->evaluate($carrinho);

        $soma = array_sum(array_map(
            static fn (Money $m): int => $m->cents,
            $resultado->itemAllocations,
        ));

        self::assertSame($resultado->itemsDiscount->cents, $soma);
        self::assertArrayHasKey('A', $resultado->itemAllocations);
    }

    public function test_condicoes_em_grupo_or_bastam_uma_verdadeira(): void
    {
        $regra = $this->regraPercentual(
            id: 1,
            nome: 'Primeira compra OU 5+ itens',
            percentual: 10,
            condicoes: new ConditionGroup(LogicOperator::Any, [
                new ConditionDefinition('first_purchase', Operator::Equals, true),
                new ConditionDefinition('total_quantity', Operator::GreaterThanOrEqual, 5),
            ]),
        );

        $carrinho = new CartContext(
            items: [new CartItem(id: 1, sku: 'X', quantity: 6, unitPrice: Money::fromCents(1000))],
            shippingCost: Money::zero(),
            customer: new CustomerContext(id: 99, completedOrders: 12),
        );

        self::assertTrue($this->motor([$regra])->evaluate($carrinho)->hasDiscount());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param  array<int,Rule>  $regras */
    private function motor(array $regras, ?float $tetoGlobal = null): DiscountEngine
    {
        $condicoes = new ConditionRegistry([
            new CartSubtotalCondition(),
            new TotalQuantityCondition(),
            new FirstPurchaseCondition(),
            new CustomerGroupCondition(),
        ]);

        $acoes = new ActionRegistry([
            new PercentageDiscount(),
            new FixedAmountDiscount(),
            new FreeShippingDiscount(),
        ]);

        return new DiscountEngine(
            rules: new ArrayRuleRepository($regras),
            actions: $acoes,
            matcher: new ConditionMatcher($condicoes),
            globalCapPercentage: $tetoGlobal,
        );
    }

    /** @param  array<int,string>  $cupons */
    private function carrinho(int $subtotalCentavos, int $freteCentavos = 0, array $cupons = []): CartContext
    {
        return new CartContext(
            items: [new CartItem(
                id: 1,
                sku: 'SKU-1',
                quantity: 1,
                unitPrice: Money::fromCents($subtotalCentavos),
            )],
            shippingCost: Money::fromCents($freteCentavos),
            couponCodes: $cupons,
        );
    }

    private function regraPercentual(
        int $id,
        string $nome,
        float $percentual,
        int $prioridade = 100,
        ?ConditionGroup $condicoes = null,
        CombinationMode $modo = CombinationMode::Stackable,
        CalculationBase $base = CalculationBase::Current,
    ): Rule {
        return new Rule(
            id: $id,
            name: $nome,
            trigger: TriggerType::Automatic,
            conditions: $condicoes ?? new ConditionGroup(),
            actions: [new ActionDefinition('percentage', $percentual, ActionTarget::Cart)],
            priority: $prioridade,
            combinationMode: $modo,
            calculationBase: $base,
        );
    }

    private function regraFrete(int $id, string $nome, int $prioridade, string $grupo): Rule
    {
        return new Rule(
            id: $id,
            name: $nome,
            trigger: TriggerType::Automatic,
            conditions: new ConditionGroup(),
            actions: [new ActionDefinition('free_shipping', 100, ActionTarget::Shipping)],
            priority: $prioridade,
            exclusivityGroup: $grupo,
        );
    }
}
