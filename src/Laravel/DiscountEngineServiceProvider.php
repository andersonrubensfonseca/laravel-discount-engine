<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel;

use Illuminate\Support\ServiceProvider;
use SolutionsTI\DiscountEngine\Core\Contracts\ConditionEvaluator;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Contracts\RuleRepository;
use SolutionsTI\DiscountEngine\Core\Contracts\UsageTracker;
use SolutionsTI\DiscountEngine\Core\Engine\ConditionMatcher;
use SolutionsTI\DiscountEngine\Core\Engine\DiscountEngine;
use SolutionsTI\DiscountEngine\Core\Registry\ActionRegistry;
use SolutionsTI\DiscountEngine\Core\Registry\ConditionRegistry;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Laravel\Repositories\DatabaseUsageTracker;
use SolutionsTI\DiscountEngine\Laravel\Repositories\EloquentRuleRepository;
use SolutionsTI\DiscountEngine\Laravel\Repositories\RuleHydrator;

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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/discount-engine.php' => config_path('discount-engine.php'),
            ], 'discount-engine-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations' => database_path('migrations'),
            ], 'discount-engine-migrations');
        }

        $this->registerCacheInvalidation();
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
