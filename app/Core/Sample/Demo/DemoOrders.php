<?php

declare(strict_types=1);

namespace App\Core\Sample\Demo;

use App\Core\RateLimit\Throttle;

/**
 * デモの店の注文の数の上限（ADR 0086）。来訪者（受け箱）ごとに 1 日 5 件まで。
 *
 * デモの店では銀行振込・代引き・テストのカードで、払わずに（お金を動かさずに）在庫を押さえられる。1 人の来訪者が売り切れにしないよう、
 * 注文を作る前に数える（カートの注文の確定と、App の 1 品の注文〔チケット・予約・定期便の申し込み〕）。それでも合い言葉を作り直せば
 * 別の来訪者になれるので、在庫は 1 時間ごとに作った時の数へ戻す（demo:restock）。両方で守る
 *
 * - 来訪者は注文のメールアドレスから見分ける（デモの店の入力のアドレスは来訪者のアドレスに置き換わる。会員も同じアドレス）。
 *   来訪者のアドレスでなければ、そのアドレスのハッシュで数える
 * - 先に足してから比べる（同時に送っても上限を超えない。確定できなかった試みも数える。0 円の注文の上限〔〕と同じ形）。
 *   数えるのは核の Throttle（ADR 0081。キーは Throttle::shopKey で店の ID が必ず入る）
 * - デモでない店では何もしない（いつも通す）
 */
final class DemoOrders
{
    public const int PER_VISITOR_PER_DAY = 5;

    public const string REFUSED = 'デモのお店では、ご注文は 1 日 5 件までです。明日またお試しください（お店の記録は毎晩、最初の状態に戻ります）。';

    /**
     * この来訪者の注文を、もう 1 件受けてよいか（数を 1 つ足す）
     */
    public static function admit(string $email): bool
    {
        if (! DemoShop::active()) {
            return true;
        }

        $mailbox = DemoVisitors::mailboxOf($email) ?? 'mail-'.hash('sha256', mb_strtolower(trim($email)));

        return Throttle::allow(Throttle::shopKey('demo-orders', $mailbox), self::PER_VISITOR_PER_DAY, 86400);
    }
}
