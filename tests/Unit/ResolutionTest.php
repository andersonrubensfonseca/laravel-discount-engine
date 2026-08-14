<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolutionsTI\DiscountEngine\Core\Actions\PercentageDiscount;
use SolutionsTI\DiscountEngine\Core\Conditions\CartSubtotalCondition;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Engine\ConditionMatcher;
use SolutionsTI\DiscountEngine\Core\Engine\DiscountEngine;
use SolutionsTI\DiscountEngine\Core\Enums\ActionTarget;
use SolutionsTI\DiscountEngine\Core\Enums\CombinationMode;
use SolutionsTI\DiscountEngine\Core\Enums\RejectionReason;
use SolutionsTI\DiscountEngine\Core\Enums\ResolutionStrategy;
use SolutionsTI\DiscountEngine\Core\Enums\TriggerType;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Registry\ActionRegistry;
use SolutionsTI\DiscountEngine\Core\Registry\ConditionRegistry;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionGroup;
use SolutionsTI\DiscountEngine\Core\Rule\Rule;
use SolutionsTI\DiscountEngine\Tests\Support\ArrayRuleRepository;

/**
 * Os tres niveis de controle de acumulo.
 */
final class ResolutionTest extends TestCase
{
    // ------------------------------------------------------------------
    // Exclusividade
    // ------------------------------------------------------------------

    /**
     * Regressao do bug corrigido na v0.4.
     *
     * Antes, `exclusive` so bloqueava as regras SEGUINTES. Uma acumulavel
     * de prioridade menor ja tinha aplicado e continuava valendo — o
     * cliente levava as duas. "Exclusivo" tem que significar sozinho.
     */
    public function test_exclusiva_descarta_o_que_ja_tinha_sido_aplicado(): void
    {
        $acumulavel = $this->regra(id: 1, nome: 'Acumulavel 10%', percentual: 10, prioridade: 10);

        $exclusiva = $this->regra(
            id: 2,
            nome: 'Exclusiva 20%',
            percentual: 20,
            prioridade: 20,
            modo: CombinationMode::Exclusive,
        );

        $resultado = $this->motor([$acumulavel, $exclusiva])->evaluate($this->carrinho(10000));

        // So a exclusiva vale: R$ 20. Antes da correcao dava R$ 28
        // (1000 da acumulavel + 20% sobre os 9000 restantes).
        self::assertSame(2000, $resultado->itemsDiscount()->cents);
        self::assertCount(1, $resultado->applied);
        self::assertSame('Exclusiva 20%', $resultado->applied[0]->ruleName);

        $descartada = $this->rejeicaoDe($resultado, 1);
        self::assertSame(RejectionReason::SupersededByExclusiveRule, $descartada->reason);
    }

    /**
     * A exclusiva precisa calcular contra base LIMPA.
     *
     * Se calculasse sobre o saldo ja reduzido pela regra anterior, daria
     * 20% de R$ 90 = R$ 18 em vez dos R$ 20 devidos.
     */
    public function test_exclusiva_calcula_sobre_o_valor_cheio(): void
    {
        $resultado = $this->motor([
            $this->regra(id: 1, nome: 'Antes', percentual: 10, prioridade: 10),
            $this->regra(id: 2, nome: 'Exclusiva', percentual: 20, prioridade: 20, modo: CombinationMode::Exclusive),
        ])->evaluate($this->carrinho(10000));

        self::assertSame(2000, $resultado->itemsDiscount()->cents);
    }

    public function test_exclusiva_que_nao_bate_nao_descarta_nada(): void
    {
        $exclusiva = $this->regra(
            id: 2,
            nome: 'Exclusiva para carrinho grande',
            percentual: 30,
            prioridade: 20,
            modo: CombinationMode::Exclusive,
            condicoes: new ConditionGroup(children: [
                new \SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition(
                    'cart_subtotal',
                    \SolutionsTI\DiscountEngine\Core\Enums\Operator::GreaterThanOrEqual,
                    99999,
                ),
            ]),
        );

        $resultado = $this->motor([
            $this->regra(id: 1, nome: 'Acumulavel', percentual: 10, prioridade: 10),
            $exclusiva,
        ])->evaluate($this->carrinho(10000));

        self::assertSame(1000, $resultado->itemsDiscount()->cents);
        self::assertCount(1, $resultado->applied);
    }

    // ------------------------------------------------------------------
    // Grupos: primeira por prioridade
    // ------------------------------------------------------------------

    public function test_grupo_por_prioridade_mantem_a_primeira(): void
    {
        $resultado = $this->motor([
            $this->regra(id: 1, nome: 'Cinco', percentual: 5, prioridade: 10, grupo: 'promos'),
            $this->regra(id: 2, nome: 'Vinte', percentual: 20, prioridade: 20, grupo: 'promos'),
        ])->evaluate($this->carrinho(10000));

        // A armadilha documentada: prioridade decide, nao valor. Quem
        // cadastrou a de 5% com prioridade menor entregou o pior negocio.
        self::assertSame(500, $resultado->itemsDiscount()->cents);
        self::assertSame(RejectionReason::ExclusivityConflict, $this->rejeicaoDe($resultado, 2)->reason);
    }

    public function test_grupos_diferentes_continuam_acumulando(): void
    {
        $resultado = $this->motor([
            $this->regra(id: 1, nome: 'Grupo A', percentual: 10, prioridade: 10, grupo: 'a'),
            $this->regra(id: 2, nome: 'Grupo B', percentual: 10, prioridade: 20, grupo: 'b'),
        ])->evaluate($this->carrinho(10000));

        // 1000 + 10% dos 9000 restantes = 1900.
        self::assertSame(1900, $resultado->itemsDiscount()->cents);
        self::assertCount(2, $resultado->applied);
    }

    // ------------------------------------------------------------------
    // Grupos: vale o melhor
    // ------------------------------------------------------------------

    public function test_melhor_oferta_vence_independente_da_prioridade(): void
    {
        $resultado = $this->motor([
            $this->regra(id: 1, nome: 'Cinco', percentual: 5, prioridade: 10, grupo: 'promos', estrategia: ResolutionStrategy::HighestDiscount),
            $this->regra(id: 2, nome: 'Vinte', percentual: 20, prioridade: 20, grupo: 'promos', estrategia: ResolutionStrategy::HighestDiscount),
        ])->evaluate($this->carrinho(10000));

        self::assertSame(2000, $resultado->itemsDiscount()->cents);
        self::assertSame('Vinte', $resultado->applied[0]->ruleName);
        self::assertSame(RejectionReason::SupersededByBetterOffer, $this->rejeicaoDe($resultado, 1)->reason);

        // Regressao: o vencedor nao pode aparecer entre as rejeitadas, e a
        // perdedora nao pode aparecer duas vezes.
        self::assertCount(1, $resultado->rejected);
    }

    public function test_melhor_oferta_com_tres_candidatas(): void
    {
        $resultado = $this->motor([
            $this->regra(id: 1, nome: 'A', percentual: 8, prioridade: 10, grupo: 'p', estrategia: ResolutionStrategy::HighestDiscount),
            $this->regra(id: 2, nome: 'B', percentual: 25, prioridade: 20, grupo: 'p', estrategia: ResolutionStrategy::HighestDiscount),
            $this->regra(id: 3, nome: 'C', percentual: 12, prioridade: 30, grupo: 'p', estrategia: ResolutionStrategy::HighestDiscount),
        ])->evaluate($this->carrinho(10000));

        self::assertSame(2500, $resultado->itemsDiscount()->cents);
        self::assertCount(1, $resultado->applied);

        // Duas perdedoras, uma rejeicao cada. Antes da correcao davam 4:
        // as do resolveBestOffer mais as do filtro de grupo ja resolvido.
        self::assertCount(2, $resultado->rejected);

        foreach ($resultado->rejected as $rejeitada) {
            self::assertNotSame('B', $rejeitada->ruleName);
        }
    }

    public function test_empate_fica_com_a_de_menor_prioridade(): void
    {
        $resultado = $this->motor([
            $this->regra(id: 1, nome: 'Primeira', percentual: 10, prioridade: 10, grupo: 'p', estrategia: ResolutionStrategy::HighestDiscount),
            $this->regra(id: 2, nome: 'Segunda', percentual: 10, prioridade: 20, grupo: 'p', estrategia: ResolutionStrategy::HighestDiscount),
        ])->evaluate($this->carrinho(10000));

        self::assertSame('Primeira', $resultado->applied[0]->ruleName);
    }

    public function test_candidata_que_nao_atende_as_condicoes_nao_disputa(): void
    {
        $inelegivel = $this->regra(
            id: 2,
            nome: 'Trinta por cento para carrinho enorme',
            percentual: 30,
            prioridade: 20,
            grupo: 'p',
            estrategia: ResolutionStrategy::HighestDiscount,
            condicoes: new ConditionGroup(children: [
                new \SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition(
                    'cart_subtotal',
                    \SolutionsTI\DiscountEngine\Core\Enums\Operator::GreaterThanOrEqual,
                    99999,
                ),
            ]),
        );

        $resultado = $this->motor([
            $this->regra(id: 1, nome: 'Dez', percentual: 10, prioridade: 10, grupo: 'p', estrategia: ResolutionStrategy::HighestDiscount),
            $inelegivel,
        ])->evaluate($this->carrinho(10000));

        self::assertSame(1000, $resultado->itemsDiscount()->cents);
        self::assertSame(RejectionReason::ConditionsNotMet, $this->rejeicaoDe($resultado, 2)->reason);
    }

    public function test_melhor_oferta_convive_com_regra_fora_do_grupo(): void
    {
        $resultado = $this->motor([
            $this->regra(id: 1, nome: 'Livre', percentual: 10, prioridade: 5),
            $this->regra(id: 2, nome: 'Cinco', percentual: 5, prioridade: 10, grupo: 'p', estrategia: ResolutionStrategy::HighestDiscount),
            $this->regra(id: 3, nome: 'Vinte', percentual: 20, prioridade: 20, grupo: 'p', estrategia: ResolutionStrategy::HighestDiscount),
        ])->evaluate($this->carrinho(10000));

        // Livre tira 1000; a vencedora do grupo tira 20% dos 9000 = 1800.
        self::assertSame(2800, $resultado->itemsDiscount()->cents);
        self::assertCount(2, $resultado->applied);
    }

    // ------------------------------------------------------------------

    /** @param  array<int,Rule>  $regras */
    private function motor(array $regras): DiscountEngine
    {
        return new DiscountEngine(
            rules: new ArrayRuleRepository($regras),
            actions: new ActionRegistry([new PercentageDiscount()]),
            matcher: new ConditionMatcher(new ConditionRegistry([new CartSubtotalCondition()])),
        );
    }

    private function regra(
        int $id,
        string $nome,
        float $percentual,
        int $prioridade = 100,
        CombinationMode $modo = CombinationMode::Stackable,
        ?string $grupo = null,
        ResolutionStrategy $estrategia = ResolutionStrategy::FirstByPriority,
        ?ConditionGroup $condicoes = null,
    ): Rule {
        return new Rule(
            id: $id,
            name: $nome,
            trigger: TriggerType::Automatic,
            conditions: $condicoes ?? new ConditionGroup(),
            actions: [new ActionDefinition('percentage', $percentual, ActionTarget::Cart)],
            priority: $prioridade,
            combinationMode: $modo,
            resolutionGroup: $grupo,
            resolutionStrategy: $estrategia,
        );
    }

    private function carrinho(int $subtotal): CartContext
    {
        return new CartContext(
            items: [new CartItem(id: 1, sku: 'X', quantity: 1, unitPrice: Money::fromCents($subtotal))],
            shippingCost: Money::zero(),
        );
    }

    private function rejeicaoDe(
        \SolutionsTI\DiscountEngine\Core\Result\DiscountResult $resultado,
        int $ruleId,
    ): \SolutionsTI\DiscountEngine\Core\Result\RejectedRule {
        foreach ($resultado->rejected as $rejeitada) {
            if ($rejeitada->ruleId === $ruleId) {
                return $rejeitada;
            }
        }

        self::fail("Regra {$ruleId} nao aparece entre as rejeitadas.");
    }
}
