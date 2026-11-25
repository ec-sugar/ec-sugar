<?php

declare(strict_types=1);

namespace EcSugar\PluginClarity;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * App「Microsoft Clarity」（ADR 0034）。ショップのふつうの画面に Clarity（ヒートマップと操作の録画）を入れる。
 * 差し込み・CSP・設定・同意・公表は核の口を通す。宣言は composer.json の extra.ec-sugar（登録のときに核が確かめる）
 */
final class ClarityServiceProvider extends ServiceProvider
{
    public function boot(PluginRegistry $plugins): void
    {
        $plugins->register(new PluginDefinition(
            name: 'clarity',
            title: 'Microsoft Clarity',
            description: 'ショップでのお客さんの操作をヒートマップと録画で見られます。文字は伏せ字で記録し、入力欄の中身は記録しません。18 歳未満向けのショップでは使えません。',
            pluginApi: 1,
            manifest: __DIR__.'/../composer.json',
        ));
    }
}
