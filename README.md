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
