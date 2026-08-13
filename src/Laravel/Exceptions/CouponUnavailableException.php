<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Exceptions;

use RuntimeException;

/**
 * Sinaliza que um cupom esgotou entre a simulacao e o fechamento.
 *
 * Existe para forcar o rollback da transacao: `return false` de dentro de
 * DB::transaction() commita normalmente, o que deixaria os usos ja gravados
 * das regras anteriores consumindo cupom de um pedido que nunca fechou.
 *
 * E capturada dentro do proprio UsageReserver e convertida em `false` —
 * nao vaza para quem chama.
 */
final class CouponUnavailableException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self("O cupom [{$code}] esgotou entre a simulacao e o fechamento.");
    }
}
