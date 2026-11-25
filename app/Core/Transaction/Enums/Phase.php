<?php

declare(strict_types=1);

namespace App\Core\Transaction\Enums;

/**
 * 取引のフェーズの語彙（CONCEPT 5.4）。縦はこの語彙を並べ替えて使い、新しいフェーズは足さない。
 * 主フェーズの並びは TransactionFlow が決める。横断フェーズ（取消・紛争・返金）の規則は核が持つ。
 */
enum Phase: string
{
    case Quote = 'quote';              // 確定前の提示（査定額、見積、提案）
    case Agreement = 'agreement';      // 双方の合意。金額と条件が固定される
    case Payment = 'payment';          // 支払い
    case Fulfillment = 'fulfillment';  // 受渡
    case Acceptance = 'acceptance';    // 受取確認・検収
    case Settlement = 'settlement';    // 精算
    case Cancel = 'cancel';            // 横断: 取消（精算前）
    case Dispute = 'dispute';          // 横断: 紛争（解決まで止める）
    case Refund = 'refund';            // 横断: 返金（取引を巻き戻して終える）

    public function isCrossCutting(): bool
    {
        return match ($this) {
            self::Cancel, self::Dispute, self::Refund => true,
            default => false,
        };
    }
}
