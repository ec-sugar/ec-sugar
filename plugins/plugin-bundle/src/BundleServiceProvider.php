<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「セット商品」（ADR 0025）。複数の品をまとめて 1 つの値段で売り、**在庫は構成の品から引く**。
 *
 * - 在庫: セットそのものは在庫を持たず、核の口（StockComponentsProvider）で「どの品からいくつ引くか」を答える。
 *   押さえる・解く・出庫するは核（LineStock）が 1 か所で行い、1 つでも足りなければ丸ごと断る
 * - 見せ方: 1 セットも作れないセットはショップに出さない（核の口 ListingVisibilityProvider）。
 *   構成の品が消えた・下書き・種類を止めた時も出さない
 * - 入れ子: セットの中にセットは入れられない（核は 1 段しか辿らないので、保存のときに断る。DB の引き金でも断る）
 * - 中身を決めるのは商品の画面の App の欄、一覧は App の画面
 *
 * 核の表（listings・listing_variants・inventory_stocks）は読むだけで変えない。中身は App の表（bundle_components）だけに持つ。
 * 店が App を止めるとセットは効かなくなり、セットの商品は自分の在庫で売れるふつうの商品になる（行は残るので、使うに戻せば元に戻る。ADR 0092）
 */
final class BundleServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'bundle',
            title: 'セット商品',
            description: '複数の商品をまとめて 1 つの値段で売ります。在庫はセットではなく構成の品から引き、どれか 1 つでも在庫が足りなくなると、セットをショップに表示しません。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（目的 category など。登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            // ショップの経路は持たない（お客さんに見せる画面が無い。効くのは核の在庫と商品の読み出し）
            listingPanel: new BundleListingPanel,
            adminPage: new BundleAdminPage,
            // セットの在庫は構成の品から引く（で核に足した口）
            stockComponents: new BundleStockComponents,
            // 止めた店で、オーナーが選ぶとセットの中身を消す（ADR 0092）
            purger: new BundleDataPurger,
            // 1 セットも作れないセットはショップに出さない（口）
            listingVisibility: new BundleVisibility,
        ));
    }
}
