<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginOrderPrint\Http\ReceiptController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「納品書・領収書」。プラグインの登録簿に自分を登録する。
 * 管理画面は注文の画面の「納品書」「領収書」（核の注文の書類の口。新しいタブの印刷用のページ）、
 * お客さんはご注文の確認とマイページの履歴の「領収書」（払い済みの注文だけ。/p/order-print/receipt/…）。どれも使う店だけ。
 * 表（order_print_receipts）は領収書を出した回数だけを持つ
 */
final class OrderPrintServiceProvider extends ServiceProvider
{
    public const string NAME = 'order-print';

    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'order-print');

        $plugins->register(new PluginDefinition(
            name: self::NAME,
            title: '納品書・領収書',
            description: '注文の納品書と領収書を、印刷用のページで出します（ブラウザの印刷で紙か PDF に）。お客さんもマイページとご注文の確認から領収書を発行できます。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                // 領収書は名前と注文を出すので、ブラウザが画面を開く時・画面のフォームから送る時だけ通す（ADR 0088）
                Route::middleware('navigation-only')->group(function (): void {
                    // お客さんの領収書（署名つきのリンクからだけ。見てよいかは ReceiptController が確かめる）
                    Route::get('/receipt/{orderId}', [ReceiptController::class, 'show'])->where('orderId', '[0-9A-Za-z]{26}')->middleware('secret-link')->name('receipt');
                    Route::post('/receipt/{orderId}', [ReceiptController::class, 'show'])->where('orderId', '[0-9A-Za-z]{26}')->middleware(['throttle:storefront-cart', 'secret-link'])->name('receipt.issue');
                });
            },
            orderLinks: new ReceiptOrderLink,
            orderDocuments: new OrderPrintDocuments,
        ));
    }
}
