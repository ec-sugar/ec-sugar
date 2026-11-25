<?php

declare(strict_types=1);

namespace EcSugar\PluginCatalogSort;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「並び順と絞り込み」（ADR 0025）。プラグインの登録簿に自分を登録する。
 * 表（catalog_sort_items）は店の DB に。管理画面は App の画面（一覧・1 件・上へ・下へ・外す。ADR 0051）、
 * ショップの一覧へは核の口（CatalogBrowseHandler）で並びと絞り込みを渡す（ショップの経路は持たない）。
 * どちらも、店が「使う」にした時だけ動く（止めれば一覧は今までの新着順・価格順に戻り、記録は残る）
 */
final class CatalogSortServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'catalog-sort',
            title: '並び順と絞り込み',
            description: 'ショップの商品一覧の並びをお店で決められます（おすすめ順）。お客さんは価格・タグ・在庫ありで絞り込めます。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（目的 products と、どの絞り込みを出すかの設定。登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            adminPage: new CatalogSortAdminPage,
            // 止めた店で、オーナーが選ぶと並びとタグを全部消す（ADR 0092）
            purger: new CatalogSortDataPurger,
            // ショップの一覧の並びと絞り込み。設定と表を読むのは、店の文脈の中で呼ばれた時だけ
            catalogBrowse: new CatalogSortBrowse,
        ));
    }
}
