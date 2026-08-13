<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Support;

use SolutionsTI\DiscountEngine\Core\Enums\RejectionReason;
use SolutionsTI\DiscountEngine\Core\Money\Money;
use SolutionsTI\DiscountEngine\Core\Result\DiscountResult;

/** Resposta da validacao de cupom, pronta para virar JSON no checkout. */
final class CouponValidation
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?Money $discount = null,
        public readonly ?RejectionReason $reason = null,
        public readonly ?string $message = null,
        public readonly ?DiscountResult $result = null,
    ) {
    }

    public static function accepted(Money $discount, DiscountResult $result): self
    {
        return new self(accepted: true, discount: $discount, result: $result);
    }

    public static function rejected(RejectionReason $reason, ?DiscountResult $result = null): self
    {
        return new self(
            accepted: false,
            reason: $reason,
            message: $reason->label(),
            result: $result,
        );
    }

    public static function invalid(string $message): self
    {
        return new self(accepted: false, message: $message);
    }

    public function message(): string
    {
        return $this->message ?? $this->reason?->label() ?? 'Cupom invalido.';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'discount_cents' => $this->discount?->cents,
            'reason' => $this->reason?->value,
            'message' => $this->accepted ? null : $this->message(),
        ];
    }
}
