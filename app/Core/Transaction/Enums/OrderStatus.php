<?php

declare(strict_types=1);

namespace App\Core\Transaction\Enums;

/**
 * 物販（sale）の対応の状況。取引のフェーズと受渡の状態から決まる見せ方で、保存しない。
 * 言葉は日本の EC の受注の管理でよく使うものに寄せる（新規受付・入金済み・発送済み・注文取消し・返品）
 */
enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment'; // 入金待ち（注文は確定、支払いはまだ）
    case Paid = 'paid';                        // 入金済み（発送の準備）
    case Confirmed = 'confirmed';              // 受付済み（合計 0 円で支払いが要らない。発送の準備）
    case CashOnDelivery = 'cod_pending';       // 代引き（払う前に発送の準備。代金は届けた時。ADR 0074）
    case PayOnSite = 'on_site_pending';        // 当日払い（予約。払う前に予約が確定し、代金は来店の時にお店で。ADR 0056）
    case Shipped = 'shipped';                  // 発送済み
    case Completed = 'completed';              // 完了（お客さんに届いた）
    case Cancelled = 'cancelled';              // 注文取消し
    case Refunded = 'refunded';                // 返金

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => '入金待ち',
            self::Paid => '入金済み',
            self::Confirmed => '受付済み',
            self::CashOnDelivery => '代引き・発送待ち',
            self::PayOnSite => '当日払い・来店待ち',
            self::Shipped => '発送済み',
            self::Completed => '完了',
            self::Cancelled => '注文取消し',
            self::Refunded => '返金',
        };
    }
}
