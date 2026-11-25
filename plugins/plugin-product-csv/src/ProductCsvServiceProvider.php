<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「商品の CSV」（ADR 0107 の段 1。元は核の app/Core/ProductCsv）。プラグインの登録簿に自分を登録する。
 *
 * ほかの App と違い、画面と受け口は核のまま（ADR 0036 の「App でなく核の画面にした」: ファイルを上げる・確かめの画面を挟む・
 * ファイルを返すは App の画面の口〔ADR 0051〕では描けない）。核の経路 /api/tenants/{店}/listings-csv… が
 * 使う店の印（plugin:product-csv）を通り、使わない店では 404 になる。記録の表（product_imports）も核の移行のまま
 * （ADR 0107 の段 1 は居場所と名前空間だけを動かし、DB の形は変えない）。
 * 動かすのは商品・種類・在庫・写真・カテゴリの核のサービスだけで、App は自分の表を持たない
 */
final class ProductCsvServiceProvider extends ServiceProvider
{
    public const string NAME = 'product-csv';

    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: self::NAME,
            title: '商品の CSV',
            description: '商品・種類・在庫・写真を CSV で書き出します。同じ形の CSV を取り込んで、まとめて登録・更新できます。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（目的 category だけ。設定もショップへの差し込みも無い）
            manifest: __DIR__.'/../composer.json',
        ));
    }
}
