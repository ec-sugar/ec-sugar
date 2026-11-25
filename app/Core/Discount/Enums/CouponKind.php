<?php

declare(strict_types=1);

namespace App\Core\Discount\Enums;

enum CouponKind: string
{
    case Fixed = 'fixed';               // 円
    case Percent = 'percent';           // %（円未満は切り捨て）
    case FreeShipping = 'free_shipping';
}
