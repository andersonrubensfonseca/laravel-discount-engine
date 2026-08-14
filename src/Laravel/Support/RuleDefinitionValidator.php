<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Support;

use SolutionsTI\DiscountEngine\Core\Enums\ActionTarget;
use SolutionsTI\DiscountEngine\Core\Enums\LogicOperator;
use SolutionsTI\DiscountEngine\Core\Enums\Operator;
use SolutionsTI\DiscountEngine\Core\Registry\ActionRegistry;
use SolutionsTI\DiscountEngine\Core\Registry\ConditionRegistry;

/**
 * Valida a estrutura do JSON antes de gravar.
 *
 * Sem isso, um erro de digitacao no painel so aparece quando um cliente
 * real monta o carrinho — e a falha e uma excecao no checkout, nao uma
 * mensagem no formulario. O hidratador confia no que esta no banco; quem
 * precisa desconfiar e esta classe.
 */
final class RuleDefinitionValidator
{
    public function __construct(
        private readonly ConditionRegistry $conditions,
        private readonly ActionRegistry $actions,
    ) {
    }

    /**
     * @return array<int,string>  mensagens de erro; vazio = tudo certo
     */
    public function validateConditions(mixed $tree, string $path = 'condicoes'): array
    {
        if ($tree === null || $tree === []) {
            return [];
        }

        if (! is_array($tree)) {
            return ["{$path}: deve ser um objeto JSON."];
        }

        if (! array_key_exists('logic', $tree)) {
            return ["{$path}: falta a chave 'logic' (use 'and' ou 'or')."];
        }

        if (LogicOperator::tryFrom((string) $tree['logic']) === null) {
            return ["{$path}: 'logic' deve ser 'and' ou 'or', recebido '{$tree['logic']}'."];
        }

        $children = $tree['children'] ?? null;

        if (! is_array($children)) {
            return ["{$path}: falta a lista 'children'."];
        }

        $errors = [];

        foreach ($children as $index => $child) {
            $childPath = "{$path}.children[{$index}]";

            if (! is_array($child)) {
                $errors[] = "{$childPath}: deve ser um objeto.";

                continue;
            }

            $errors = array_merge(
                $errors,
                array_key_exists('logic', $child)
                    ? $this->validateConditions($child, $childPath)
                    : $this->validateCondition($child, $childPath),
            );
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $condition
     * @return array<int,string>
     */
    private function validateCondition(array $condition, string $path): array
    {
        $errors = [];
        $type = $condition['type'] ?? null;

        if (! is_string($type) || $type === '') {
            return ["{$path}: falta a chave 'type'."];
        }

        if (! $this->conditions->has($type)) {
            $disponiveis = implode(', ', array_keys($this->conditions->options()));
            $errors[] = "{$path}: condicao '{$type}' nao registrada. Disponiveis: {$disponiveis}.";
        }

        $operator = $condition['operator'] ?? null;

        if (! is_string($operator) || Operator::tryFrom($operator) === null) {
            $validos = implode(', ', array_column(Operator::cases(), 'value'));
            $errors[] = "{$path}: operador invalido. Use um destes: {$validos}.";
        }

        if (! array_key_exists('value', $condition)) {
            $errors[] = "{$path}: falta a chave 'value'.";
        }

        // Pegadinha comum: a condicao de categoria precisa saber QUAL categoria.
        if ($type === 'category_quantity' && ! isset($condition['meta']['category_id'])) {
            $errors[] = "{$path}: category_quantity exige meta.category_id.";
        }

        return $errors;
    }

    /**
     * @return array<int,string>
     */
    public function validateActions(mixed $actions): array
    {
        if (! is_array($actions) || $actions === []) {
            return ['acoes: informe pelo menos uma acao.'];
        }

        $errors = [];

        foreach ($actions as $index => $action) {
            $path = "acoes[{$index}]";

            if (! is_array($action)) {
                $errors[] = "{$path}: deve ser um objeto.";

                continue;
            }

            $type = $action['type'] ?? null;

            if (! is_string($type) || ! $this->actions->has($type)) {
                $disponiveis = implode(', ', array_keys($this->actions->options()));
                $errors[] = "{$path}: acao '" . (is_string($type) ? $type : '?') . "' nao registrada. Disponiveis: {$disponiveis}.";

                continue;
            }

            $target = $action['target'] ?? 'cart';

            if (! is_string($target) || ActionTarget::tryFrom($target) === null) {
                $errors[] = "{$path}: alvo invalido. Use cart, items, components ou shipping.";
            }

            if (isset($action['value']) && ! is_numeric($action['value'])) {
                $errors[] = "{$path}: 'value' deve ser numerico.";
            }

            $errors = array_merge($errors, $this->validateActionSpecifics($type, $action, $target, $path));
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<int,string>
     */
    private function validateActionSpecifics(string $type, array $action, mixed $target, string $path): array
    {
        $errors = [];
        $meta = $action['meta'] ?? [];

        if ($target === ActionTarget::Components->value && empty($meta['component_types'])) {
            $errors[] = "{$path}: alvo 'components' exige meta.component_types (ex.: [\"print\"]).";
        }

        if ($type === 'percentage' && isset($action['value']) && (float) $action['value'] > 100) {
            $errors[] = "{$path}: percentual acima de 100 nao faz sentido.";
        }

        if ($type === 'tiered') {
            if (empty($meta['tiers']) || ! is_array($meta['tiers'])) {
                $errors[] = "{$path}: acao 'tiered' exige meta.tiers com as faixas.";
            } else {
                foreach ($meta['tiers'] as $i => $tier) {
                    if (! is_array($tier) || ! isset($tier['min'])) {
                        $errors[] = "{$path}: faixa [{$i}] precisa de 'min'.";
                    } elseif (! isset($tier['percent']) && ! isset($tier['amount_cents'])) {
                        $errors[] = "{$path}: faixa [{$i}] precisa de 'percent' ou 'amount_cents'.";
                    }
                }
            }
        }

        if ($type === 'component_unit_price' && ! isset($meta['unit_price_cents'])) {
            $errors[] = "{$path}: acao 'component_unit_price' exige meta.unit_price_cents.";
        }

        if ($type === 'buy_x_get_y') {
            foreach (['buy', 'free'] as $key) {
                if (isset($meta[$key]) && (int) $meta[$key] < 1) {
                    $errors[] = "{$path}: meta.{$key} precisa ser pelo menos 1.";
                }
            }
        }

        return $errors;
    }
}
