<?php

declare(strict_types=1);

namespace EcSugar\ThemeDefault;

use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 既定のテーマ「角砂糖」（識別子は default）。画面（Blade）を読み込ませ、テーマの登録簿に自分を登録する。
 * テーマは DB に触らず、店頭が渡すデータ（docs/design/theme-api.md）だけを表示する。
 */
final class ThemeServiceProvider extends ServiceProvider
{
    public function boot(ThemeRegistry $themes): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'theme-default');

        $themes->register(new ThemeDefinition(
            name: 'default',
            label: '角砂糖',
            themeApi: 2,
            views: 'theme-default',
            stylesheet: 'plugins/theme-default/resources/css/theme.css',
            // 区画の宣言（テーマ API 2。ADR 0028）。管理画面の「テーマの中身」の欄はここから作る
            manifest: __DIR__.'/../theme.json',
        ));
    }
}
