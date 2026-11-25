<?php

declare(strict_types=1);

namespace EcSugar\PluginTagSample;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 見本の App「試験用のタグ」（ADR 0034）。核の口（ショップへの差し込み・CSP への足し込み・設定・同意・公表）を
 * 通して確かめるためだけのもので、宛先は実在しない（.test）。本物のタグ（GA4 など）は 1 つのタグに 1 つの App で作る。
 * 開発とテストだけで入れる（composer の require-dev。本番のイメージには入らない）
 */
final class TagSampleServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'tag-sample',
            title: '試験用のタグ',
            description: 'タグをショップに入れる仕組みを試すための見本です（実在の送信先はありません）。',
            pluginApi: 1,
            // 宣言は composer.json の extra.ec-sugar（登録のときに核が確かめる）
            manifest: __DIR__.'/../composer.json',
        ));
    }
}
