<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolutionsTI\DiscountEngine\Core\Money\Money;

final class MoneyTest extends TestCase
{
    public function test_converte_reais_para_centavos_sem_erro_de_ponto_flutuante(): void
    {
        self::assertSame(1990, Money::fromFloat(19.90)->cents);
        self::assertSame(10, Money::fromFloat(0.10)->cents);
        self::assertSame(30, Money::fromFloat(0.10)->add(Money::fromFloat(0.20))->cents);
    }

    public function test_percentual_arredonda_half_up(): void
    {
        // 10% de R$ 33,33 = 3,333 -> 3,33
        self::assertSame(333, Money::fromCents(3333)->percentage(10)->cents);

        // 10% de R$ 33,35 = 3,335 -> 3,34
        self::assertSame(334, Money::fromCents(3335)->percentage(10)->cents);
    }

    public function test_clamp_impede_desconto_maior_que_a_base(): void
    {
        $desconto = Money::fromCents(50000);
        $base = Money::fromCents(12000);

        self::assertSame(12000, $desconto->clampTo($base)->cents);
    }

    /**
     * O teste que mais importa: rateio nao pode perder nem inventar centavo.
     * Se a soma das fatias divergir do total, o pedido fecha com valor
     * diferente da soma dos itens e o financeiro descobre depois.
     */
    public function test_rateio_nunca_perde_centavos(): void
    {
        $total = Money::fromCents(1000);
        $fatias = $total->allocate([1, 1, 1]); // 3 partes iguais de 10,00

        self::assertSame(1000, array_sum(array_map(
            static fn (Money $m): int => $m->cents,
            $fatias,
        )));

        // 334 + 333 + 333: o centavo sobrando vai para a primeira fatia
        self::assertSame([334, 333, 333], array_map(
            static fn (Money $m): int => $m->cents,
            $fatias,
        ));
    }

    public function test_rateio_respeita_pesos_diferentes(): void
    {
        $total = Money::fromCents(10000);
        $fatias = $total->allocate([7000, 3000]);

        self::assertSame([7000, 3000], array_map(
            static fn (Money $m): int => $m->cents,
            $fatias,
        ));
    }

    public function test_rateio_com_muitos_itens_continua_fechando(): void
    {
        $total = Money::fromCents(9999);
        $pesos = array_fill(0, 7, 1);

        $soma = array_sum(array_map(
            static fn (Money $m): int => $m->cents,
            $total->allocate($pesos),
        ));

        self::assertSame(9999, $soma);
    }
}
