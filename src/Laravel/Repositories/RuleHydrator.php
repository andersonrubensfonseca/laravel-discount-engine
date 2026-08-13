<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Repositories;

use DateTimeImmutable;
use SolutionsTI\DiscountEngine\Core\Enums\ActionTarget;
use SolutionsTI\DiscountEngine\Core\Enums\CalculationBase;
use SolutionsTI\DiscountEngine\Core\Enums\CombinationMode;
use SolutionsTI\DiscountEngine\Core\Enums\LogicOperator;
use SolutionsTI\DiscountEngine\Core\Enums\Operator;
use SolutionsTI\DiscountEngine\Core\Enums\TriggerType;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Rule\ActionDefinition;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionDefinition;
use SolutionsTI\DiscountEngine\Core\Rule\ConditionGroup;
use SolutionsTI\DiscountEngine\Core\Rule\Rule;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;

/**
 * Fronteira entre o banco e o dominio.
 *
 * Tudo que e Eloquent para aqui: o Core recebe apenas objetos puros.
 * Se um dia o armazenamento virar Redis, arquivo YAML ou outro ORM,
 * so esta classe muda.
 */
final class RuleHydrator
{
    public function hydrate(DiscountRule $model, ?string $couponCode = null): Rule
    {
        return new Rule(
            id: $model->id,
            name: $model->name,
            trigger: TriggerType::from($model->trigger),
            conditions: $this->conditionGroup($model->conditions),
            actions: $this->actions($model->actions ?? []),
            couponCode: $couponCode,
            priority: $model->priority,
            combinationMode: CombinationMode::from($model->combination_mode ?? 'stackable'),
            exclusivityGroup: $model->exclusivity_group,
            stopFurtherProcessing: (bool) $model->stop_further_processing,
            calculationBase: CalculationBase::from($model->calculation_base ?? 'current'),
            active: (bool) $model->active,
            validFrom: $this->toDate($model->valid_from),
            validUntil: $this->toDate($model->valid_until),
        );
    }

    /** @param  array<string,mixed>|null  $payload */
    private function conditionGroup(?array $payload): ConditionGroup
    {
        if ($payload === null || $payload === []) {
            return new ConditionGroup();
        }

        $logic = LogicOperator::tryFrom($payload['logic'] ?? 'and') ?? LogicOperator::All;
        $children = [];

        foreach ($payload['children'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }

            // A presenca de 'logic' e o que distingue um subgrupo de uma condicao folha.
            $children[] = array_key_exists('logic', $child)
                ? $this->conditionGroup($child)
                : $this->condition($child);
        }

        return new ConditionGroup($logic, $children);
    }

    /** @param  array<string,mixed>  $payload */
    private function condition(array $payload): ConditionDefinition
    {
        return new ConditionDefinition(
            type: (string) $payload['type'],
            operator: Operator::from($payload['operator'] ?? 'eq'),
            value: $payload['value'] ?? null,
            meta: $payload['meta'] ?? [],
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $payload
     * @return array<int,ActionDefinition>
     */
    private function actions(array $payload): array
    {
        $actions = [];

        foreach ($payload as $item) {
            if (! is_array($item) || ! isset($item['type'])) {
                continue;
            }

            $max = $item['max_discount_cents'] ?? null;

            $actions[] = new ActionDefinition(
                type: (string) $item['type'],
                value: (float) ($item['value'] ?? 0),
                target: ActionTarget::from($item['target'] ?? 'cart'),
                maxDiscount: $max === null ? null : Money::fromCents((int) $max),
                meta: $item['meta'] ?? [],
            );
        }

        return $actions;
    }

    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        // Carbon implementa DateTimeInterface; convertemos para nao vazar o Carbon no Core.
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromFormat('U', (string) $value->getTimestamp())
                ?: null;
        }

        return new DateTimeImmutable((string) $value);
    }
}
