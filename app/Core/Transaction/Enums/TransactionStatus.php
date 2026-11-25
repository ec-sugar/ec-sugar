<?php

declare(strict_types=1);

namespace App\Core\Transaction\Enums;

enum TransactionStatus: string
{
    case Open = 'open';
    case Completed = 'completed';  // 流れの最後まで来た（後から紛争・返金はありうる）
    case Cancelled = 'cancelled';  // 終わり
    case Refunded = 'refunded';    // 終わり

    public function isClosed(): bool
    {
        return $this === self::Cancelled || $this === self::Refunded;
    }
}
