<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Actions;

use SolutionsTI\DiscountEngine\Core\Allocation\AllocationEntry;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Allocation\ScopedComponent;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/**
 * "Leve 3, pague 2".
 *
 * meta:
 *   buy         quantas unidades sao pagas (padrao 2)
 *   free        quantas saem de graca (padrao 1)
 *   free_item   'cheapest' (padrao) ou 'most_expensive'
 *
 * O recorte de quais componentes participam ja vem pronto no escopo, feito
 * pelo motor a partir de target + meta['component_types'].
 *
 * A escolha de quem sai de graca acontece DENTRO de cada grupo, nao no
 * carrinho inteiro. Com 6 unidades a 50, 40, 30, 20, 10 e 10, os grupos sao
 * (50,40,30) e (20,10,10) — saem o 30 e o 10, total 40. Pegar "as 2 mais
 * baratas do carrinho" daria 20, e a diferenca aparece na margem.
 */
final class BuyXGetYDiscount implements DiscountAction
{
    public static function key(): string
    {
        return 'buy_x_get_y';
    }

    public static function label(): string
    {
        return 'Leve X pague Y';
    }

    public function calculate(
        ActionDefinition $definition,
        CartContext $cart,
        DiscountScope $scope,
    ): DiscountAllocation {
        $buy = max(1, (int) $definition->meta('buy', 2));
        $free = max(1, (int) $definition->meta('free', 1));
        $groupSize = $buy + $free;

        $units = $this->units($scope);

        if (count($units) < $groupSize) {
            return DiscountAllocation::empty();
        }

        $freeIsMostExpensive = $definition->meta('free_item', 'cheapest') === 'most_expensive';

        usort(
            $units,
            static fn (array $a, array $b): int => $freeIsMostExpensive
                ? $a['cents'] <=> $b['cents']
                : $b['cents'] <=> $a['cents'],
        );

        // Acumula por componente: e isso que permite dizer na nota fiscal
        // exatamente qual linha recebeu o desconto.
        $perComponent = [];

        foreach ($units as $position => $unit) {
            if ($position % $groupSize < $buy) {
                continue;
            }

            $key = $unit['scope_index'];
            $perComponent[$key] = ($perComponent[$key] ?? 0) + $unit['cents'];
        }

        $entries = [];

        foreach ($perComponent as $scopeIndex => $cents) {
            $component = $scope->components[$scopeIndex];
            $entries[] = $component->entry(Money::fromCents($cents));
        }

        $allocation = DiscountAllocation::of($entries);

        if ($definition->maxDiscount !== null) {
            $allocation = $allocation->clampTo($definition->maxDiscount);
        }

        return $allocation;
    }

    /**
     * Expande o escopo em unidades individuais, cada uma sabendo de qual
     * componente veio.
     *
     * @return array<int,array{cents:int,scope_index:int}>
     */
    private function units(DiscountScope $scope): array
    {
        $units = [];

        foreach ($scope->components as $index => $component) {
            /** @var ScopedComponent $component */
            for ($i = 0; $i < $component->units(); $i++) {
                $units[] = [
                    'cents' => $component->unitPrice->cents,
                    'scope_index' => $index,
                ];
            }
        }

        return $units;
    }
}
