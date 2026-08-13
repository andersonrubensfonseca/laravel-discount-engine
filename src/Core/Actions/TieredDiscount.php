<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Actions;

use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/**
 * Desconto escalonado por faixa: 5% acima de R$ 100, 10% acima de R$ 300.
 *
 * meta:
 *   basis  'subtotal' (centavos do escopo) ou 'quantity' (unidades do escopo)
 *   tiers  [['min' => .., 'percent' => ..] | ['min' => .., 'amount_cents' => ..]]
 *
 * Vale a faixa MAIS ALTA alcancada, sem soma entre faixas. A ordem do array
 * nao importa — quem cadastra pelo painel raramente mantem ordem.
 */
final class TieredDiscount implements DiscountAction
{
    public static function key(): string
    {
        return 'tiered';
    }

    public static function label(): string
    {
        return 'Desconto escalonado por faixa';
    }

    public function calculate(
        ActionDefinition $definition,
        CartContext $cart,
        DiscountScope $scope,
    ): DiscountAllocation {
        $tiers = $definition->meta('tiers', []);

        if (! is_array($tiers) || $tiers === [] || $scope->isEmpty()) {
            return DiscountAllocation::empty();
        }

        $measure = $definition->meta('basis', 'subtotal') === 'quantity'
            ? $scope->totalUnits()
            : $scope->total()->cents;

        $tier = $this->highestMatchingTier($tiers, $measure);

        if ($tier === null) {
            return DiscountAllocation::empty();
        }

        $discount = isset($tier['amount_cents'])
            ? Money::fromCents((int) $tier['amount_cents'])
            : $scope->total()->percentage((float) ($tier['percent'] ?? 0));

        if ($definition->maxDiscount !== null) {
            $discount = $discount->clampTo($definition->maxDiscount);
        }

        return DiscountAllocation::spread($scope, $discount->atLeastZero());
    }

    /**
     * @param  array<int,array<string,mixed>>  $tiers
     * @return array<string,mixed>|null
     */
    private function highestMatchingTier(array $tiers, int $measure): ?array
    {
        $selected = null;
        $selectedMin = -1;

        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $min = (int) ($tier['min'] ?? 0);

            if ($measure >= $min && $min > $selectedMin) {
                $selected = $tier;
                $selectedMin = $min;
            }
        }

        return $selected;
    }
}
