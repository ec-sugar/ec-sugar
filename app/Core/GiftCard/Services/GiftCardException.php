<?php

declare(strict_types=1);

namespace App\Core\GiftCard\Services;

use App\Core\UserFacingException;
use DomainException;

/**
 * ギフトカードの決まりに反する操作（止めた・期限の過ぎたカードを使う、残高より多く使う・引く）。文はお客さんと店の人に見せてよい（コードは含めない）
 */
final class GiftCardException extends DomainException implements UserFacingException
{
    public function status(): int
    {
        return 422;
    }
}
