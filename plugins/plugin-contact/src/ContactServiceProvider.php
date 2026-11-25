<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginContact\Console\PruneContactCommand;
use EcSugar\PluginContact\Http\ContactController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「お問い合わせ」（ADR 0025）。プラグインの登録簿に自分を登録する。
 * 表（お問い合わせと返信）は店の DB に。ショップは /p/contact のフォームと、商品の画面の「この商品について問い合わせる」（買い方の口。売り切れでも出す）・
 * ご注文の画面の「この注文について問い合わせる」（ご注文の画面のリンクの口。署名つき）。管理画面は App の画面（一覧・1 件・返信・状態。ADR 0051）。
 * 済んだお問い合わせは、毎日の contact:prune が店の決めた期間の後に消す。どれも使う店だけ（片付けは、使うのをやめた店でも残りがあれば）
 */
final class ContactServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'contact');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneContactCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('contact:prune')->dailyAt('04:40')->withoutOverlapping(120);
        });

        $plugins->register(new PluginDefinition(
            name: 'contact',
            title: 'お問い合わせ',
            description: 'ショップにお問い合わせのフォームを置きます。届いたお問い合わせは管理画面で読み、メールでお返事できます。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（目的 category と、知らせのアドレス・消すまでの期間の設定）
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            purchaseOptions: new ContactPurchaseOption,
            storefrontRoutes: static function (): void {
                // フォーム（そのまま・商品を付けて・注文を付けて〔署名つき〕）と、送った後の画面。
                // フォームは会員のメール・入れ直しの値・注文を出すので、ブラウザが画面を開く時・画面のフォームから送る時だけ（ADR 0088）
                Route::get('/', [ContactController::class, 'show'])->middleware('navigation-only')->name('form');
                Route::get('/sent', [ContactController::class, 'sent'])->name('sent');
                Route::get('/product/{listingId}', [ContactController::class, 'showProduct'])->where('listingId', '[0-9A-Za-z]{26}')->middleware('navigation-only')->name('product');
                Route::get('/order/{orderId}', [ContactController::class, 'showOrder'])->where('orderId', '[0-9A-Za-z]{26}')->middleware(['secret-link', 'navigation-only'])->name('order');
                // 送るのは、店 × 接続元で 1 分に 10 回まで（会員のログインと同じ枠。中でも接続元・メール・店全体で数える）
                Route::middleware(['navigation-only', 'throttle:storefront-member'])->group(function (): void {
                    Route::post('/', [ContactController::class, 'submit'])->name('submit');
                    Route::post('/product/{listingId}', [ContactController::class, 'submitProduct'])->where('listingId', '[0-9A-Za-z]{26}')->name('product.submit');
                    Route::post('/order/{orderId}', [ContactController::class, 'submitOrder'])->where('orderId', '[0-9A-Za-z]{26}')->middleware('secret-link')->name('order.submit');
                });
            },
            adminPage: new ContactAdminPage,
            orderLinks: new ContactOrderLink,
        ));
    }
}
