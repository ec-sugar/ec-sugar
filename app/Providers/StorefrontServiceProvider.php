<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Discount\Services\CouponService;
use App\Core\Event\Outbox\EventReceivers;
use App\Core\Order\Gifts;
use App\Core\Party\Services\CustomerSessions;
use App\Core\Payment\Events\PaymentAwaiting;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Events\PaymentLapsed;
use App\Core\Payment\Events\RefundSettled;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Events\FulfillmentShipped;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Orders\SendOrderMail;
use App\Storefront\Postal\PostalLookup;
use App\Storefront\Postal\ZipcloudPostalLookup;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * 店頭（ADR 0018）。テーマの登録簿を 1 つだけ持ち、テーマのパッケージが自分のサービスプロバイダの boot で登録する。
 */
final class StorefrontServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ThemeRegistry::class);
        // 郵便番号から住所。差し替えるときはここ
        $this->app->bind(PostalLookup::class, ZipcloudPostalLookup::class);
    }

    public function boot(): void
    {
        // 店頭の核の部品（<x-storefront::…>）。テーマが同じ描き方で使う（商品の説明のブロックなど。ADR 0032）
        Blade::anonymousComponentPath(resource_path('views/storefront/components'), 'storefront');

        // ブラウザのテストだけ: 区画を並べられる見本のテーマ（config/storefront.php の sample_theme。本番では偽）。自分の CSS は持たないので、入口は核の部品にしておく
        $sample = base_path('tests/Support/Themes/sample');
        if (config('storefront.sample_theme') === true && is_dir($sample)) {
            View::addNamespace('theme-sample', "{$sample}/views");
            $this->app->make(ThemeRegistry::class)->register(new ThemeDefinition('sample', '見本のテーマ（テスト用）', ThemeRegistry::API_VERSION, 'theme-sample', StorefrontView::SCRIPT, manifest: "{$sample}/theme.json"));
        }

        // ショップの会員（auth:customer。ADR 0044 の 3）: 印の Cookie から店の DB の会員を引く。店の文脈の外（中央のホスト）では誰でもない
        Auth::viaRequest('storefront-member', static function (Request $request): ?Member {
            if (! tenancy()->initialized) {
                return null;
            }

            $token = MemberCookie::token($request);
            $resolved = app(CustomerSessions::class)->resolveAndRenew($token);

            if ($resolved === null || $token === null) {
                return null;
            }

            // 延ばした時は Cookie も出し直す（ブラウザの期限も、使っている間は延びる）
            if ($resolved[1]) {
                Cookie::queue(MemberCookie::make($request, $token));
            }

            return new Member($resolved[0], (string) tenant()?->getTenantKey());
        });

        // 核の知らせの受け手（ADR 0089）。書き込みと同じトランザクションで残した知らせを、コミットの後に送り出し役が届ける（落ちたら見回りが届け直す）。
        // どれも同じ知らせが 2 回届いても同じ結果になる（メールは送った記録 sent_mails の一意と行のロックで 1 回だけ、クーポンは「予約中」の時だけ「使った」に、贈り物のリンクは「準備中」の時だけ作る）
        $receivers = $this->app->make(EventReceivers::class);
        // 贈り物の注文（ADR 0055）は、先に App が受け取りのリンクを作る（知らせのメールに載せる。同じ知らせの受け手は登録した順に呼ぶ）。
        // 作れなくても知らせは送る（リンクは届け直しと App の毎日の処理が作り直す）
        $receivers->notice('core.gift-link', PaymentConfirmed::class, static fn (PaymentConfirmed $e) => app(Gifts::class)->paid($e->transactionId));
        // お客さんへの注文の知らせ。キューで送る
        $receivers->notice('core.order-mail.paid', PaymentConfirmed::class, static fn (PaymentConfirmed $e) => SendOrderMail::dispatch($e->transactionId, 'order_paid'));
        // 払いが届いた注文のクーポンを「使った」に（ADR 0054）。代引きの注文は払う前なので、入金を確かめた時に（ADR 0074）
        $receivers->notice('core.coupon-used', PaymentConfirmed::class, static function (PaymentConfirmed $e): void {
            if (! $e->onDelivery) {
                app(CouponService::class)->markUsed($e->transactionId);
            }
        });
        // コンビニ払い・銀行振込の入金待ちと、期限までに払われず取り消した知らせ（ADR 0058・0074。コンビニ払いの払い方そのものは Stripe の払込票の画面で、銀行振込は振込先をメールに）
        $receivers->notice('core.order-mail.awaiting', PaymentAwaiting::class, static fn (PaymentAwaiting $e) => SendOrderMail::dispatch($e->transactionId, 'order_awaiting_payment'));
        $receivers->notice('core.order-mail.lapsed', PaymentLapsed::class, static fn (PaymentLapsed $e) => SendOrderMail::dispatch($e->transactionId, 'order_payment_lapsed'));
        $receivers->notice('core.order-mail.shipped', FulfillmentShipped::class, static fn (FulfillmentShipped $e) => SendOrderMail::dispatch($e->transactionId, 'order_shipped', $e->fulfillmentId));
        // 返品・一部返金の返金が確かになった知らせ（ADR 0048。額とカードへの戻りの日数。取消・送料の差額は送らない）
        $receivers->notice('core.order-mail.refunded', RefundSettled::class, static function (RefundSettled $e): void {
            if ($e->purpose === 'return') {
                SendOrderMail::dispatch($e->transactionId, 'order_refunded', refundId: $e->refundId);
            }
        });
    }
}
