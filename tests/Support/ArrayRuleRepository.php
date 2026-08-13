<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Tests\Support;

use SolutionsTI\DiscountEngine\Core\Contracts\RuleRepository;
use SolutionsTI\DiscountEngine\Core\Enums\TriggerType;
use SolutionsTI\DiscountEngine\Core\Rule\Rule;

/**
 * Repositorio em memoria. Existe para provar o ponto central da arquitetura:
 * da para testar o motor inteiro sem banco, sem Laravel, sem bootstrap.
 */
final class ArrayRuleRepository implements RuleRepository
{
    /** @param  array<int,Rule>  $rules */
    public function __construct(private readonly array $rules = [])
    {
    }

    public function automaticRules(): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (Rule $r): bool => $r->trigger === TriggerType::Automatic,
        ));
    }

    public function rulesForCoupons(array $codes): array
    {
        $normalized = array_map(
            static fn (string $c): string => strtolower(trim($c)),
            $codes,
        );

        return array_values(array_filter(
            $this->rules,
            static fn (Rule $r): bool => $r->trigger === TriggerType::Coupon
                && $r->couponCode !== null
                && in_array(strtolower(trim($r->couponCode)), $normalized, true),
        ));
    }
}
