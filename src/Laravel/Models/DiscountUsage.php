<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property string      $order_id
 * @property int         $amount_cents
 * @property array|null  $snapshot
 */
class DiscountUsage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'snapshot' => 'array',
        'amount_cents' => 'integer',
    ];

    public function getTable(): string
    {
        return config('discount-engine.tables.usages', 'discount_usages');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DiscountRule::class, 'rule_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(DiscountCoupon::class, 'coupon_id');
    }
}
