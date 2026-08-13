<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountCoupon;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountUsage;

/**
 * Prova o que o PHPUnit nao consegue provar.
 *
 * PHPUnit e single-threaded: os testes de integracao verificam a logica
 * sequencial, mas nao que o lockForUpdate segura sob disputa real. Aqui
 * sao N processos PHP independentes, cada um com sua propria conexao,
 * todos tentando consumir o mesmo cupom no mesmo instante.
 *
 * Rodar contra MySQL/InnoDB. SQLite trava o arquivo inteiro e o resultado
 * nao diz nada sobre o comportamento de producao.
 *
 *   php artisan discount:race-test --workers=20 --limit=1
 */
final class RaceTestCommand extends Command
{
    protected $signature = 'discount:race-test
        {--workers=20 : Quantos processos concorrentes disparar}
        {--limit=1 : Limite de uso do cupom}
        {--code=RACETEST : Codigo do cupom de teste}
        {--delay=3 : Segundos ate o portao de largada}
        {--show-workers : Imprime a saida crua de cada worker}';

    protected $description = 'Simula N clientes disputando o mesmo cupom para validar o lock.';

    public function handle(): int
    {
        $workers = max(2, (int) $this->option('workers'));
        $limit = max(1, (int) $this->option('limit'));
        $code = strtoupper(trim((string) $this->option('code')));
        $delay = max(1, (int) $this->option('delay'));

        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->warn("Conexao atual: [{$driver}].");
            $this->warn('Este teste so tem valor em MySQL/InnoDB. O SQLite serializa tudo no arquivo.');

            if (! $this->confirm('Continuar mesmo assim?', false)) {
                return 1;
            }
        }

        $this->prepare($code, $limit);

        // Isolamento: qualquer regra automatica ativa contamina a medicao.
        // Um carrinho com 10% automatico tem desconto mesmo quando o cupom
        // e barrado — e reserve() retorna true por ter reservado a OUTRA regra.
        $silenced = $this->silenceAutomaticRules();

        if ($silenced !== []) {
            $this->line(count($silenced) . ' regra(s) automatica(s) desativada(s) durante o teste.');
        }

        $this->line("Cupom [{$code}] com limite {$limit}. Disparando {$workers} processos...");

        $startAt = microtime(true) + $delay;
        $processes = [];

        try {
            foreach (range(1, $workers) as $index) {
                $processes[$index] = $this->spawn($code, $startAt, $index);
            }

            $results = $this->collect($processes);
        } finally {
            $this->restoreAutomaticRules($silenced);
        }

        if ($this->option('show-workers')) {
            $this->newLine();
            $this->line('--- saida crua dos workers ---');

            foreach ($results as $result) {
                $this->line(json_encode($result));
            }
        }

        return $this->report($code, $limit, $workers, $results);
    }

    private function prepare(string $code, int $limit): void
    {
        $existing = DiscountCoupon::query()->where('code', $code)->first();

        if ($existing !== null) {
            DiscountUsage::query()->where('coupon_id', $existing->id)->delete();
            DiscountRule::query()->whereKey($existing->rule_id)->delete();
        }

        DiscountUsage::query()->where('order_id', 'like', 'RACE-%')->delete();

        $rule = DiscountRule::create([
            'name' => 'Regra de teste de concorrencia',
            'trigger' => 'coupon',
            'conditions' => [],
            'actions' => [
                ['type' => 'fixed_amount', 'value' => 5000, 'target' => 'cart'],
            ],
        ]);

        DiscountCoupon::create([
            'rule_id' => $rule->id,
            'code' => $code,
            'usage_limit' => $limit,
        ]);
    }

    /**
     * Desativa regras automaticas ativas e devolve os ids para restaurar depois.
     *
     * @return array<int,int>
     */
    private function silenceAutomaticRules(): array
    {
        $ids = DiscountRule::query()
            ->where('trigger', 'automatic')
            ->where('active', true)
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            DiscountRule::query()->whereKey($id)->update(['active' => false]);
        }

        // O update em massa nao dispara o evento saved, entao o cache
        // do repositorio precisa ser furado na mao.
        $this->flushRuleCache();

        return $ids;
    }

    /** @param  array<int,int>  $ids */
    private function restoreAutomaticRules(array $ids): void
    {
        foreach ($ids as $id) {
            DiscountRule::query()->whereKey($id)->update(['active' => true]);
        }

        $this->flushRuleCache();
    }

    private function flushRuleCache(): void
    {
        $repository = app(\SolutionsTI\DiscountEngine\Core\Contracts\RuleRepository::class);

        if ($repository instanceof \SolutionsTI\DiscountEngine\Laravel\Repositories\EloquentRuleRepository) {
            $repository->flushCache();
        }
    }

    /** @return array{proc:resource,pipes:array} */
    private function spawn(string $code, float $startAt, int $index): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'discount:race-worker',
            $code,
            sprintf('%.4f', $startAt),
            (string) $index,
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            $this->error("Falha ao iniciar o worker {$index}.");
            exit(1);
        }

        return ['proc' => $process, 'pipes' => $pipes];
    }

    /**
     * @param  array<int,array{proc:resource,pipes:array}>  $processes
     * @return array<int,array<string,mixed>>
     */
    private function collect(array $processes): array
    {
        $results = [];

        foreach ($processes as $index => $handle) {
            $stdout = stream_get_contents($handle['pipes'][1]);
            $stderr = stream_get_contents($handle['pipes'][2]);

            fclose($handle['pipes'][1]);
            fclose($handle['pipes'][2]);
            proc_close($handle['proc']);

            $decoded = null;

            foreach (explode("\n", (string) $stdout) as $line) {
                $line = trim($line);

                if ($line !== '' && str_starts_with($line, '{')) {
                    $decoded = json_decode($line, true);
                }
            }

            $results[$index] = $decoded ?? [
                'index' => $index,
                'saw_discount' => null,
                'status' => 'crashed',
                'error' => trim((string) $stderr) ?: 'Sem saida do worker.',
            ];
        }

        return $results;
    }

    /** @param  array<int,array<string,mixed>>  $results */
    private function report(string $code, int $limit, int $workers, array $results): int
    {
        $counts = ['reserved' => 0, 'refused' => 0, 'skipped' => 0, 'error' => 0, 'crashed' => 0];

        foreach ($results as $result) {
            $status = (string) $result['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $coupon = DiscountCoupon::query()->where('code', $code)->first();
        $usedCount = (int) $coupon->used_count;
        $usageRows = DiscountUsage::query()->where('coupon_id', $coupon->id)->count();

        $this->newLine();
        $this->table(
            ['Metrica', 'Valor', 'Esperado'],
            [
                ['Processos disparados', $workers, $workers],
                ['Reservas aceitas', $counts['reserved'], $limit],
                ['Reservas recusadas', $counts['refused'], '-'],
                ['Nao viram o desconto', $counts['skipped'], '-'],
                ['Erros', $counts['error'], 0],
                ['Processos quebrados', $counts['crashed'], 0],
                ['coupon.used_count', $usedCount, $limit],
                ['Linhas em discount_usages', $usageRows, $limit],
            ],
        );

        foreach ($results as $result) {
            if (in_array($result['status'], ['error', 'crashed'], true)) {
                $this->error("Worker {$result['index']}: {$result['error']}");
            }
        }

        // Cruza o retorno de reserve() com o que existe no banco.
        $lying = 0;

        foreach ($results as $result) {
            if ($result['status'] === 'reserved' && (int) ($result['persisted'] ?? 0) === 0) {
                $lying++;
            }
        }

        if ($lying > 0) {
            $this->warn("{$lying} worker(s) retornaram 'reserved' sem gravar nada no banco.");
            $this->warn('Isso indica versao antiga do UsageReserver no vendor, nao falha de lock.');
        }

        $ok = $counts['reserved'] === $limit
            && $usedCount === $limit
            && $usageRows === $limit
            && $counts['error'] === 0
            && $counts['crashed'] === 0;

        $this->newLine();

        if ($ok) {
            $this->info("OK — exatamente {$limit} reserva(s) passaram entre {$workers} processos simultaneos.");
            $this->line('O lock segurou. Nenhum cupom vazou.');

            return 0;
        }

        $this->error('FALHOU — os numeros nao fecham.');
        $this->line('Se "Reservas aceitas" ou "used_count" ficou acima do limite, o lock nao esta segurando.');
        $this->line('Se apareceram muitos "skipped", aumente --delay: os workers nao chegaram a tempo do portao.');

        return 1;
    }
}
