<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $rule_id
 * @property string      $code
 * @property int|null    $usage_limit
 * @property int         $used_count
 */
class DiscountCoupon extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'usage_limit' => 'integer',
        'usage_limit_per_customer' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('discount-engine.tables.coupons', 'discount_coupons');
    }

    /**
     * Normaliza o codigo na gravacao.
     *
     * Sem isso o comportamento MUDA conforme o banco: o MySQL costuma usar
     * collation case-insensitive (utf8mb4_unicode_ci) e casaria 'bemvindo'
     * com 'BEMVINDO'; o SQLite compara '=' de forma case-sensitive e nao
     * casaria. Guardar sempre em caixa alta remove a divergencia e mantem
     * o indice utilizavel na busca.
     */
    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null ? null : strtoupper(trim($value));
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DiscountRule::class, 'rule_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasGlobalCapacity(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }
}
