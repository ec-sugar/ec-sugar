<?php

declare(strict_types=1);

namespace App\Core\Ledger\Enums;

/**
 * 仕訳の種別（CONCEPT 5.5）。charge と tax は合意から自動で作り、残りは決済のドライバや運用が記録する。
 */
enum JournalType: string
{
    case Charge = 'charge';            // 請求（合意した金額の債権・債務）
    case Tax = 'tax';                  // 消費税（受け取る側が納める義務）
    case Payment = 'payment';          // 支払いの受領（直接、または決済代行が預かる）
    case Fee = 'fee';                  // プラットフォーム手数料
    case Withholding = 'withholding';  // 源泉徴収
    case Payout = 'payout';            // 送金（決済代行から受け取る側へ）
    case Refund = 'refund';            // 返金（お金を戻す）
    case Adjustment = 'adjustment';    // 調整（一部返金・訂正）
}
