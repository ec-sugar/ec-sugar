<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Storefront\Access\SecretLinks;
use App\Storefront\Access\ShopPasswordCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ショップの関所。準備中のショップは、パスワードを入れた人（ShopPasswordCookie）のほかは「準備中です」の画面へ送る。
 * 店頭のミドルウェア群（'storefront'）の最後に置くので、ショップの画面・カート・注文・ご注文の確認・プラグインの画面はすべてこの内側。
 * 外にあるのは、セッションを持たない群（写真・App のスクリプト・決済代行の通知。bootstrap/app.php の storefront-media）とヘルスチェック（/up）と、
 * 下の OUTSIDE に書いた経路だけ（運営の判断、2026-10-30）。ほかの経路を外に出すときは、ここに理由と一緒に足す。
 * 準備中の応答には、入れた人の分・外に出した経路の分も含めて noindex を付ける（検索に載せない）
 */
final class RequireShopAccess
{
    public const string ROBOTS = 'noindex, nofollow';

    /**
     * 準備中でも関所の外に置く経路（名前）。どれも、関所の内側で始めたことの続きか、法律で常に見せる情報か、運営が作った署名つきのリンク
     */
    public const array OUTSIDE = [
        // 「準備中です」の画面とパスワード
        'storefront.password',
        'storefront.password.unlock',
        // Stripe の支払いの画面から戻る先（このセッションで確定した注文だけを出す。Cookie の期限が切れていても戻れるように）
        'storefront.checkout.complete',
        // ご注文の確認のメールの署名つきリンク（署名の正しいものだけ。下の handle で確かめる。照会の画面と照会の送信は内側）
        'storefront.orders.show',
        // 特定商取引法に基づく表記（法律で常に見せる情報）
        'storefront.legal',
        // 商品のプレビュー。署名つきの期限つきのリンクで、その商品の画面だけ。署名が合わなければ受け口が「準備中です」へ送る
        'storefront.preview',
        // テーマの下書きのプレビュー（ADR 0039 区切り 2）。署名つきの期限つきのリンクで、そのテーマのそのページだけ。署名が合わなければ受け口が「準備中です」へ送る
        'storefront.preview.theme',
        // 区画の試し見の断片。同じく署名つきの期限つきの道筋で、区画 1 つを見本の値で描くだけ。署名が合わなければ受け口が中身の無い 404 を返す
        'storefront.preview.theme.section',
        // App の配信の停止のリンク（App「メルマガ・一斉メール」）。届いたメールの停止は、ショップが準備中に戻っても必ず受ける
        // （特定電子メール法の受信拒否の通知。メールのソフトのワンクリックも含む）。合い言葉の合うその 1 人の購読を止めるだけ。
        // 名前は App まで書く（「*」にすると、ほかの App が同じ名前の経路を足しただけで関所の外に出てしまう）
        'storefront.plugin.newsletter.unsubscribe',
        'storefront.plugin.newsletter.unsubscribe.submit',
        // App「カゴ落ちのお知らせ」（ADR 0067）のお知らせのメールの配信の停止。上と同じ理由で、準備中でも受ける
        'storefront.plugin.cart-reminder.unsubscribe',
        'storefront.plugin.cart-reminder.unsubscribe.submit',
        // App「お気に入り」（ADR 0071）の値下げ・再入荷のお知らせのメールの配信の停止。上と同じ理由で、準備中でも受ける
        'storefront.plugin.favorites.unsubscribe',
        'storefront.plugin.favorites.unsubscribe.submit',
        // App「LINE 連携」（ADR 0070）の Webhook の受付。送るのは LINE で、友だちの追加・ブロックの知らせ（送ってよい相手か）を準備中でも受ける。
        // 受け口が Messaging API のチャネルシークレットで署名を確かめ、合わなければ何もしない。つないだ会員の「友だちか」の印を変えるだけ
        'storefront.plugin.line.webhook',
    ];

    public function __construct(private readonly StorefrontAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->access->current();

        if ($state['status'] === ShopStatus::Open) {
            return $next($request);
        }

        $granted = ShopPasswordCookie::grants($request, (string) tenant()?->getTenantKey(), $state['generation'], $state['key']);

        if ($granted || self::outside($request)) {
            $response = $next($request);
        } elseif ($request->expectsJson()) {
            $response = response()->json(['message' => 'ショップは準備中です。'], 403);
        } elseif ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            // 入れた後に戻る先（このホストの URL だけ。Laravel が組み立てる）
            $response = redirect()->guest(route('storefront.password'));
        } else {
            $response = redirect()->route('storefront.password', status: 303);
        }

        $response->headers->set('X-Robots-Tag', self::ROBOTS);

        return $response;
    }

    private static function outside(Request $request): bool
    {
        if (! $request->routeIs(...self::OUTSIDE)) {
            return false;
        }

        // ご注文の確認は、確認のメールの署名つきリンクだけ（署名の無い・違う・期限切れのリンクは内側）
        return ! $request->routeIs('storefront.orders.show') || SecretLinks::valid($request);
    }
}
