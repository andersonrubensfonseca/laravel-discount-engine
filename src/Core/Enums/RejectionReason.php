<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Enums;

/**
 * Por que uma regra candidata nao foi aplicada.
 * E o que alimenta o simulador do painel: o time comercial precisa
 * enxergar "quase bateu, faltou R$ 12" em vez de silencio.
 */
enum RejectionReason: string
{
    case Inactive = 'inactive';
    case OutsideDateWindow = 'outside_date_window';
    case ConditionsNotMet = 'conditions_not_met';
    case CouponNotProvided = 'coupon_not_provided';
    case UsageLimitReached = 'usage_limit_reached';
    case ExclusivityConflict = 'exclusivity_conflict';
    case StoppedByPreviousRule = 'stopped_by_previous_rule';
    case SupersededByExclusiveRule = 'superseded_by_exclusive';
    case SupersededByBetterOffer = 'superseded_by_better_offer';
    case NoDiscountValue = 'no_discount_value';

    public function label(): string
    {
        return match ($this) {
            self::Inactive => 'Regra desativada',
            self::OutsideDateWindow => 'Fora do periodo de vigencia',
            self::ConditionsNotMet => 'Carrinho nao atende as condicoes',
            self::CouponNotProvided => 'Cupom nao informado',
            self::UsageLimitReached => 'Limite de uso atingido',
            self::ExclusivityConflict => 'Conflita com um desconto ja aplicado',
            self::StoppedByPreviousRule => 'Interrompida por uma regra anterior',
            self::SupersededByExclusiveRule => 'Descartada por um desconto exclusivo',
            self::SupersededByBetterOffer => 'Havia uma oferta melhor no mesmo grupo',
            self::NoDiscountValue => 'O calculo resultou em desconto zero',
        };
    }
}
