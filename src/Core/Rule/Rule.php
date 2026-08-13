<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Rule;

use DateTimeImmutable;
use SolutionsTI\DiscountEngine\Core\Enums\CalculationBase;
use SolutionsTI\DiscountEngine\Core\Enums\CombinationMode;
use SolutionsTI\DiscountEngine\Core\Enums\TriggerType;

/**
 * A regra em si, ja hidratada a partir do banco pelo repositorio.
 *
 * Repare que nao ha nada de Eloquent aqui: e um objeto de dominio puro.
 */
final class Rule
{
    /** @param  array<int,ActionDefinition>  $actions */
    public function __construct(
        public readonly string|int $id,
        public readonly string $name,
        public readonly TriggerType $trigger,
        public readonly ConditionGroup $conditions,
        public readonly array $actions,
        public readonly ?string $couponCode = null,
        public readonly int $priority = 100,
        public readonly CombinationMode $combinationMode = CombinationMode::Stackable,
        public readonly ?string $exclusivityGroup = null,
        public readonly bool $stopFurtherProcessing = false,
        public readonly CalculationBase $calculationBase = CalculationBase::Current,
        public readonly bool $active = true,
        public readonly ?DateTimeImmutable $validFrom = null,
        public readonly ?DateTimeImmutable $validUntil = null,
    ) {
    }

    public function isWithinDateWindow(DateTimeImmutable $now): bool
    {
        if ($this->validFrom !== null && $now < $this->validFrom) {
            return false;
        }

        if ($this->validUntil !== null && $now > $this->validUntil) {
            return false;
        }

        return true;
    }

    public function requiresCoupon(): bool
    {
        return $this->trigger === TriggerType::Coupon;
    }

    public function isExclusive(): bool
    {
        return $this->combinationMode === CombinationMode::Exclusive;
    }
}
