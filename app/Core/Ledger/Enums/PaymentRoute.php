<?php

declare(strict_types=1);

namespace App\Core\Ledger\Enums;

/**
 * お金の通り道（CONCEPT 5.4 の Payment）。
 */
enum PaymentRoute: string
{
    case Direct = 'direct';      // 受け取る側へ直接（店頭現金、出品者の決済アカウント）
    case Platform = 'platform';  // 決済代行が預かり、後で送金する（エスクロー）
}
