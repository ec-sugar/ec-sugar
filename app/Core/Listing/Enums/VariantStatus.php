<?php

declare(strict_types=1);

namespace App\Core\Listing\Enums;

/**
 * 種類の販売の状態。止めた種類はショップで選べない（在庫と取引の記録は残る）。
 */
enum VariantStatus: string
{
    case Active = 'active';
    case Stopped = 'stopped';
}
