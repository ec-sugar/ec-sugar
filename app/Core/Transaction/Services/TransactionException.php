<?php

declare(strict_types=1);

namespace App\Core\Transaction\Services;

use App\Core\UserFacingException;
use DomainException;

/**
 * 取引の規則に反する操作（許されない遷移、締まった取引への変更、他の出品者の出品物など）。文は運営に見せてよい
 */
final class TransactionException extends DomainException implements UserFacingException
{
    public function status(): int
    {
        return 409;
    }
}
