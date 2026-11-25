<?php

declare(strict_types=1);

namespace EcSugar\PluginGoogleMap;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「地図（Google マップ）」（ADR 0040）。ページに地図の区画を置ける。住所か場所の名前を入れると、Google マップの地図を埋め込む
 * （キーの要らない埋め込み。公式の資料に無い形で止まりうる。止まったら Maps Embed API へ戻す。ADR 0040 の 6）。
 * ページを開いた時から枠を出す（同意は待たない。守りは核の CSP・sandbox・宛先の確かめ。送る内容は外部送信に関する公表）。
 * 区画の宣言・枠の宛先・同意の種類・外部送信の公表は composer.json の extra.ec-sugar に書く（設定の項目は無い）。地図の URL を作るのは GoogleMapSections
 */
final class GoogleMapServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'google-map',
            title: '地図（Google マップ）',
            description: 'ページに Google マップの地図を置けます。住所か場所の名前を入れるだけで出ます（設定は不要です）。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
            sectionHandler: new GoogleMapSections,
        ));
    }
}
