<?php

declare(strict_types=1);

namespace App\Core\Discount\Services;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * 注文の確定の時にクーポンが使えなくなった（回数の上限・止められた・1 人の回数）。文はお客さんに出す
 */
final class CouponRejected extends RuntimeException implements UserFacingException
{
    public function status(): int
    {
        return 409;
    }
}
