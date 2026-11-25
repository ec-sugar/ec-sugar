<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Section\Models\ShopPage;
use Illuminate\Support\Facades\Route;

/**
 * プラグインのショップの経路を、核がプラグインの代わりに登録する（ADR 0025）。
 * /p/<名前> の下だけに置き（核の経路と重ならない）、storefront・public・plugin:<名前> の印を必ず付ける（使わない店では 404）。
 * 公開してよいかは、入れる前のプラグインの審査で確かめる。
 *
 * 検索に載せるショップのページ（ADR 0072。ShopPagesProvider）は、App が決めた頭（/news など）の下に、同じ印を付けて置く。
 * 頭は核の経路の頭（CORE_PREFIXES）と自由なページの予約語に重ならないことを登録で確かめる（PluginRegistry）
 */
final class PluginRoutes
{
    /**
     * 核のショップの経路の頭（道筋の最初の区切り）。ショップの経路を足したらここにも足す（テストが今の経路と比べる）
     */
    public const array CORE_PREFIXES = [
        'account', 'apps', 'cart', 'categories', 'checkout', 'consent', 'demo', 'legal', 'media', 'orders', 'p', 'pages', 'password',
        'postal', 'preview', 'products', 'robots.txt', 'sitemap.xml', 'theme', 'webhooks',
    ];

    /**
     * App を止めた店でも開く経路（keptStorefrontRoutes）に付ける印の名前（経路の action に App の名前を入れる）。
     * 使う店の印（plugin:<名前>）を付けないのは意図だと、全部の経路を調べるテスト（RouteAudit の規則 14）に伝える
     */
    public const string KEPT = 'ec_kept_plugin';

    public static function storefront(PluginRegistry $registry): void
    {
        foreach ($registry->all() as $plugin) {
            if ($plugin->storefrontRoutes !== null) {
                Route::middleware(['storefront', 'public', "plugin:{$plugin->name}"])
                    ->prefix("p/{$plugin->name}")
                    ->name("storefront.plugin.{$plugin->name}.")
                    ->group($plugin->storefrontRoutes);
            }

            // App を止めた店でも開く経路（もう払ったお客さんの受け取り）。公開の印だけで、使う店の印（plugin）は付けない
            if ($plugin->keptStorefrontRoutes !== null) {
                Route::group([
                    'middleware' => ['storefront', 'public'],
                    'prefix' => "p/{$plugin->name}",
                    'as' => "storefront.plugin.{$plugin->name}.",
                    self::KEPT => $plugin->name,
                ], $plugin->keptStorefrontRoutes);
            }

            if ($plugin->shopPages !== null) {
                $pages = $plugin->shopPages;
                Route::middleware(['storefront', 'public', "plugin:{$plugin->name}"])
                    ->prefix($pages->prefix())
                    ->name("storefront.plugin.{$plugin->name}.pages.")
                    ->group(static function () use ($pages): void {
                        $pages->routes();
                    });
            }
        }
    }

    /**
     * App のショップのページの頭に使えない言葉（核の経路の頭と、自由なページの予約語）
     *
     * @return list<string>
     */
    public static function reservedPrefixes(): array
    {
        return array_values(array_unique([...self::CORE_PREFIXES, ...ShopPage::RESERVED_SLUGS]));
    }
}
