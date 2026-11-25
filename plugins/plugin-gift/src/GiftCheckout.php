<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

use App\Core\Plugin\Contracts\GiftHandler;
use App\Core\Plugin\Gift\GiftNote;
use App\Core\Plugin\Gift\GiftShare;
use App\Models\Tenant;
use App\Storefront\Access\SecretLinks;
use App\Storefront\View\StorefrontUrls;
use EcSugar\PluginGift\Models\GiftOrder;

/**
 * 核の贈り物の口（GiftHandler。ADR 0055）の、この App の実装。注文の入力の後に差出人とメッセージを受け、払いが確かになったらリンクを作り、
 * 買う人に見せる様子（GiftShare）を作る。呼ばれるのは店の文脈の中だけ
 */
final class GiftCheckout implements GiftHandler
{
    /** 買う人の「作り直す・取り消す」の画面の署名つきリンクの期限（日。ご注文の確認のリンクと同じ） */
    public const int MANAGE_LINK_DAYS = 30;

    public function placed(string $transactionId, GiftNote $note, string $actor): void
    {
        app(GiftOrders::class)->placed($transactionId, $note, $actor);
    }

    public function paid(string $transactionId): void
    {
        app(GiftOrders::class)->paid($transactionId);
    }

    public function share(string $transactionId, bool $private): ?GiftShare
    {
        $gift = GiftOrder::query()->where('transaction_id', $transactionId)->first();
        $tenant = tenant();

        if ($gift === null || ! $tenant instanceof Tenant) {
            return null;
        }

        $status = GiftStatus::of($gift);
        $base = (string) StorefrontUrls::of($tenant, '');
        $manageable = in_array($status, ['preparing', 'waiting'], true);

        return new GiftShare(
            $status,
            GiftStatus::label($status),
            // 受け取りのリンクは、買う人だけが見る所（メール・ご注文の状況・この App の画面）にだけ
            url: $private && $status === 'waiting' && $gift->token_sealed !== null ? $base.'/p/gift/'.$gift->token_sealed : null,
            // 作り直す・取り消すの画面: 買う人だけが見る所には署名つきのリンク、注文を受け付けた画面には署名の無い道筋（このセッションで今買った注文だけが開ける）
            manageUrl: ! $manageable ? null : ($private ? $base.self::signedManagePath($transactionId) : '/p/gift/orders/'.$transactionId),
            expiresOn: $gift->expires_at === null ? null : GiftStatus::date($gift->expires_at),
            shopName: (string) $tenant->getAttribute('name'),
        );
    }

    /**
     * 「作り直す・取り消す」の画面の署名つきの道筋（署名はパスにだけ。キューの中ではショップのドメインを知らないため）
     */
    public static function signedManagePath(string $transactionId): string
    {
        return SecretLinks::signed('storefront.plugin.gift.order', ['orderId' => $transactionId], now()->addDays(self::MANAGE_LINK_DAYS));
    }
}
