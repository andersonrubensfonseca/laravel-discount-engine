<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Registry;

use InvalidArgumentException;
use SolutionsTI\DiscountEngine\Core\Contracts\ConditionEvaluator;

/**
 * Mapa chave -> avaliador. Na camada Laravel isso e populado pelo
 * ServiceProvider (e o app hospedeiro pode registrar as proprias condicoes).
 */
final class ConditionRegistry
{
    /** @var array<string,ConditionEvaluator> */
    private array $evaluators = [];

    /** @param  array<int,ConditionEvaluator>  $evaluators */
    public function __construct(array $evaluators = [])
    {
        foreach ($evaluators as $evaluator) {
            $this->register($evaluator);
        }
    }

    public function register(ConditionEvaluator $evaluator): self
    {
        $this->evaluators[$evaluator::key()] = $evaluator;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->evaluators[$key]);
    }

    public function get(string $key): ConditionEvaluator
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Condicao nao registrada: [{$key}].");
        }

        return $this->evaluators[$key];
    }

    /** @return array<string,string> chave => rotulo, para montar o select do painel */
    public function options(): array
    {
        return array_map(
            static fn (ConditionEvaluator $e): string => $e::label(),
            $this->evaluators,
        );
    }
}
