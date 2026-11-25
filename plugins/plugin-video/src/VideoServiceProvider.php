<?php

declare(strict_types=1);

namespace EcSugar\PluginVideo;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「動画」（ADR 0040）。ページに YouTube・Vimeo の動画の区画を置ける。
 * ページを開いた時から枠を出す（同意は待たない。守りは核の CSP・sandbox・宛先の確かめ。送る内容は外部送信に関する公表）。
 * 区画の宣言・枠の宛先・同意の種類・外部送信の公表は composer.json の extra.ec-sugar に書く。URL から動画の ID を取り出すのは VideoSections
 */
final class VideoServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'video',
            title: '動画',
            description: 'ページに YouTube・Vimeo の動画を置けます。自動で再生する・音を消す・操作の表示を出さない・繰り返すも選べます。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
            sectionHandler: new VideoSections,
        ));
    }
}
