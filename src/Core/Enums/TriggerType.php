<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Enums;

/** Como a regra entra em jogo: digitada pelo cliente ou avaliada sozinha. */
enum TriggerType: string
{
    case Coupon = 'coupon';
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Coupon => 'Por cupom',
            self::Automatic => 'Automatico',
        };
    }
}
