<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Core\Enums;

/**
 * Como as regras de um mesmo grupo competem entre si.
 *
 * FirstByPriority  a primeira elegivel na ordem de prioridade leva; as
 *                  demais sao rejeitadas. Bom para "so um desconto de frete".
 *
 * HighestDiscount  todas as elegiveis do grupo sao simuladas e vence a que
 *                  der o maior desconto ao cliente. E o que a maioria das
 *                  lojas quer dizer com "vale o melhor desconto".
 *
 * A diferenca importa: com FirstByPriority, uma regra de 5% cadastrada com
 * prioridade menor ganha de uma de 20%. O motor nao compara valores.
 */
enum ResolutionStrategy: string
{
    case FirstByPriority = 'first_by_priority';
    case HighestDiscount = 'highest_discount';

    public function label(): string
    {
        return match ($this) {
            self::FirstByPriority => 'A primeira por prioridade',
            self::HighestDiscount => 'A que der o maior desconto',
        };
    }
}
