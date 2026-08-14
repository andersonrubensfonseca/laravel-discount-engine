<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SolutionsTI\DiscountEngine\Core\Contracts\ConditionEvaluator;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Contracts\RuleRepository;
use SolutionsTI\DiscountEngine\Core\Contracts\UsageTracker;
use SolutionsTI\DiscountEngine\Core\Engine\ConditionMatcher;
use SolutionsTI\DiscountEngine\Core\Engine\DiscountEngine;
use SolutionsTI\DiscountEngine\Core\Registry\ActionRegistry;
use SolutionsTI\DiscountEngine\Core\Registry\ConditionRegistry;
use SolutionsTI\DiscountEngine\Laravel\Console\RaceTestCommand;
use SolutionsTI\DiscountEngine\Laravel\Console\RaceWorkerCommand;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Laravel\Repositories\DatabaseUsageTracker;
use SolutionsTI\DiscountEngine\Laravel\Repositories\EloquentRuleRepository;
use SolutionsTI\DiscountEngine\Laravel\Repositories\RuleHydrator;
use SolutionsTI\DiscountEngine\Laravel\Support\CartContextFactory;
use SolutionsTI\DiscountEngine\Laravel\Support\PanelFieldMap;
use SolutionsTI\DiscountEngine\Laravel\Support\RuleDefinitionValidator;

/**
 * Camada fina de amarracao. E o unico arquivo que precisa de atencao
 * de verdade quando o projeto migrar de Laravel 8 para 13.
 */
final class DiscountEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/discount-engine.php', 'discount-engine');

        $this->app->singleton(ConditionRegistry::class, function ($app): ConditionRegistry {
            $registry = new ConditionRegistry();

            foreach ((array) config('discount-engine.conditions', []) as $class) {
                $instance = $app->make($class);

                if ($instance instanceof ConditionEvaluator) {
                    $registry->register($instance);
                }
            }

            return $registry;
        });

        $this->app->singleton(ActionRegistry::class, function ($app): ActionRegistry {
            $registry = new ActionRegistry();

            foreach ((array) config('discount-engine.actions', []) as $class) {
                $instance = $app->make($class);

                if ($instance instanceof DiscountAction) {
                    $registry->register($instance);
                }
            }

            return $registry;
        });

        $this->app->singleton(RuleHydrator::class);

        $this->app->singleton(RuleRepository::class, fn ($app): RuleRepository => new EloquentRuleRepository(
            $app->make(RuleHydrator::class),
        ));

        $this->app->singleton(UsageTracker::class, DatabaseUsageTracker::class);

        $this->app->singleton(ConditionMatcher::class, fn ($app): ConditionMatcher => new ConditionMatcher(
            $app->make(ConditionRegistry::class),
        ));

        $this->app->singleton(DiscountEngine::class, function ($app): DiscountEngine {
            $cap = config('discount-engine.global_cap_percentage');

            return new DiscountEngine(
                rules: $app->make(RuleRepository::class),
                actions: $app->make(ActionRegistry::class),
                matcher: $app->make(ConditionMatcher::class),
                usage: $app->make(UsageTracker::class),
                globalCapPercentage: $cap === null ? null : (float) $cap,
            );
        });

        $this->app->singleton(DiscountManager::class);

        $this->app->singleton(RuleDefinitionValidator::class, fn ($app): RuleDefinitionValidator => new RuleDefinitionValidator(
            $app->make(ConditionRegistry::class),
            $app->make(ActionRegistry::class),
        ));

        $this->app->singleton(PanelFieldMap::class, fn ($app): PanelFieldMap => new PanelFieldMap(
            $app->make(ConditionRegistry::class),
            $app->make(ActionRegistry::class),
        ));

        $this->app->singleton(CartContextFactory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'discount-engine');
        $this->registerPanelRoutes();

        if ($this->app->runningInConsole()) {
            // Ferramenta de diagnostico: valida o lock sob concorrencia real.
            // Cria e apaga dados de teste, entao nao deve rodar em producao.
            $this->commands([
                RaceTestCommand::class,
                RaceWorkerCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../../config/discount-engine.php' => config_path('discount-engine.php'),
            ], 'discount-engine-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations' => database_path('migrations'),
            ], 'discount-engine-migrations');

            $this->publishes([
                __DIR__ . '/../../resources/views' => resource_path('views/vendor/discount-engine'),
            ], 'discount-engine-views');
        }

        $this->registerCacheInvalidation();
    }

    /**
     * As rotas do painel so existem se ele estiver habilitado.
     *
     * O middleware vem do config: por padrao apenas 'web', o que significa
     * SEM autenticacao. O painel exibe um aviso vermelho enquanto for esse
     * o caso — quem chega nele reescreve as regras de preco da loja.
     */
    private function registerPanelRoutes(): void
    {
        if (! config('discount-engine.panel.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => config('discount-engine.panel.prefix', 'admin/descontos'),
            'middleware' => config('discount-engine.panel.middleware', ['web']),
            'as' => 'discount-engine.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/panel.php');
        });
    }

    /**
     * Regra salva ou removida = cache furado.
     *
     * Sem isso, o time comercial edita uma campanha no painel e passa cinco
     * minutos achando que o sistema esta quebrado.
     */
    private function registerCacheInvalidation(): void
    {
        $flush = function (): void {
            $repository = $this->app->make(RuleRepository::class);

            if ($repository instanceof EloquentRuleRepository) {
                $repository->flushCache();
            }
        };

        DiscountRule::saved($flush);
        DiscountRule::deleted($flush);
    }
}
