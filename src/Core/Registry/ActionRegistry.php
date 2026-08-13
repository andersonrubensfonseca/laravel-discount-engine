<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Registry;

use InvalidArgumentException;
use SolutionsTI\DiscountEngine\Core\Contracts\DiscountAction;

final class ActionRegistry
{
    /** @var array<string,DiscountAction> */
    private array $actions = [];

    /** @param  array<int,DiscountAction>  $actions */
    public function __construct(array $actions = [])
    {
        foreach ($actions as $action) {
            $this->register($action);
        }
    }

    public function register(DiscountAction $action): self
    {
        $this->actions[$action::key()] = $action;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    public function get(string $key): DiscountAction
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Acao de desconto nao registrada: [{$key}].");
        }

        return $this->actions[$key];
    }

    /** @return array<string,string> */
    public function options(): array
    {
        return array_map(
            static fn (DiscountAction $a): string => $a::label(),
            $this->actions,
        );
    }
}
