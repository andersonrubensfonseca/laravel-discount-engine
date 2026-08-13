<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Result;

use SolutionsTI\DiscountEngine\Core\Allocation\DiscountAllocation;
use SolutionsTI\DiscountEngine\Core\Enums\ActionTarget;
use SolutionsTI\DiscountEngine\Core\Money\Money;

/**
 * Linha de desconto efetivamente concedida. Vira snapshot no pedido.
 *
 * Carrega a alocacao propria: para nota fiscal, e preciso saber nao so
 * "esta regra deu R$ 30" mas de qual item e de qual componente saiu.
 */
final class AppliedDiscount
{
    public function __construct(
        public readonly string|int $ruleId,
        public readonly string $ruleName,
        public readonly string $actionType,
        public readonly ActionTarget $target,
        public readonly Money $amount,
        public readonly ?string $couponCode = null,
        public readonly ?DiscountAllocation $allocation = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_name' => $this->ruleName,
            'action_type' => $this->actionType,
            'target' => $this->target->value,
            'amount_cents' => $this->amount->cents,
            'coupon_code' => $this->couponCode,
            'allocation' => $this->allocation?->toArray() ?? [],
        ];
    }
}
