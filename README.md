# laravel-discount-engine

Motor de descontos orientado a regras para carrinhos. Cupons, regras
automaticas, condicoes compostas, produtos com preco decomposto e alocacao
exata por item.

Compativel com Laravel 8.75+ e 13. PHP 8.1+.

> **Vai cadastrar descontos, nao programar?**
> O manual esta em [`docs/MANUAL.md`](docs/MANUAL.md) — escrito para o time
> comercial, com receitas prontas e roteiro de familiarizacao.

---

## Instalacao

```bash
composer require solutionsti/laravel-discount-engine
php artisan vendor:publish --tag=discount-engine-config
php artisan migrate
```

O ServiceProvider e a facade `Discounts` sao descobertos automaticamente.

> **Config publicado nao se atualiza sozinho.** Ao atualizar o pacote, rode
> `vendor:publish --tag=discount-engine-config --force`. As listas de
> condicoes e acoes vivem la; sem republicar, tipos novos nao existem nem no
> painel nem no motor.

---

## A ideia central

O motor e **PHP puro**. Nenhuma classe em `src/Core/` importa `Illuminate\*`.

Isso nao e purismo: e o que permite instalar o mesmo pacote em Laravel 8 e em
Laravel 13 sem reescrever logica de negocio. Na migracao, so `src/Laravel/`
precisa de atencao.

```
CartContext  ->  DiscountEngine  ->  DiscountResult
   (DTO)          (sem IO)            (snapshot)
```

O motor nao le banco, nao chama HTTP, nao toca em sessao. Entrada, saida,
testavel.

---

## Uso

```php
use SolutionsTI\DiscountEngine\Core\Context\{CartContext, CartItem, CustomerContext, PriceComponent};
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
$resultado->finalTotal()->cents;
$resultado->itemAllocations();           // desconto exato por item
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
DB::transaction(function () use ($cart, $user, $pedido) {
    $resultado = Discounts::calculate($cart);   // recalcula, nunca confia no front

    if (! Discounts::reserve($resultado, $pedido->id, $user->id)) {
        throw new CupomEsgotadoException();     // esgotou entre a simulacao e o fechamento
    }

    $pedido->update([
        'desconto_centavos' => $resultado->totalDiscount()->cents,
        'desconto_snapshot' => $resultado->toArray(),
    ]);
});
```

---

## Conceitos

Uma **regra** tem condicoes (decidem *se* aplica) e acoes (decidem *o que*
faz). As duas partes sao independentes.

### Alvos

| Alvo | Onde incide |
|---|---|
| `cart` | Todo o carrinho |
| `items` | Os itens (equivalente a `cart` na pratica) |
| `components` | Apenas os componentes de preco indicados |
| `shipping` | O frete |

### Tres niveis de recorte

Confundir estes tres e o erro mais caro do sistema:

| Onde | Decide |
|---|---|
| Condicoes da regra | **Se** a regra roda |
| `meta.category_ids` / `meta.skus` | **Em quais produtos** |
| `meta.component_types` | **Em qual parte do preco** |

Condicao nao recorta. "Carrinho tem camisas" libera a regra, mas o desconto
cairia tambem nas canecas — para limitar aos produtos certos, use
`category_ids`.

### Condicoes disponiveis

| Chave | O que avalia |
|---|---|
| `cart_subtotal` | Subtotal em centavos |
| `total_quantity` | Unidades no carrinho |
| `category_quantity` | Unidades de uma categoria (`meta.category_id`) |
| `first_purchase` | Primeira compra — exige cliente identificado |
| `customer_group` | Grupos do cliente |

### Acoes disponiveis

| Chave | O que faz |
|---|---|
| `percentage` | Percentual sobre o escopo |
| `fixed_amount` | Valor fixo em centavos |
| `free_shipping` | Zera o frete (ou subsidia, com `max_discount_cents`) |
| `buy_x_get_y` | Leve X pague Y |
| `tiered` | Escalonado por faixa |
| `component_unit_price` | Preco promocional nas primeiras unidades |

---

## Formato das regras

Condicoes e acoes ficam em colunas JSON.

```php
DiscountRule::create([
    'name' => '10% acima de R$ 200, so na primeira compra',
    'trigger' => 'automatic',
    'priority' => 10,
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

**Por que JSON e nao tabelas normalizadas.** A arvore de condicoes e
arbitrariamente aninhada; normalizar exigiria `parent_id` com self-join e
hidratacao recursiva bem mais fragil. O preco e nao dar para perguntar em SQL
"quais regras usam a categoria 12" — se virar necessidade, resolve-se com uma
tabela lateral de indice.

---

## Produtos compostos

Um produto customizavel nao tem "um preco". Camisa estampada e o preco da
peca mais o da estamparia — e regras comerciais costumam incidir so em uma
dessas partes.

```php
new CartItem(
    id: $linha->id,
    sku: 'CAMISA-P',
    quantity: 2,                         // duas camisas
    categoryIds: [7],
    components: [
        new PriceComponent('base',  Money::fromCents(4000)),
        new PriceComponent('print', Money::fromCents(1500), quantity: 3),
    ],
);
```

Duas camisas com tres estampas cada = 6 estampas. A quantidade do item
multiplica a do componente.

Os tipos (`base`, `print`, `bordado`) sao strings livres: o vocabulario e do
sistema hospedeiro.

Item sem `components` continua funcionando — o motor cria um `base` a partir
do `unitPrice`. Se voce passar os dois e divergirem, o construtor estoura:
divergencia ai e bug de integracao, nao caso de negocio.

### Desconto so na estamparia

```php
['type' => 'percentage', 'value' => 20, 'target' => 'components',
 'meta' => ['component_types' => ['print'], 'category_ids' => [7]]]
```

### Primeira estampa a R$ 1,99

```php
['type' => 'component_unit_price', 'value' => 0, 'target' => 'components',
 'meta' => [
     'component_types' => ['print'],
     'category_ids' => [7],
     'first_n' => 1,
     'unit_price_cents' => 199,
     'per' => 'item_unit',
 ]]
```

O `per` muda o resultado:

| `per` | 2 camisas com 3 estampas cada |
|---|---|
| `item_unit` | 2 estampas promocionais (uma por camisa) |
| `line` | 1 estampa promocional |
| `cart` | 1 estampa promocional |

A regra nunca encarece: se o componente ja custa menos que o preco
promocional, nao ha desconto.

### Leve X pague Y

```php
['type' => 'buy_x_get_y', 'value' => 0, 'target' => 'components',
 'meta' => ['component_types' => ['base'], 'buy' => 2, 'free' => 1,
            'free_item' => 'cheapest']]
```

A escolha de quem sai de graca acontece **dentro de cada grupo**, nao no
carrinho inteiro. Com 6 unidades a 50, 40, 30, 20, 10 e 10, os grupos sao
(50,40,30) e (20,10,10) — saem o 30 e um 10, total 40. Pegar "as 2 mais
baratas do carrinho" daria 20, e a diferenca aparece na margem.

### Escalonado por faixa

```php
['type' => 'tiered', 'value' => 0, 'target' => 'cart',
 'meta' => ['basis' => 'subtotal', 'tiers' => [
     ['min' => 10000, 'percent' => 5],
     ['min' => 30000, 'percent' => 10],
     ['min' => 50000, 'percent' => 15],
 ]]]
```

Vale a faixa **mais alta alcancada**, sem soma entre faixas. As faixas nao
precisam vir ordenadas. Cada uma aceita `percent` ou `amount_cents`.

---

## Acumulo

Tres mecanismos, do mais amplo ao mais fino.

**`combination_mode: exclusive`** — se a regra aplicar, ela e a unica do
pedido: descarta o que veio antes e bloqueia o que viria depois.

**`resolution_group` + `resolution_strategy`** — regras do mesmo grupo
competem; regras de grupos diferentes continuam acumulando.

| Estrategia | Comportamento |
|---|---|
| `first_by_priority` | Vence a primeira na ordem de prioridade |
| `highest_discount` | Simula todas e aplica a de maior desconto |

> `first_by_priority` **ignora o valor**. Uma regra de 5% com prioridade menor
> vence uma de 20% do mesmo grupo. O painel avisa quando essa combinacao e
> escolhida.

**`stop_further_processing`** — mantem o ja aplicado, mas encerra o pipeline.

**`calculation_base`** decide sobre qual valor o percentual incide:
`current` faz 10%+10% = 19%; `original` faz 20%.

**`global_cap_percentage`** (config) e a rede de seguranca: teto para a soma
de todas as regras. Incide sobre itens; frete fica de fora, senao um frete
caro consumiria a cota de desconto dos produtos.

---

## Rateio por item

Cada acao devolve uma `DiscountAllocation`: um mapa `(item, componente) =>
valor`. Nao ha rateio proporcional a posteriori — a acao diz de onde saiu cada
centavo.

```php
$resultado->itemAllocations();          // ['CAMISA-1' => Money, ...]
$resultado->componentAllocations();     // ['CAMISA-1::1' => Money, ...]
$resultado->discountByComponentType();  // ['print' => Money, ...]
```

E o que permite emitir nota fiscal com desconto discriminado por item sem
divergencia entre a soma das linhas e o total do pedido.

Dinheiro trafega em centavos (`int`) dentro do motor, e o rateio usa o metodo
do maior resto: a soma das fatias e sempre exatamente igual ao total.

---

## Painel administrativo

```
/admin/descontos
/admin/descontos/simulador
```

CRUD de regras com construtor visual de condicoes e acoes, e um simulador que
monta um carrinho de teste, roda o motor de verdade e mostra **o que nao
aplicou e por que**.

### SEGURANCA — leia antes de subir

O middleware padrao e apenas `web`, **sem autenticacao**. Isso existe para o
painel funcionar de imediato em ambiente local.

```php
'panel' => [
    'middleware' => ['web', 'auth', 'can:gerir-descontos'],
],
```

Enquanto o middleware for so `web`, o painel exibe um aviso vermelho no topo.
Quem alcanca essa URL reescreve as regras de preco da loja.

Para desligar: `DISCOUNT_PANEL_ENABLED=false`.

### Validacao estrutural

O JSON e validado antes de gravar, contra os registries. Erro de digitacao
vira mensagem no formulario, nao excecao no checkout dias depois. O validador
pega JSON malformado, condicao ou acao nao registrada, operador invalido,
alvo `components` sem `component_types`, `tiered` sem faixas e percentual
acima de 100.

### Cupons

Varios codigos por regra, um por linha, normalizados em caixa alta.

Codigo removido da lista e apagado — **exceto se ja tiver sido usado**. Nesse
caso e apenas desativado e o painel avisa: apagar quebraria a referencia dos
usos gravados. O mesmo vale para a regra.

### Publicando as views

```bash
php artisan vendor:publish --tag=discount-engine-views
```

Tailwind e Alpine vem por CDN, para o pacote funcionar em Laravel 8 e 13 sem
exigir build de assets do app hospedeiro.

---

## Concorrencia

Dois momentos distintos, e confundi-los e a causa classica de cupom usado
alem do limite:

| Momento | Classe | Faz o que |
|---|---|---|
| Simulacao do carrinho | `DatabaseUsageTracker` | Le o saldo, sem lock |
| Fechamento do pedido | `UsageReserver` | `lockForUpdate` + incremento |

Travar linha a cada recalculo de carrinho seria desastroso sob carga. A
garantia real esta no fechamento, e a `unique(order_id, rule_id)` da tabela de
usos e a segunda linha de defesa contra retry duplicado.

A recusa lanca excecao interna em vez de `return false`: retorno normal de
dentro de `DB::transaction()` commita, e com varias regras aplicadas isso
deixaria as anteriores gravadas.

### Resultados medidos

MySQL 8 / InnoDB, 50 processos PHP independentes, cada um com sua conexao,
todos calculando antes do portao e reservando no mesmo instante:

| Cenario | Reservas aceitas | `used_count` | Linhas gravadas | Erros |
|---|---|---|---|---|
| 50 processos, limite 1 | 1 | 1 | 1 | 0 |
| 50 processos, limite 3 | 3 | 3 | 3 | 0 |

Nenhum deadlock, nenhum lock wait timeout.

Reproduzir:

```bash
php artisan discount:race-test --workers=50 --limit=1 --delay=15
```

O comando desativa temporariamente as regras automaticas ativas durante o
teste e restaura no fim — sem isso a medicao contamina. O `--delay` precisa
ser generoso: no Windows o boot do `php artisan` faz os ultimos processos
perderem o portao.

Nao rodar em producao: o comando cria e apaga dados de teste.

---

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

Registre a chave no `config/discount-engine.php` publicado e ela aparece no
painel. O motor nao muda.

Tipos customizados que nao estejam no `PanelFieldMap` continuam editaveis no
painel: caem no modo `raw`, com valor digitado como JSON.

Acoes seguem o mesmo padrao, implementando `DiscountAction`: recebem um
`DiscountScope` ja recortado e devolvem uma `DiscountAllocation`.

---

## Estrutura

```
src/Core/            PHP puro, zero Laravel
├── Contracts/       ConditionEvaluator, DiscountAction, RuleRepository, UsageTracker
├── Enums/           TriggerType, CombinationMode, CalculationBase, ResolutionStrategy...
├── Context/         CartContext, CartItem, PriceComponent, CustomerContext
├── Money/           Money (centavos, rateio sem perda)
├── Allocation/      DiscountAllocation, DiscountScope, ScopedComponent
├── Rule/            Rule, ConditionGroup, ConditionDefinition, ActionDefinition
├── Conditions/      5 condicoes
├── Actions/         6 acoes
├── Registry/        ConditionRegistry, ActionRegistry
├── Engine/          DiscountEngine, ConditionMatcher
└── Result/          DiscountResult, AppliedDiscount, RejectedRule

src/Laravel/         camada de integracao
├── Models/          Eloquent
├── Repositories/    EloquentRuleRepository (com cache), RuleHydrator
├── Support/         UsageReserver, CartContextFactory, validador, PanelFieldMap
├── Http/            controllers do painel e do simulador
└── Console/         discount:race-test
```

---

## Testes

```bash
composer install
vendor/bin/phpunit --testdox
```

124 testes. Padrao SQLite em memoria; para rodar contra MySQL:

```bash
DB_CONNECTION=mysql DB_DATABASE=discount_engine_test vendor/bin/phpunit
```

Vale rodar nos dois: SQLite pega logica, MySQL pega diferenca de tipo (coluna
JSON, collation, foreign key) que o SQLite deixa passar.

---

## Estado e limitacoes conhecidas

**O motor esta coberto; a interface nao.** Os 124 testes cobrem Core, camada
Laravel e as rotas do painel. Nenhum toca JavaScript — o construtor visual e
a parte mais fragil do pacote hoje.

**Cupom consumido nao volta.** Nao ha devolucao de cota em cancelamento, nem
em pagamento recusado. E politica deliberada contra fraude, mas significa que
pagamento nao concluido tambem queima o uso. Ver
[`docs/MANUAL.md`](docs/MANUAL.md#cancelamento-e-devolução).

**Devolucao parcial nao tem API.** A informacao existe no snapshot
(`item_allocations`), mas nao ha metodo nem tela para consultar.

**Sem relatorios.** Uso de cupons e custo de campanha precisam ser
consultados no banco.

**Laravel 13 exige PHP 8.3+.** O pacote declara `php: ^8.1`, mas a migracao
para o L13 implica subir o runtime — decisao de infraestrutura, nao de codigo.

**Sem CI.** A matriz L8/L13 ainda nao existe.

---

## Licenca

MIT. Ver [LICENSE](LICENSE).
