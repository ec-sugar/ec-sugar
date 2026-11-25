<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Core\UserFacingException;
use DomainException;

/**
 * 運営のメンバーと権限の決まりに反する操作（最後のオーナーを外す、自分を止める、知らない役割など）。文は見せてよい
 */
final class AccessRuleException extends DomainException implements UserFacingException
{
    public function status(): int
    {
        return 409;
    }
}
