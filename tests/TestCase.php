<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SolutionsTI\DiscountEngine\Laravel\DiscountEngineServiceProvider;

/**
 * Base dos testes de integracao.
 *
 * O Testbench sobe um app Laravel minimo em memoria — nao existe .env,
 * nao existe artisan, nao existe sandbox. A conexao e configurada aqui.
 *
 * Padrao: SQLite em memoria (rapido, zero setup).
 * Para rodar contra o MySQL de verdade:
 *
 *   set DB_CONNECTION=mysql
 *   set DB_DATABASE=discount_engine_test
 *   vendor\bin\phpunit
 *
 * Vale rodar nos dois: SQLite pega logica, MySQL pega diferenca de tipo
 * (coluna JSON, collation, foreign key) que o SQLite deixa passar.
 */
abstract class TestCase extends Orchestra
{
    /** @return array<int,class-string> */
    protected function getPackageProviders($app): array
    {
        return [DiscountEngineServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $connection = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', $connection);

        if ($connection === 'sqlite') {
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        } else {
            $app['config']->set('database.connections.' . $connection, [
                'driver' => $connection,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'discount_engine_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);
        }

        // Cache desligado por padrao: cada teste precisa enxergar o que
        // acabou de gravar. O teste de invalidacao liga explicitamente.
        $app['config']->set('discount-engine.cache.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
