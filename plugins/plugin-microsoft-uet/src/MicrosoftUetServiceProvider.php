<?php

declare(strict_types=1);

namespace EcSugar\PluginMicrosoftUet;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「Microsoft 広告 UET」（ADR 0034、docs/design/tag-apps.md の 9）。Microsoft 広告の計測のタグ（UET）を、
 * 核の差し込み口からショップのふつうの画面と購入の完了の画面にだけ差し込む。お客さんが「広告」に同意したときだけ核が読む。
 * 照合用の情報（メール・電話の拡張コンバージョン）は送らない
 */
final class MicrosoftUetServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'microsoft-uet',
            title: 'Microsoft 広告 UET',
            description: 'Microsoft 広告（Bing などの検索広告）から来たお客さんのページの表示と購入を計測し、広告の効果を測ります。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
        ));
    }
}
