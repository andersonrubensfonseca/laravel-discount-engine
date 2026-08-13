<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use SolutionsTI\DiscountEngine\Laravel\DiscountManager;

/**
 * @method static \SolutionsTI\DiscountEngine\Core\Result\DiscountResult calculate(\SolutionsTI\DiscountEngine\Core\Context\CartContext $cart)
 * @method static \SolutionsTI\DiscountEngine\Laravel\Support\CouponValidation validateCoupon(string $code, \SolutionsTI\DiscountEngine\Core\Context\CartContext $cart)
 * @method static bool reserve(\SolutionsTI\DiscountEngine\Core\Result\DiscountResult $result, string $orderId, string|int|null $customerId = null)
 *
 * @see DiscountManager
 */
final class Discounts extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DiscountManager::class;
    }
}
