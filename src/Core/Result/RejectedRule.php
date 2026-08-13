<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Result;

use SolutionsTI\DiscountEngine\Core\Enums\RejectionReason;

/** Regra candidata que nao entrou, com o porque. Alimenta o simulador. */
final class RejectedRule
{
    public function __construct(
        public readonly string|int $ruleId,
        public readonly string $ruleName,
        public readonly RejectionReason $reason,
        public readonly ?string $detail = null,
    ) {
    }

    public function message(): string
    {
        return $this->detail ?? $this->reason->label();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'reason' => $this->reason->value,
            'message' => $this->message(),
        ];
    }
}
