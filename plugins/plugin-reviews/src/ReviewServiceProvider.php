<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews;

use App\Core\Plugin\AccountLink;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginReviews\Http\ReviewController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「商品レビュー」（ADR 0025）。プラグインの登録簿に自分を登録する。
 * 表（reviews）は店の DB に。書けるのは買った人だけ（会員はマイページ、ゲストはご注文の確認の署名つきの道筋から）、
 * 公開は店が決める（管理画面の App の画面。既定は「店が公開するまで出さない」）、ショップは商品の画面の説明の下（核の部品が描く）。どれも使う店だけ
 */
final class ReviewServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'reviews');

        $plugins->register(new PluginDefinition(
            name: 'reviews',
            title: '商品レビュー',
            description: '買ったお客さんが商品の画面に星と感想を書けます。出すかどうかはお店が決めます。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（目的 category と、公開の設定 publish）
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            storefrontRoutes: static function (): void {
                // 会員の注文とレビューの画面は、どれもブラウザが画面を開く時・画面のフォームから送る時だけ通す（ADR 0088）
                Route::middleware('navigation-only')->group(function (): void {
                    // 会員のマイページのレビュー（会員でなければログインへ）
                    Route::get('/account', [ReviewController::class, 'account'])->name('account');
                    // 1 つの注文のレビューと書く欄（会員の自分の注文か、署名と期限つきの道筋だけ。ほかはご注文の確認へ）
                    Route::get('/orders/{orderId}', [ReviewController::class, 'order'])->where('orderId', '[0-9A-Za-z]{26}')->middleware('secret-link')->name('order');
                    Route::get('/orders/{orderId}/{listingId}', [ReviewController::class, 'form'])->where(['orderId' => '[0-9A-Za-z]{26}', 'listingId' => '[0-9A-Za-z]{26}'])->middleware('secret-link')->name('form');
                    Route::post('/orders/{orderId}/{listingId}', [ReviewController::class, 'submit'])->where(['orderId' => '[0-9A-Za-z]{26}', 'listingId' => '[0-9A-Za-z]{26}'])
                        ->middleware(['throttle:storefront-cart', 'secret-link'])->name('submit');
                });
            },
            accountLink: new AccountLink('reviews', 'レビュー', '/p/reviews/account'),
            adminPage: new ReviewAdminPage,
            productPanel: new ReviewProductPanel,
            orderLinks: new ReviewOrderLink,
        ));
    }
}
