<?php

declare(strict_types=1);

namespace App\Core\Party\Services;

use App\Core\UserFacingException;
use DomainException;

/**
 * お客さん（会員）の規則に反する操作（届け先の数の上限、ほかの会員の届け先、会員でない台帳への書き込みなど）。文はお客さんに見せてよい
 */
final class CustomerException extends DomainException implements UserFacingException
{
    public function status(): int
    {
        return 422;
    }
}
