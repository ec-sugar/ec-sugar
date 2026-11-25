<?php

declare(strict_types=1);

namespace EcSugar\PluginLineyahooAds;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「LINEヤフー広告」（ADR 0034、docs/design/tag-apps.md の 5）。LINEヤフー広告の計測タグ（新しい形）を、
 * 核の差し込み口からショップのふつうの画面と購入の完了の画面にだけ差し込む。お客さんが「広告」に同意したときだけ核が読む。
 * 照合用の情報（メール・電話）は送らず、パートナー企業への送信（isExternalTransmission）も切る
 */
final class LineyahooAdsServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'lineyahoo-ads',
            title: 'LINEヤフー広告',
            description: 'Yahoo! JAPAN や LINE に出した広告から来たお客さんのページの表示と購入を計測し、広告の効果を測ります。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
        ));
    }
}
