<?php

declare(strict_types=1);

namespace App\Core\Ledger\Enums;

use App\Core\Transaction\Enums\PartyRole;

/**
 * 仕訳の口座。取引ごとの立場で表す（誰の口座かは取引の seller_id / customer_id で決まる）。
 * 残高が正 = その口座が受け取る権利を持つ、負 = 払う義務を負う。取引の全口座の合計は常に 0。
 */
enum LedgerAccount: string
{
    case Customer = 'customer';
    case Seller = 'seller';
    case Platform = 'platform';        // 運営（テナント）の手数料収入
    case Provider = 'provider';        // 決済代行が預かっている分（自社口座は経由しない）
    case Tax = 'tax';                  // 消費税の納付先
    case Withholding = 'withholding';  // 源泉所得税の納付先

    public static function of(PartyRole $role): self
    {
        return match ($role) {
            PartyRole::Customer => self::Customer,
            PartyRole::Seller => self::Seller,
        };
    }
}
