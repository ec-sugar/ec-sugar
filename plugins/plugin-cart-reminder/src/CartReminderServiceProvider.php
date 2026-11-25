<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginCartReminder\Console\RunCartRemindersCommand;
use EcSugar\PluginCartReminder\Http\CartReminderController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「カゴ落ちのお知らせ」（ADR 0067）。プラグインの登録簿に自分を登録する。
 * 表（cart_reminders。注文ごとの送った記録）は店の DB に。お知らせは毎時の cart-reminders:run が積み、送る仕事が 1 通ずつ送る。
 * ショップは「カートに戻す」のリンクと配信の停止の /p/cart-reminder/… の画面。管理画面は App の画面（送った数・戻った数・払われた数。ADR 0051）。
 * 送るのは、お知らせのメールの受け取りに同意した人だけ（核の口 MarketingConsent。同意は「メルマガ・一斉メール」の App が持つ）
 */
final class CartReminderServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cart-reminder');

        if ($this->app->runningInConsole()) {
            $this->commands([RunCartRemindersCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('cart-reminders:run')->hourlyAt(35)->withoutOverlapping(60);
        });

        $plugins->register(new PluginDefinition(
            name: 'cart-reminder',
            title: 'カゴ落ちのお知らせ',
            description: '注文の手続きを始めたのに、お支払いの前で終わったお客さんへ、商品をカートに戻せるリンクをメールで 1 回お送りします。お知らせのメールに登録したお客さん（App「メルマガ・一斉メール」）だけに送ります。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                $token = '[A-Za-z0-9_-]{43}';
                // 配信の停止の画面（開くだけでは変えない。押して変える）と、押す（画面のボタンと、メールのソフトのワンクリック〔RFC 8058〕）。
                // メールのソフトは CSRF の合い言葉を持たないので、この 1 つだけ CSRF の確かめを外す（合い言葉は 256 ビットの乱数で、できるのは止めることだけ）。
                // 止める操作は断らないので、接続元の回数の上限も掛けない（メルマガの停止と同じ）
                Route::get('/unsubscribe/{token}', [CartReminderController::class, 'showUnsubscribe'])->where('token', $token)->middleware('secret-link')->name('unsubscribe');
                Route::post('/unsubscribe/{token}', [CartReminderController::class, 'unsubscribe'])->where('token', $token)->middleware('secret-link')->name('unsubscribe.submit')
                    ->withoutMiddleware(ValidateCsrfToken::class);
                // 「カートに戻す」（開くとカートを作り直してカートの画面へ。回数の上限は受け口の中で接続元ごとにも数える）
                Route::get('/{token}', [CartReminderController::class, 'open'])->where('token', $token)->middleware('throttle:storefront-cart')->middleware('secret-link')->name('open');
            },
            adminPage: new CartReminderAdminPage,
        ));
    }
}
