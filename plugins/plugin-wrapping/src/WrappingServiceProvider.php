<?php

declare(strict_types=1);

namespace EcSugar\PluginWrapping;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「ラッピング・のし」（ADR 0078）。プラグインの登録簿に自分を登録する。
 * 表（wrapping_types・wrapping_listings・wrapping_orders）は店の DB に。注文の入力の欄と料金の行は核の料金の口（OrderChargeHandler）、
 * 商品ごとの「ラッピングできる」「のしを付けられる」は管理画面の商品の画面の欄（ADR 0050）、ラッピングの種類は App の画面（ADR 0051）、
 * のしの決まりは App の設定
 */
final class WrappingServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: WrappingCharges::PLUGIN,
            title: 'ラッピング・のし',
            description: 'ご注文の時に、ラッピングの種類とのし（表書き・外のし／内のし・名入れ）を選べるようにします。料金はご注文の合計に足します。選んだ内容は納品書と配送の CSV の備考にも載ります。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            listingPanel: new WrappingListingPanel,
            adminPage: new WrappingAdminPage,
            orderCharges: new WrappingCharges,
        ));
    }
}
