<?php

declare(strict_types=1);

namespace EcSugar\ThemeFashion;

use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * 公式テーマ 2（fashion）。画面（Blade）を読み込ませ、テーマの登録簿に自分を登録する。
 * テーマは DB に触らず、店頭が渡すデータ（docs/design/theme-api.md）だけを表示する。トップなどの区画は theme.json で宣言する。
 */
final class ThemeServiceProvider extends ServiceProvider
{
    public function boot(ThemeRegistry $themes): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'theme-fashion');

        $themes->register(new ThemeDefinition(
            name: 'fashion',
            label: '公式テーマ 2（fashion）',
            themeApi: 2,
            views: 'theme-fashion',
            stylesheet: 'plugins/theme-fashion/resources/css/theme.css',
            // 区画の宣言（テーマ API 2。ADR 0028）。管理画面の「テーマの中身」の欄はここから作る
            manifest: __DIR__.'/../theme.json',
        ));
    }
}
