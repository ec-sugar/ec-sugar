<?php

declare(strict_types=1);

namespace App\Core\Points\Services;

use App\Core\UserFacingException;
use DomainException;

/**
 * ポイントの決まりに反する操作（残高より多く使う・引く、会員でない台帳、使わない店で使う）。文はお客さんと店の人に見せてよい
 */
final class PointException extends DomainException implements UserFacingException
{
    public function status(): int
    {
        return 422;
    }
}
