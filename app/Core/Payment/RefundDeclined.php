<?php

declare(strict_types=1);

namespace App\Core\Payment;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * 決済代行が返金をはっきり断った（ADR 0027）。答えが分からない（通信の失敗など）とは分ける。
 * この場合、返金の記録は failed になり、注文は取り消さない
 */
final class RefundDeclined extends RuntimeException implements UserFacingException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Stripe が返金を受け付けませんでした（{$reason}）。Stripe のダッシュボードで支払いの状態を確認してください。");
    }

    public function status(): int
    {
        return 409;
    }
}
