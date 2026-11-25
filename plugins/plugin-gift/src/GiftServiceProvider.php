<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

use App\Core\Plugin\AccountLink;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginGift\Console\RunGiftsCommand;
use EcSugar\PluginGift\Http\GiftOrderController;
use EcSugar\PluginGift\Http\GiftReceiveController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「贈り物」（ADR 0055）。プラグインの登録簿に自分を登録する。
 * 表（gift_orders）は店の DB に。注文の入力の「贈り物として送る」は核の口（GiftHandler）、受け取りの画面と買う人の画面は /p/gift/…、
 * 期限の知らせと取り消しは毎時の gifts:run（使う店だけ）、管理画面は App の画面（受け取り待ちの一覧。ADR 0051）
 */
final class GiftServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'gift');

        if ($this->app->runningInConsole()) {
            $this->commands([RunGiftsCommand::class]);
        }

        // 期限の知らせと、期限を過ぎた贈り物の取り消し（毎時。使う店だけ）
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('gifts:run')->hourlyAt(25)->withoutOverlapping(60);
        });

        $plugins->register(new PluginDefinition(
            name: 'gift',
            title: '贈り物',
            description: 'お届け先の住所を知らなくても、贈り物として購入できます。お支払いの後にできる受け取りのリンクを相手に送ると、受け取る方がお届け先を入力します。期限までに入力がなければ、自動で注文を取り消して返金します。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                $order = '[0-9A-Za-z]{26}';
                $token = '[A-Za-z0-9_-]{43}';
                // 買う人の画面（受け取りのリンクを出す）と受け取る人の画面（入れた届け先を出す）は、どれもブラウザが画面を開く時・画面のフォームから送る時だけ通す（ADR 0088）
                Route::middleware('navigation-only')->group(function () use ($order, $token): void {
                    // 買う人の画面（会員のマイページ・注文ごとの画面。買う人だけ）
                    Route::get('/account', [GiftOrderController::class, 'account'])->name('account');
                    Route::get('/orders/{orderId}', [GiftOrderController::class, 'show'])->where('orderId', $order)->middleware('secret-link')->name('order');
                    Route::get('/orders/{orderId}/cancel', [GiftOrderController::class, 'confirmCancel'])->where('orderId', $order)->name('order.cancel');
                    Route::middleware('throttle:storefront-cart')->group(function () use ($order): void {
                        Route::post('/orders/{orderId}/renew', [GiftOrderController::class, 'renew'])->where('orderId', $order)->name('order.renew');
                        Route::post('/orders/{orderId}/cancel', [GiftOrderController::class, 'cancel'])->where('orderId', $order)->name('order.cancel.submit');
                    });
                    // 受け取る人の画面（リンクの token で開く。回数の上限は受け口の中で、接続元ごとと token ごとに）
                    Route::get('/{token}', [GiftReceiveController::class, 'show'])->where('token', $token)->middleware('secret-link')->name('receive');
                    Route::get('/{token}/confirm', [GiftReceiveController::class, 'confirm'])->where('token', $token)->middleware('secret-link')->name('receive.confirm');
                    Route::middleware('throttle:storefront-cart')->group(function () use ($token): void {
                        Route::post('/{token}', [GiftReceiveController::class, 'submit'])->where('token', $token)->middleware('secret-link')->name('receive.submit');
                        Route::post('/{token}/confirm', [GiftReceiveController::class, 'claim'])->where('token', $token)->middleware('secret-link')->name('receive.claim');
                    });
                });
            },
            accountLink: new AccountLink('gift', '贈り物', '/p/gift/account'),
            adminPage: new GiftAdminPage,
            // ご注文の画面（ご注文の確認・マイページの注文の履歴）の「贈り物」の入口（核の App のご注文のリンクの口）
            orderLinks: new GiftOrderLink,
            gift: new GiftCheckout,
        ));
    }
}
