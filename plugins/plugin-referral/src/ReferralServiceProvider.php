<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral;

use App\Core\Event\DomainEventRecorded;
use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventReceivers;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Plugin\AccountLink;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Transaction\Events\FulfillmentShipped;
use App\Storefront\Access\ClientSource;
use Carbon\CarbonImmutable;
use EcSugar\PluginReferral\Console\RunReferralsCommand;
use EcSugar\PluginReferral\Http\ReferralController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * 公式の App「友だち紹介」（ADR 0075）。プラグインの登録簿に自分を登録する。
 * 表（紹介のリンクの印・紹介）は店の DB に。ショップは紹介のリンク（/p/referral/r/{印}）とマイページの「友だちを紹介する」、管理画面は App の画面（確かめ待ち・数・付けたポイント）。
 * ポイントは核の帳簿へ核の口（PointService::grant、理由 referral）で付ける。核の流れには手を入れず、核の知らせで知る:
 * 会員になった（customer_account.joined。その要求の紹介の印の Cookie を読む）・払われた（PaymentConfirmed）・発送した（FulfillmentShipped）・取り消した（transaction.phase_changed）。
 * どれも書き込みの確定の後に届く。受け手の中の誤りは外へ出さない（注文や発送の答えを変えない）。取りこぼしは毎時の referral:run が拾う
 */
final class ReferralServiceProvider extends ServiceProvider
{
    /** 確保していた注文が閉じる取引の段 */
    private const array CLOSING = ['cancel', 'refund'];

    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'referral');

        // 会員になった: この要求に紹介の印があれば結ぶ。書いた要求の Cookie が要るので、同じプロセスの中だけの知らせで受ける
        // （届かなければ結ばない。紹介の印は 1 回きりの目印で、届け直す先の要求が無い。ADR 0089）
        Event::listen(DomainEventRecorded::class, static function (DomainEventRecorded $e): void {
            self::safely(static function () use ($e): void {
                if ($e->event->type !== 'customer_account.joined' || ! app()->bound('request')) {
                    return;
                }

                $request = app(Request::class);
                $now = CarbonImmutable::now();
                $code = ReferralCookie::code($request, $now);

                if ($code !== null && app(ReferralSettings::class)->active()) {
                    app(Referrals::class)->link($e->event->aggregate_id, $code, ClientSource::of($request->ip()), $now);
                    // 印は 1 回で使い終わる（結べなかった時も。紹介されるのは会員になる時の 1 回だけ）
                    Cookie::queue(ReferralCookie::forget($request));
                }
            });
        });

        // ほかは名前のある受け手（コミットの後に送り出し役が届け、落ちたら届け直す）。どれも紹介の状態を錠の中で確かめてから進めるので、2 回届いても 1 回分だけ
        $receivers = $this->app->make(EventReceivers::class);

        // 注文が取り消された・返金で閉じた（App を止めていても、まだ付けていない紹介は付けないにする）
        $receivers->on('referral.closed', ['transaction.phase_changed'], static function (DomainEvent $event): void {
            if (($event->payload['kind'] ?? null) === 'sale' && in_array($event->payload['to'] ?? null, self::CLOSING, true)) {
                app(Referrals::class)->closed($event->aggregate_id);
            }
        });

        // 払われた: 紹介された人の最初に払われた注文を結ぶ
        $receivers->notice('referral.paid', PaymentConfirmed::class, static function (PaymentConfirmed $e): void {
            if (app(ReferralSettings::class)->enabled()) {
                app(Referrals::class)->paid($e->transactionId);
            }
        });

        // 発送した: 受渡が全部発送されたら付けるかを決める（App を止めていた時の発送は付けない）
        $receivers->notice('referral.shipped', FulfillmentShipped::class, static function (FulfillmentShipped $e): void {
            app(Referrals::class)->shipped($e->transactionId, app(ReferralSettings::class)->enabled());
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RunReferralsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('referral:run')->hourlyAt(45)->withoutOverlapping(60);
        });

        $plugins->register(new PluginDefinition(
            name: ReferralSettings::PLUGIN,
            title: '友だち紹介',
            description: '会員ごとの紹介のリンクから来た方が会員登録し、最初のご注文が発送されると、紹介した方と紹介された方の両方にポイントを付けます（ポイントを使う店だけ）。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                // 紹介のリンク（開くと印の Cookie を置いてトップへ）。印の総当たりを接続元ごとに絞る
                Route::get('/r/{code}', [ReferralController::class, 'open'])->where('code', ReferralCodes::PATTERN)->middleware('throttle:storefront-cart')->name('open');
                // マイページの「友だちを紹介する」（会員だけ。ほかはログインの画面へ）。会員の紹介の印を出すので、ブラウザが画面を開く時・画面のフォームから送る時だけ（ADR 0088）
                Route::get('/invite', [ReferralController::class, 'invite'])->middleware('navigation-only')->name('invite');
                Route::post('/invite/renew', [ReferralController::class, 'regenerate'])->middleware(['navigation-only', 'throttle:storefront-cart'])->name('invite.renew');
            },
            accountLink: new AccountLink(ReferralSettings::PLUGIN, '友だちを紹介する', '/p/referral/invite'),
            adminPage: new ReferralAdminPage,
        ));
    }

    /**
     * 知らせの受け手の中の誤りを外へ出さない（報告だけ）
     */
    private static function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $error) {
            report($error);
        }
    }
}
