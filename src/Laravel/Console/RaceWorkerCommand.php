<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Console;

use Illuminate\Console\Command;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\CustomerContext;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Laravel\DiscountManager;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountUsage;
use Throwable;

/**
 * Um "cliente" da simulacao de concorrencia. Nunca chamado a mao.
 *
 * Cada worker e um processo PHP separado, portanto uma CONEXAO MySQL
 * separada — que e o que torna o teste valido. Threads na mesma conexao
 * nao disputariam lock nenhum.
 *
 * A ordem importa: calcula ANTES do portao, reserva DEPOIS. Isso reproduz
 * o cenario real — todo mundo viu o desconto na tela enquanto havia saldo,
 * e so entao apertou "finalizar".
 */
final class RaceWorkerCommand extends Command
{
    protected $signature = 'discount:race-worker {code} {startAt} {index}';

    protected $description = 'Worker interno da simulacao de concorrencia.';

    protected $hidden = true;

    public function handle(DiscountManager $manager): int
    {
        $code = (string) $this->argument('code');
        $startAt = (float) $this->argument('startAt');
        $index = (int) $this->argument('index');

        $cart = new CartContext(
            items: [new CartItem(
                id: 1,
                sku: 'RACE',
                quantity: 1,
                unitPrice: Money::fromCents(30000),
            )],
            shippingCost: Money::zero(),
            customer: new CustomerContext(id: $index, completedOrders: 5),
            couponCodes: [$code],
        );

        $result = $manager->calculate($cart);

        // O que importa nao e "o carrinho tem desconto", e "ESTE cupom entrou".
        // Sem essa distincao, uma regra automatica qualquer no carrinho faz o
        // worker reportar sucesso sem ter encostado no cupom.
        $sawDiscount = false;

        foreach ($result->applied as $applied) {
            if ($applied->couponCode !== null && strcasecmp($applied->couponCode, $code) === 0) {
                $sawDiscount = true;
                break;
            }
        }

        // Portao de largada: todos os processos disparam no mesmo instante.
        while (microtime(true) < $startAt) {
            usleep(200);
        }

        $status = 'skipped';
        $error = null;

        if ($sawDiscount) {
            try {
                $status = $manager->reserve($result, 'RACE-' . $index, $index)
                    ? 'reserved'
                    : 'refused';
            } catch (Throwable $e) {
                $status = 'error';
                $error = $e->getMessage();
            }
        }

        // Confere no banco o que de fato ficou gravado por este worker.
        // Se 'reserved' for true mas 'persisted' for 0, o retorno esta mentindo.
        // Conta apenas o uso DO CUPOM, ignorando outras regras do carrinho.
        $persisted = DiscountUsage::query()
            ->where('order_id', 'RACE-' . $index)
            ->whereNotNull('coupon_id')
            ->count();

        fwrite(STDOUT, json_encode([
            'index' => $index,
            'saw_discount' => $sawDiscount,
            'discount_cents' => $result->totalDiscount()->cents,
            'status' => $status,
            'persisted' => $persisted,
            'error' => $error,
        ]) . PHP_EOL);

        return 0;
    }
}
