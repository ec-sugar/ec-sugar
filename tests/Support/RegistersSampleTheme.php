<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Support\Facades\View;

/**
 * テスト用: 区画を宣言したテーマ（テーマ API 2）を登録する。今の公式テーマを 2 に移すまで（ADR 0028 区切り 2c）、区画の仕組みはこれで確かめる
 */
trait RegistersSampleTheme
{
    private function registerSampleTheme(): void
    {
        $dir = base_path('tests/Support/Themes/sample');
        View::addNamespace('theme-sample', "{$dir}/views");
        app(ThemeRegistry::class)->register(new ThemeDefinition('sample', 'テスト用のテーマ', 2, 'theme-sample', 'x.css', manifest: "{$dir}/theme.json"));
    }

    /**
     * 今の書き方のテーマ（pages を書かない決まった並び。自由なページの雛形も page の画面も持たない）。
     * 公式テーマは並びの形に移ったので（ADR 0039 区切り 4）、今の書き方の扱いはこれで確かめる
     */
    private function registerLegacyTheme(): void
    {
        $dir = base_path('tests/Support/Themes/legacy');
        View::addNamespace('theme-legacy', "{$dir}/views");
        app(ThemeRegistry::class)->register(new ThemeDefinition('legacy', '今の書き方のテスト用のテーマ', 2, 'theme-legacy', 'x.css', manifest: "{$dir}/theme.json"));
    }
}
