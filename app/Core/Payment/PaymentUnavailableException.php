<?php

declare(strict_types=1);

namespace App\Core\Payment;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * 今は支払い・返金に進めない（鍵が無い、決済代行に届かない、取引が払える状態でない）。メッセージはお客さん・運営に見せてよい文だけ。
 * 既定は 503（外のサービスや設定の都合）。もう済んでいるなど状態の衝突は conflict() で 409
 */
final class PaymentUnavailableException extends RuntimeException implements UserFacingException
{
    private int $status = 503;

    public static function conflict(string $message): self
    {
        $e = new self($message);
        $e->status = 409;

        return $e;
    }

    public function status(): int
    {
        return $this->status;
    }
}
