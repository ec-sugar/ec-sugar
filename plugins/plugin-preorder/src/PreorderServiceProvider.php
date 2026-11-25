<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「予約販売」（ADR 0073）。プラグインの登録簿に自分を登録する。
 * 表（preorder_listings・preorder_items）は店の DB に。ショップの予約の受け方と注文の確定の数え直しは核の口（PreorderHandler）、
 * 商品ごとの決まりは管理画面の商品の画面の欄（ADR 0050）、予約の商品の一覧と発売日の変更は App の画面（ADR 0051）
 */
final class PreorderServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'preorder');

        $plugins->register(new PluginDefinition(
            name: 'preorder',
            title: '予約販売',
            description: '発売前の商品の予約を、在庫がなくても受け付けます。お支払いはご注文の時で、発売日にまとめて発送します。予約の数の上限と締切日を決められ、発売日を変えると予約したお客さんにメールでお知らせします。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            listingPanel: new PreorderListingPanel,
            adminPage: new PreorderAdminPage,
            preorder: new PreorderCheckout,
            demoRestock: new PreorderDemoRestock,
        ));
    }
}
