<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginGift\Models\GiftOrder;

/**
 * 見せる状態と言葉（ADR 0055）。贈り物の行の状態に、注文の様子（店の人が注文の画面で取り消した・発送した）を重ねる
 */
final class GiftStatus
{
    private const array LABELS = [
        'preparing' => 'お支払いが完了すると、受け取りのリンクが表示されます（しばらくしてから、このページを再読み込みしてください）。',
        'waiting' => '受け取りのリンクができました。お相手がお届け先を入力するのをお待ちしています。',
        'claimed' => 'お相手がお届け先を入力しました。発送の準備をしています。',
        'shipped' => 'お相手へ発送しました。',
        'cancelling' => '取り消しと返金の手続きをしています。',
        'stopped' => '返金の手続きを完了できませんでした。お店からご連絡します。',
        'cancelled' => '贈り物を取り消し、お支払いいただいた分の返金の手続きをしました。',
        'expired' => '期限までにお届け先の入力がなかったため、ご注文を取り消して全額を返金しました。',
    ];

    public static function of(GiftOrder $gift): string
    {
        if (in_array($gift->status, ['cancelled', 'expired', 'cancelling', 'stopped'], true)) {
            return $gift->status;
        }

        // 店の人が注文の画面で取り消した（贈り物の行は毎時の処理が合わせる）
        $order = Transaction::query()->whereKey($gift->transaction_id)->first()?->status;

        if (! $order instanceof TransactionStatus || $order->isClosed()) {
            return 'cancelled';
        }

        if ($gift->status === 'claimed' && Fulfillment::query()->where('transaction_id', $gift->transaction_id)->whereIn('status', [FulfillmentStatus::Shipped, FulfillmentStatus::Completed])->exists()) {
            return 'shipped';
        }

        return $gift->status;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? '';
    }

    /**
     * 日付（店の時間で「2026年11月18日」）
     */
    public static function date(CarbonImmutable $at): string
    {
        return $at->timezone('Asia/Tokyo')->isoFormat('YYYY年M月D日');
    }
}
