<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Repositories;

use Illuminate\Support\Facades\Cache;
use SolutionsTI\DiscountEngine\Core\Contracts\RuleRepository;
use SolutionsTI\DiscountEngine\Core\Rule\Rule;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountCoupon;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;

/**
 * As regras automaticas sao lidas a cada mutacao do carrinho, entao vao para
 * cache. Os cupons NAO vao: sao consultados apenas quando o cliente digita um
 * codigo, e o estado de uso muda com frequencia.
 *
 * O cache guarda objetos Rule ja hidratados (serializaveis, sem Eloquent dentro).
 */
final class EloquentRuleRepository implements RuleRepository
{
    public function __construct(private readonly RuleHydrator $hydrator)
    {
    }

    /** @return array<int,Rule> */
    public function automaticRules(): array
    {
        if (! config('discount-engine.cache.enabled', true)) {
            return $this->fetchAutomaticRules();
        }

        return $this->cache()->remember(
            (string) config('discount-engine.cache.key', 'discount-engine.automatic-rules'),
            (int) config('discount-engine.cache.ttl', 300),
            fn (): array => $this->fetchAutomaticRules(),
        );
    }

    /**
     * @param  array<int,string>  $codes
     * @return array<int,Rule>
     */
    public function rulesForCoupons(array $codes): array
    {
        // Caixa alta na busca porque o mutator do model grava em caixa alta.
        // Isso torna o comportamento identico em MySQL e SQLite.
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $codes,
        ))));

        if ($normalized === []) {
            return [];
        }

        $coupons = DiscountCoupon::query()
            ->with('rule')
            ->where('active', true)
            ->whereIn('code', $normalized)
            ->get();

        $rules = [];

        foreach ($coupons as $coupon) {
            if ($coupon->rule === null || $coupon->isExpired()) {
                continue;
            }

            // Passamos o codigo digitado para que o AppliedDiscount saiba
            // qual cupom concedeu o desconto — essencial para o snapshot.
            $rules[] = $this->hydrator->hydrate($coupon->rule, $coupon->code);
        }

        return $rules;
    }

    public function flushCache(): void
    {
        $this->cache()->forget(
            (string) config('discount-engine.cache.key', 'discount-engine.automatic-rules'),
        );
    }

    /** @return array<int,Rule> */
    private function fetchAutomaticRules(): array
    {
        return DiscountRule::query()
            ->where('trigger', 'automatic')
            ->where('active', true)
            ->orderBy('priority')
            ->get()
            ->map(fn (DiscountRule $model): Rule => $this->hydrator->hydrate($model))
            ->all();
    }

    private function cache(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('discount-engine.cache.store');

        return $store === null ? Cache::store() : Cache::store($store);
    }
}
