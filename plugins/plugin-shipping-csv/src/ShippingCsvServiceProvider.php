<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「配送の CSV」（ADR 0025・0051）。プラグインの登録簿に自分を登録する。
 * 表（shipping_csv_*）は店の DB に。管理画面は App の画面（取り込んだ CSV の一覧と確かめの 1 件）と、CSV の書き出し・取り込みの口。
 * ショップには何も出さない。使う店だけ
 */
final class ShippingCsvServiceProvider extends ServiceProvider
{
    public const string NAME = 'shipping-csv';

    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: self::NAME,
            title: '配送の CSV',
            description: '発送待ちの注文を、運送会社の送り状ソフトに取り込める CSV で書き出します。送り状番号の CSV を取り込むと、まとめて発送済みにできます。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（目的 category と、ご依頼主・運送会社のコードの設定）
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            adminPage: new ShippingCsvAdminPage,
        ));
    }
}
