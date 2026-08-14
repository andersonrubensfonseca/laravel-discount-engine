<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Rule;

use DateTimeImmutable;
use SolutionsTI\DiscountEngine\Core\Enums\CalculationBase;
use SolutionsTI\DiscountEngine\Core\Enums\CombinationMode;
use SolutionsTI\DiscountEngine\Core\Enums\ResolutionStrategy;
use SolutionsTI\DiscountEngine\Core\Enums\TriggerType;

/**
 * A regra em si, ja hidratada a partir do banco pelo repositorio.
 *
 * Tres mecanismos controlam acumulo, do mais amplo ao mais fino:
 *
 *   combinationMode = Exclusive
 *     Se esta regra aplicar, ela e a UNICA do pedido. Descarta o que veio
 *     antes e bloqueia o que viria depois.
 *
 *   resolutionGroup + resolutionStrategy
 *     Regras do mesmo grupo competem entre si; regras de grupos diferentes
 *     (ou sem grupo) continuam acumulando normalmente.
 *
 *   stopFurtherProcessing
 *     Mantem o que ja foi aplicado, mas encerra o pipeline.
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
        public readonly ?string $resolutionGroup = null,
        public readonly ResolutionStrategy $resolutionStrategy = ResolutionStrategy::FirstByPriority,
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

    /** O grupo desta regra decide pelo maior desconto? */
    public function usesBestOfferResolution(): bool
    {
        return $this->resolutionGroup !== null
            && $this->resolutionStrategy === ResolutionStrategy::HighestDiscount;
    }
}
