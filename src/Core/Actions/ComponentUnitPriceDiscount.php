<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Actions;

use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Allocation\DiscountScope;
use SolutionsTI\DiscountEngine\Core\Allocation\ScopedComponent;
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;

/**
 * "A primeira estampa de cada camisa sai a R$ 1,99; as demais, preco normal."
 *
 * meta:
 *   first_n            quantas unidades recebem o preco promocional (padrao 1)
 *   unit_price_cents   o preco promocional (padrao 0 = gratis)
 *   per                'item_unit' (padrao) | 'line' | 'cart'
 *
 * O 'per' e a decisao que mais muda o resultado:
 *
 *   item_unit  a cada camisa, a primeira estampa. 2 camisas com 3 estampas
 *              cada = 2 estampas promocionais.
 *   line       a primeira estampa da linha do carrinho, independente de
 *              quantas camisas ela representa = 1 estampa promocional.
 *   cart       a primeira estampa do pedido inteiro, somando todas as linhas.
 *
 * O desconto e a diferenca entre o preco cheio e o promocional. Se o
 * componente ja custa menos que o preco promocional, nao ha desconto —
 * a regra nunca encarece nada.
 */
final class ComponentUnitPriceDiscount implements DiscountAction
{
    public static function key(): string
    {
        return 'component_unit_price';
    }

    public static function label(): string
    {
        return 'Preco promocional nas primeiras unidades';
    }

    public function calculate(
        ActionDefinition $definition,
        CartContext $cart,
        DiscountScope $scope,
    ): DiscountAllocation {
        $firstN = max(1, (int) $definition->meta('first_n', 1));
        $promoPrice = Money::fromCents((int) $definition->meta('unit_price_cents', 0));
        $per = (string) $definition->meta('per', 'item_unit');

        if ($scope->isEmpty()) {
            return DiscountAllocation::empty();
        }

        $entries = [];
        $remainingForCart = $firstN;

        foreach ($scope->components as $component) {
            /** @var ScopedComponent $component */
            $perUnitDiscount = $component->unitPrice->subtract($promoPrice);

            if (! $perUnitDiscount->isPositive()) {
                continue;
            }

            $discountedUnits = $this->discountedUnits($component, $firstN, $per, $remainingForCart);

            if ($discountedUnits <= 0) {
                continue;
            }

            if ($per === 'cart') {
                $remainingForCart -= $discountedUnits;
            }

            $entries[] = $component->entry($perUnitDiscount->multiply($discountedUnits));
        }

        $allocation = DiscountAllocation::of($entries);

        if ($definition->maxDiscount !== null) {
            $allocation = $allocation->clampTo($definition->maxDiscount);
        }

        return $allocation;
    }

    private function discountedUnits(
        ScopedComponent $component,
        int $firstN,
        string $per,
        int $remainingForCart,
    ): int {
        return match ($per) {
            // Uma cota por unidade do item pai: a primeira estampa de CADA camisa.
            'item_unit' => $component->itemQuantity * min($firstN, $component->componentQuantity),

            // Uma cota por linha do carrinho, nao importa a quantidade.
            'line' => min($firstN, $component->units()),

            // Uma cota para o pedido inteiro, consumida na ordem do escopo.
            'cart' => max(0, min($remainingForCart, $component->units())),

            default => $component->itemQuantity * min($firstN, $component->componentQuantity),
        };
    }
}
