# laravel-discount-engine

Motor de descontos orientado a regras para carrinhos. Cupons, regras automaticas,
condicoes compostas e acoes combinaveis — cadastraveis por gente nao tecnica.

> **Status: esqueleto.** O `Core/` esta escrito e coberto por testes.
> A camada Laravel (migrations, Eloquent, painel) ainda nao foi implementada.

## A ideia central

O motor e **PHP puro**. Nenhuma classe dentro de `src/Core/` importa `Illuminate\*`.

Isso nao e purismo: e o que permite instalar o mesmo pacote em Laravel 8 e em
Laravel 13 sem reescrever a logica de negocio. Quando a migracao chegar, so a
pasta `src/Laravel/` precisa de atencao.

```
CartContext  ->  DiscountEngine  ->  DiscountResult
   (DTO)          (sem IO)            (snapshot)
```

O motor nao le banco, nao chama HTTP, nao toca em sessao. Entrada, saida, testavel.

## Estrutura

```
src/Core/
├── Contracts/    ConditionEvaluator, DiscountAction, RuleRepository, UsageTracker
├── Enums/        TriggerType, CombinationMode, CalculationBase, Operator, ...
├── Context/      CartContext, CartItem, CustomerContext
├── Money/        Money (centavos, rateio sem perda)
├── Rule/         Rule, ConditionGroup, ConditionDefinition, ActionDefinition
├── Conditions/   5 condicoes prontas
├── Actions/      percentual, valor fixo, frete gratis
├── Registry/     ConditionRegistry, ActionRegistry
├── Engine/       DiscountEngine, ConditionMatcher
└── Result/       DiscountResult, AppliedDiscount, RejectedRule
```

## Decisoes que valem explicar

**Dinheiro em centavos.** `Money` guarda `int`. Float em dinheiro faz a soma dos
itens divergir do total do pedido, e o financeiro descobre depois.

**Rateio pelo metodo do maior resto.** `Money::allocate()` garante que a soma das
fatias e exatamente igual ao total. Sem centavo perdido, sem centavo inventado.

**Base de calculo explicita por regra.** `CalculationBase::Original` faz
10% + 10% = 20%. `CalculationBase::Current` faz 10% + 10% = 19%. Quem cadastra a
regra escolhe; o motor nao adivinha.

**Rejeicoes sao dados, nao silencio.** O resultado traz `rejected[]` com o motivo
de cada regra que nao entrou. E o que alimenta o simulador do painel.

**Limite de uso aqui e consulta, nao reserva.** `UsageTracker` responde "ainda ha
saldo?". A reserva definitiva acontece no fechamento do pedido, dentro de
transacao com lock — checar aqui e confiar seria race condition classica.

**Nunca recalcular pedido antigo.** O `DiscountResult` e serializavel de proposito:
grave o snapshot no pedido. Editar uma regra nao pode reescrever historico financeiro.

## Estendendo

Nova condicao:

```php
final class DeliveryStateCondition implements ConditionEvaluator
{
    public static function key(): string { return 'delivery_state'; }
    public static function label(): string { return 'UF de entrega'; }

    public function evaluate(ConditionDefinition $definition, CartContext $cart): bool
    {
        return $definition->operator->compare(
            $cart->attribute('delivery_state'),
            $definition->value,
        );
    }
}
```

Registre a chave e ela aparece no painel. O motor nao muda.

## Proximos passos

- [ ] Camada Laravel: migrations, models Eloquent, repositorio com cache
- [ ] Endpoint de validacao de cupom com mensagens de erro distintas
- [ ] Reserva de uso com lock no fechamento do pedido
- [ ] Painel Blade + Alpine com construtor de condicoes e simulador
- [ ] `BuyXGetY` e desconto escalonado por faixa
- [ ] CI com matriz Laravel 8.75 / 13

## Testes

```bash
composer install
vendor/bin/phpunit
```

## Licenca

MIT.

---

# Camada Laravel

> Escrita, mas **nao executada**: o ambiente onde foi gerada nao tinha PHP nem
> acesso ao Packagist. Os testes do `Core/` passam; a camada Laravel abaixo
> precisa ser validada num projeto real antes de qualquer confianca.

## Instalacao

```bash
composer update            # illuminate/* entrou como dependencia
php artisan vendor:publish --tag=discount-engine-config
php artisan migrate
```

O ServiceProvider e descoberto automaticamente. A facade `Discounts` tambem.

## Uso

```php
use SolutionsTI\DiscountEngine\Core\Context\CartContext;
use SolutionsTI\DiscountEngine\Core\Context\CartItem;
use SolutionsTI\DiscountEngine\Core\Context\CustomerContext;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Laravel\Facades\Discounts;

$cart = new CartContext(
    items: [
        new CartItem(
            id: $linha->id,
            sku: $linha->sku,
            quantity: $linha->quantidade,
            unitPrice: Money::fromCents($linha->preco_centavos),
            categoryIds: $linha->produto->categorias->pluck('id')->all(),
        ),
    ],
    shippingCost: Money::fromCents($frete),
    customer: new CustomerContext(
        id: $user->id,
        groups: $user->grupos->pluck('slug')->all(),
        completedOrders: $user->pedidos()->concluidos()->count(),
    ),
    couponCodes: session('cupons', []),
);

$resultado = Discounts::calculate($cart);

$resultado->totalDiscount()->format();   // R$ 25,00
$resultado->finalTotal()->cents;         // para gravar no pedido
$resultado->itemAllocations;             // desconto rateado por item
```

Validar um cupom digitado:

```php
$validacao = Discounts::validateCoupon($request->input('codigo'), $cart);

return response()->json($validacao->toArray());
// { "accepted": false, "reason": "conditions_not_met",
//   "message": "Carrinho nao atende as condicoes" }
```

Fechar o pedido:

```php
DB::transaction(function () use ($cart, $user) {
    $resultado = Discounts::calculate($cart);   // recalcula, nunca confia no front

    if (! Discounts::reserve($resultado, $pedido->id, $user->id)) {
        throw new CupomEsgotadoException();     // acabou entre a simulacao e o fechamento
    }

    $pedido->update([
        'desconto_centavos' => $resultado->totalDiscount()->cents,
        'desconto_snapshot' => $resultado->toArray(),
    ]);
});
```

## Formato das regras no banco

Condicoes e acoes vao em colunas JSON.

```php
DiscountRule::create([
    'name' => '10% acima de R$ 200, so na primeira compra',
    'trigger' => 'automatic',
    'priority' => 10,
    'combination_mode' => 'stackable',
    'calculation_base' => 'current',
    'conditions' => [
        'logic' => 'and',
        'children' => [
            ['type' => 'cart_subtotal', 'operator' => 'gte', 'value' => 20000],
            ['type' => 'first_purchase', 'operator' => 'eq', 'value' => true],
        ],
    ],
    'actions' => [
        ['type' => 'percentage', 'value' => 10, 'target' => 'cart',
         'max_discount_cents' => 5000],
    ],
]);
```

Grupos aninhados: um filho com a chave `logic` vira subgrupo.

```php
'conditions' => [
    'logic' => 'and',
    'children' => [
        ['type' => 'cart_subtotal', 'operator' => 'gte', 'value' => 10000],
        ['logic' => 'or', 'children' => [
            ['type' => 'first_purchase', 'operator' => 'eq', 'value' => true],
            ['type' => 'customer_group', 'operator' => 'contains_any', 'value' => ['vip']],
        ]],
    ],
],
```

## Por que JSON e nao tabelas normalizadas

Mudei de ideia em relacao ao plano inicial. A arvore de condicoes e
arbitrariamente aninhada; normalizar isso exige `parent_id` com self-join e
uma hidratacao recursiva bem mais fragil de manter.

O preco: nao da para perguntar em SQL "quais regras usam a categoria 12".
Se isso virar necessidade real, a saida e uma tabela lateral de indice
alimentada por observer — sem mexer no formato principal.

## Concorrencia

Ha dois momentos distintos, e confundi-los e a causa classica de cupom
usado alem do limite:

| Momento | Classe | Faz o que |
|---|---|---|
| Simulacao do carrinho | `DatabaseUsageTracker` | Le o saldo, sem lock |
| Fechamento do pedido | `UsageReserver` | `lockForUpdate` + incremento |

Travar linha a cada recalculo de carrinho seria desastroso sob carga.
A garantia real esta no fechamento, e a `unique(order_id, rule_id)` da tabela
de usos e a segunda linha de defesa contra retry duplicado.

## O que falta

- [ ] Testes da camada Laravel (Testbench + SQLite em memoria)
- [ ] Painel Blade + Alpine com construtor de condicoes e simulador
- [ ] `BuyXGetY` e desconto escalonado por faixa
- [ ] Endpoint HTTP pronto para o checkout
- [ ] CI com matriz Laravel 8.75 / 13
