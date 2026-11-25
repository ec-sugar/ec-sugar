<?php

declare(strict_types=1);

namespace EcSugar\PluginSmartnewsAds;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「SmartNews 広告」（ADR 0034、docs/design/tag-apps.md の 11）。SmartNews Ads のピクセルを、
 * 核の差し込み口からショップのふつうの画面と購入の完了の画面にだけ差し込む。お客さんが「広告」に同意したときだけ核が読む。
 * 購入で送るのは金額・通貨・品の ID と数だけ（注文の番号を受ける項目はピクセルに無い）
 */
final class SmartnewsAdsServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'smartnews-ads',
            title: 'SmartNews 広告',
            description: 'SmartNews の広告から来たお客さんのページの表示と購入を計測し、広告の効果を測ります。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
        ));
    }
}
