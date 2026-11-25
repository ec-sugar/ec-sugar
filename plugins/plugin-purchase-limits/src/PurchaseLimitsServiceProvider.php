<?php

declare(strict_types=1);

namespace EcSugar\PluginPurchaseLimits;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 公式の App「購入の制限」（ADR 0025）。1 回のご注文の決まりを 2 つ決める。
 *
 * - 商品ごとの「1 回のご注文の上限」（1〜999）: 商品の画面の App の欄で決める。種類が違っても同じ商品は合わせて数える
 * - 店の「最低購入金額」（円）: App の設定で決める。カートの商品の合計（値引き・送料の前）と比べる
 *
 * どちらも核の口（PurchaseLimitProvider）に答えるだけで、カートの行の知らせ・注文の入力へ進めないこと・注文の確定で断ることは核がする。
 * 核の表は変えない（上限は App の表 purchase_limits_listings、下限は App の設定）。
 * 店が App を止めると決まりは効かなくなる（行と設定は残るので、使うに戻せば元の決まりに戻る。ADR 0092）
 */
final class PurchaseLimitsServiceProvider extends ServiceProvider
{
    public const string NAME = 'purchase-limits';

    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: self::NAME,
            title: '購入の制限',
            description: '商品ごとに「1 回のご注文で何点まで」を、お店に「商品の合計が ◯ 円以上から」を決めます。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（目的 category と設定「最低購入金額」。登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
            tenantMigrations: __DIR__.'/../database/migrations',
            // ショップの経路も App の画面も持たない（効くのは核のカートと注文の確定の中）
            listingPanel: new PurchaseLimitsListingPanel,
            // 止めた店で、オーナーが選ぶと商品ごとの上限の行を消す（ADR 0092）
            purger: new PurchaseLimitsDataPurger,
            // 1 回のご注文の決まり（商品ごとの上限・商品の合計の下限）を核に答える
            purchaseLimits: new PurchaseLimitsProvider,
        ));
    }
}
