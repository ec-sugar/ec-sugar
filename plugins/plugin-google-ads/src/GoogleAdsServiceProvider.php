<?php

declare(strict_types=1);

namespace EcSugar\PluginGoogleAds;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「Google 広告」（ADR 0034、docs/design/tag-apps.md の 2）。
 * 核の差し込み口を通して、同意（広告）のあるお客さんの、ふつうの画面と購入の完了の画面にだけ gtag.js を読み、
 * 購入の完了でコンバージョンを送る。GA4 の App と同じページで使われても gtag.js の読み込みは 1 回（スクリプトの中で分け合う）。
 * 設定（コンバージョン ID とラベル）・CSP の宛先・同意の種類・外部送信の公表は composer.json の extra.ec-sugar に書く
 */
final class GoogleAdsServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'google-ads',
            title: 'Google 広告',
            description: 'Google 広告から来たお客さんの購入を、コンバージョンとして計測します。同意の帯を出す店では、お客さんが同意したときだけ計測します。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
        ));
    }
}
