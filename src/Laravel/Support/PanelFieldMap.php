<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Support;

use SolutionsTI\DiscountEngine\Core\Registry\ActionRegistry;
use SolutionsTI\DiscountEngine\Core\Registry\ConditionRegistry;

/**
 * Descreve, para o painel, que campos cada tipo de condicao ou acao precisa.
 *
 * Isto e metadado de INTERFACE, nao de dominio — por isso vive na camada
 * Laravel e nao no Core. O motor nunca consulta este mapa.
 *
 * Tipos registrados pelo app hospedeiro que nao estiverem aqui continuam
 * funcionando: caem no modo 'raw', onde o valor e digitado como JSON. Assim
 * uma condicao customizada nunca fica invisivel no painel.
 */
final class PanelFieldMap
{
    /** @var array<string,array<string,mixed>> */
    private const CONDITIONS = [
        'cart_subtotal' => [
            'value' => 'cents',
            'hint' => 'Valor em centavos. R$ 200,00 = 20000.',
        ],
        'total_quantity' => [
            'value' => 'int',
            'hint' => 'Conta unidades, nao linhas do carrinho.',
        ],
        'category_quantity' => [
            'value' => 'int',
            'meta' => ['category_id' => ['label' => 'ID da categoria', 'type' => 'text']],
            'hint' => 'Quantas unidades da categoria informada.',
        ],
        'first_purchase' => [
            'value' => 'bool',
            'hint' => 'Visitante nao identificado nunca satisfaz esta condicao.',
        ],
        'customer_group' => [
            'value' => 'list',
            'hint' => 'Separe por virgula. Use "contem algum de" ou "nao contem nenhum de".',
        ],
    ];

    /** @var array<string,array<string,mixed>> */
    private const ACTIONS = [
        'percentage' => [
            'value' => 'percent',
            'max' => true,
        ],
        'fixed_amount' => [
            'value' => 'cents',
            'hint' => 'Valor em centavos.',
        ],
        'free_shipping' => [
            'value' => 'none',
            'max' => true,
            'hint' => 'Sem teto, zera o frete. Com teto, subsidia parcialmente.',
        ],
        'buy_x_get_y' => [
            'value' => 'none',
            'max' => true,
            'meta' => [
                'buy' => ['label' => 'Paga', 'type' => 'number', 'default' => 2],
                'free' => ['label' => 'Leva de graca', 'type' => 'number', 'default' => 1],
                'free_item' => [
                    'label' => 'Qual sai de graca',
                    'type' => 'select',
                    'options' => ['cheapest' => 'O mais barato', 'most_expensive' => 'O mais caro'],
                    'default' => 'cheapest',
                ],
            ],
            'hint' => 'A escolha acontece dentro de cada grupo, nao no carrinho inteiro.',
        ],
        'tiered' => [
            'value' => 'none',
            'max' => true,
            'meta' => [
                'basis' => [
                    'label' => 'Faixa medida por',
                    'type' => 'select',
                    'options' => ['subtotal' => 'Valor (centavos)', 'quantity' => 'Quantidade'],
                    'default' => 'subtotal',
                ],
            ],
            'tiers' => true,
            'hint' => 'Vale a faixa mais alta alcancada. Nao ha soma entre faixas.',
        ],
        'component_unit_price' => [
            'value' => 'none',
            'max' => true,
            'meta' => [
                'first_n' => ['label' => 'Quantas unidades', 'type' => 'number', 'default' => 1],
                'unit_price_cents' => ['label' => 'Preco promocional (centavos)', 'type' => 'number', 'default' => 199],
                'per' => [
                    'label' => 'Cota por',
                    'type' => 'select',
                    'options' => [
                        'item_unit' => 'Cada unidade do produto',
                        'line' => 'Cada linha do carrinho',
                        'cart' => 'Pedido inteiro',
                    ],
                    'default' => 'item_unit',
                ],
            ],
            'hint' => 'Nunca encarece: se o componente ja custa menos, nao ha desconto.',
        ],
    ];

    public function __construct(
        private readonly ConditionRegistry $conditions,
        private readonly ActionRegistry $actions,
    ) {
    }

    /** @return array<string,array<string,mixed>> */
    public function conditions(): array
    {
        return $this->merge($this->conditions->options(), self::CONDITIONS);
    }

    /** @return array<string,array<string,mixed>> */
    public function actions(): array
    {
        return $this->merge($this->actions->options(), self::ACTIONS);
    }

    /**
     * @param  array<string,string>                $registered  chave => rotulo
     * @param  array<string,array<string,mixed>>   $known
     * @return array<string,array<string,mixed>>
     */
    private function merge(array $registered, array $known): array
    {
        $result = [];

        foreach ($registered as $key => $label) {
            $result[$key] = array_merge(
                ['value' => 'raw', 'label' => $label, 'meta' => [], 'max' => false, 'hint' => ''],
                $known[$key] ?? [],
                ['label' => $label],
            );
        }

        return $result;
    }
}
