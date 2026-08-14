<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Support;

use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\CustomerContext;
use SolutionsTI\DiscountEngine\Core\Context\PriceComponent;
use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * Monta um CartContext a partir de um array.
 *
 * Nasceu para o simulador do painel, mas serve para qualquer integracao
 * que receba carrinho em JSON — webhook, API de checkout, fila.
 */
final class CartContextFactory
{
    /** @param  array<string,mixed>  $payload */
    public function fromArray(array $payload): CartContext
    {
        return new CartContext(
            items: array_map(
                fn (array $item): CartItem => $this->item($item),
                array_values($payload['items'] ?? []),
            ),
            shippingCost: Money::fromCents((int) ($payload['shipping_cents'] ?? 0)),
            customer: $this->customer($payload['customer'] ?? null),
            couponCodes: array_values(array_filter(array_map(
                static fn ($code): string => trim((string) $code),
                (array) ($payload['coupons'] ?? []),
            ))),
            attributes: (array) ($payload['attributes'] ?? []),
        );
    }

    /** @param  array<string,mixed>  $data */
    private function item(array $data): CartItem
    {
        $components = array_values($data['components'] ?? []);

        // Repassamos unit_price_cents mesmo quando ha componentes, de proposito.
        // O construtor do CartItem confere se o total bate com a soma das
        // parcelas — descartar o valor aqui desligaria essa protecao logo na
        // fronteira onde ela mais serve: dados vindos de fora.
        return new CartItem(
            id: $data['id'] ?? uniqid('item_'),
            sku: (string) ($data['sku'] ?? 'SKU'),
            quantity: max(1, (int) ($data['quantity'] ?? 1)),
            unitPrice: array_key_exists('unit_price_cents', $data)
                ? Money::fromCents((int) $data['unit_price_cents'])
                : null,
            categoryIds: array_values((array) ($data['category_ids'] ?? [])),
            attributes: (array) ($data['attributes'] ?? []),
            components: array_map(
                static fn (array $component): PriceComponent => new PriceComponent(
                    type: (string) ($component['type'] ?? 'base'),
                    unitPrice: Money::fromCents((int) ($component['unit_price_cents'] ?? 0)),
                    quantity: max(1, (int) ($component['quantity'] ?? 1)),
                ),
                $components,
            ),
        );
    }

    /** @param  array<string,mixed>|null  $data */
    private function customer(?array $data): ?CustomerContext
    {
        if ($data === null || ($data['id'] ?? null) === null) {
            return null;
        }

        return new CustomerContext(
            id: $data['id'],
            groups: array_values(array_filter((array) ($data['groups'] ?? []))),
            completedOrders: max(0, (int) ($data['completed_orders'] ?? 0)),
            attributes: (array) ($data['attributes'] ?? []),
        );
    }
}
