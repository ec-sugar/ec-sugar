<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「公開の予約と販売期間」（ADR 0025）。商品ごとに「この日時から出す」「この日時で終わる」を決める。
 *
 * - 期間の前: ショップの一覧・検索・商品の画面・サイトマップ・フィードに出さない（核の口 ListingVisibilityProvider）
 * - 期間の中: ふだんどおり
 * - 期間の後: 商品の画面は売り切れ（同じ口）。カートに入らない・注文を確定しない（核の口 SaleHoldProvider。断りの文つき）
 * - 日時は商品の画面の App の欄で決め（日本時間で入れて UTC で置く）、App の画面で予約の一覧を見る
 *
 * 核の表（listings）は変えない（商品の状態 draft / active / archived はそのまま）。期間は App の表（sale_period_windows）だけに持つ。
 * 店が App を止めると期間は効かなくなり、商品はふだんどおり売れる（行は残るので、使うに戻せば元の期間に戻る。ADR 0092）
 */
final class SalePeriodServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'sale-period',
            title: '公開の予約と販売期間',
            description: '商品ごとに「ショップに公開する日時」と「販売を終える日時」を決めます。公開する日時まではショップに表示せず、販売を終える日時になると売り切れになります。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（目的 category など。登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            // ショップの経路は持たない（お客さんに見せる画面が無い。効くのは核の商品の読み出しとカートの中）
            listingPanel: new SalePeriodListingPanel,
            adminPage: new SalePeriodAdminPage,
            // 期間の外の商品は、カートにもほかの App の 1 品の注文にも入れない（ADR 0080）
            saleHold: new SalePeriodSaleHold,
            // 止めた店で、オーナーが選ぶと期間の行を消す（ADR 0092）
            purger: new SalePeriodDataPurger,
            // 期間の前は出さない・期間の後は売り切れ（見せ方）
            listingVisibility: new SalePeriodVisibility,
        ));
    }
}
