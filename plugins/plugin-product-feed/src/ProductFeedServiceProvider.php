<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginProductFeed\Http\FeedController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * App「商品フィード」。Google Merchant Center（Google ショッピング）と Meta のカタログ（Facebook・Instagram）が読みに来る、
 * ショップの商品の一覧の XML を出す。プラグインの登録簿に自分を登録する。
 * ショップの経路は /p/product-feed/{google|meta}/{トークン}.xml の 1 つ（核が storefront・public・plugin:product-feed の印を付ける）。
 * 管理画面は App の画面（URL・数・外した商品・出せない商品。ADR 0051）と商品の画面の欄（ADR 0050）。どれも使う店だけ
 */
final class ProductFeedServiceProvider extends ServiceProvider
{
    public const string NAME = 'product-feed';

    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: self::NAME,
            title: '商品フィード',
            description: 'Google ショッピング（Merchant Center）と Meta のカタログ（Facebook・Instagram）に商品を並べるための、商品の一覧の URL を出します。',
            pluginApi: 1,
            // 公開の経路: Google と Meta がログインなしで読みに来るため。守りは推測されないトークン（店の人が作り直せる）・回数の上限・
            // 公開している店の公開している商品だけ（FeedController）
            storefrontRoutes: static function (): void {
                Route::get('/{format}/{token}.xml', FeedController::class)
                    ->where(['format' => 'google|meta', 'token' => FeedTokens::PATTERN])
                    ->middleware('secret-link')->name('feed');
            },
            // 宣言は composer.json の extra.ec-sugar（目的と設定の項目。登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            listingPanel: new FeedListingPanel,
            adminPage: new FeedAdminPage,
        ));
    }
}
