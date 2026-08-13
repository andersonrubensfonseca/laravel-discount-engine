<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $trigger
 * @property int         $priority
 * @property array|null  $conditions
 * @property array       $actions
 */
class DiscountRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'active' => 'boolean',
        'stop_further_processing' => 'boolean',
        'priority' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('discount-engine.tables.rules', 'discount_rules');
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(DiscountCoupon::class, 'rule_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(DiscountUsage::class, 'rule_id');
    }
}
