<?php

declare(strict_types=1);

use SolutionsTI\DiscountEngine\Core\Actions\BuyXGetYDiscount;
use SolutionsTI\DiscountEngine\Core\Actions\ComponentUnitPriceDiscount;
use SolutionsTI\DiscountEngine\Core\Actions\FixedAmountDiscount;
use SolutionsTI\DiscountEngine\Core\Actions\FreeShippingDiscount;
use SolutionsTI\DiscountEngine\Core\Actions\PercentageDiscount;
use SolutionsTI\DiscountEngine\Core\Actions\TieredDiscount;
use SolutionsTI\DiscountEngine\Core\Conditions\CartSubtotalCondition;
use SolutionsTI\DiscountEngine\Core\Conditions\CategoryQuantityCondition;
use SolutionsTI\DiscountEngine\Core\Conditions\CustomerGroupCondition;
use SolutionsTI\DiscountEngine\Core\Conditions\FirstPurchaseCondition;
use SolutionsTI\DiscountEngine\Core\Conditions\TotalQuantityCondition;

return [

    /*
    |--------------------------------------------------------------------------
    | Nomes das tabelas
    |--------------------------------------------------------------------------
    | Renomeie aqui se o pacote for instalado num banco que ja tenha
    | tabelas com esses nomes.
    */

    'tables' => [
        'rules' => 'discount_rules',
        'coupons' => 'discount_coupons',
        'usages' => 'discount_usages',
    ],

    /*
    |--------------------------------------------------------------------------
    | Teto global de desconto
    |--------------------------------------------------------------------------
    | Rede de seguranca: percentual maximo que a soma de TODAS as regras pode
    | descontar de um pedido. Protege contra combinacoes nao previstas de
    | regras cadastradas por pessoas diferentes. null = sem teto.
    */

    'global_cap_percentage' => env('DISCOUNT_GLOBAL_CAP', null),

    /*
    |--------------------------------------------------------------------------
    | Cache das regras
    |--------------------------------------------------------------------------
    | As regras automaticas sao consultadas a cada alteracao do carrinho.
    | O cache e invalidado automaticamente quando uma regra e salva ou removida.
    */

    'cache' => [
        'enabled' => env('DISCOUNT_CACHE_ENABLED', true),
        'ttl' => env('DISCOUNT_CACHE_TTL', 300),
        'key' => 'discount-engine.automatic-rules',
        'store' => env('DISCOUNT_CACHE_STORE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Painel administrativo
    |--------------------------------------------------------------------------
    |
    | ATENCAO: o middleware padrao e apenas 'web' — ou seja, SEM autenticacao.
    | Isso existe para o painel funcionar de imediato em ambiente local.
    |
    | ANTES DE SUBIR PARA PRODUCAO, adicione o middleware de autenticacao e
    | autorizacao do seu app, por exemplo ['web', 'auth', 'can:gerir-descontos'].
    | Quem alcanca este painel edita as regras de preco da loja.
    |
    */

    'panel' => [
        'enabled' => env('DISCOUNT_PANEL_ENABLED', true),
        'prefix' => env('DISCOUNT_PANEL_PREFIX', 'admin/descontos'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Condicoes disponiveis
    |--------------------------------------------------------------------------
    | Adicione aqui as condicoes do seu proprio dominio. A classe precisa
    | implementar ConditionEvaluator; a chave vem do metodo key().
    */

    'conditions' => [
        CartSubtotalCondition::class,
        TotalQuantityCondition::class,
        CategoryQuantityCondition::class,
        FirstPurchaseCondition::class,
        CustomerGroupCondition::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Acoes disponiveis
    |--------------------------------------------------------------------------
    */

    'actions' => [
        PercentageDiscount::class,
        FixedAmountDiscount::class,
        FreeShippingDiscount::class,
        BuyXGetYDiscount::class,
        TieredDiscount::class,
        ComponentUnitPriceDiscount::class,
    ],

];
