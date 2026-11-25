<?php

declare(strict_types=1);

namespace EcSugar\PluginGa4;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「Google アナリティクス 4」（ADR 0034、docs/design/tag-apps.md の 1）。
 * 核の差し込み口を通して、同意（解析）のあるお客さんの、ふつうの画面と購入の完了の画面にだけ gtag.js を読む。
 * 設定（測定 ID）・CSP の宛先・同意の種類・外部送信の公表は composer.json の extra.ec-sugar に書く
 */
final class Ga4ServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'ga4',
            title: 'Google アナリティクス 4',
            description: 'ショップのアクセスと購入を Google アナリティクス（GA4）で計測します。同意の帯を出す店では、お客さんが同意したときだけ計測します。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
        ));
    }
}
