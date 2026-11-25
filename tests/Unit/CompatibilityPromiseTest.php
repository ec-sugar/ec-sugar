<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Plugin\PluginRegistry;
use App\Core\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * 互換の約束（ADR 0093）に書いたテーマ API とプラグイン API の番号が、登録簿の今の番号と同じか。
 * 番号を上げたのに約束の文書（ADR 0093・テーマ API の文書・CHANGELOG）を直し忘れると落ちる
 */
final class CompatibilityPromiseTest extends TestCase
{
    public function test_the_promise_states_the_current_theme_and_plugin_api_versions(): void
    {
        $adr = $this->read('docs/adr/0093-外に約束する口を決め、壊すときはAPIのバージョンを上げてCHANGELOGで知らせる.md');

        // 要点の 1 行「テーマ API（今 **2**）・プラグイン API（今 **1**）」
        $this->assertSame(ThemeRegistry::API_VERSION, $this->number('/テーマ API（今 \*\*(\d+)\*\*）/u', $adr, 'ADR 0093 の要点'), 'ADR 0093 の要点のテーマ API の番号を ThemeRegistry::API_VERSION に合わせる');
        $this->assertSame(PluginRegistry::API_VERSION, $this->number('/プラグイン API（今 \*\*(\d+)\*\*）/u', $adr, 'ADR 0093 の要点'), 'ADR 0093 の要点のプラグイン API の番号を PluginRegistry::API_VERSION に合わせる');

        // 約束の表「テーマ API**（今のバージョン **2**、`ThemeRegistry::API_VERSION`）」
        $this->assertSame(ThemeRegistry::API_VERSION, $this->number('/テーマ API\*\*（今のバージョン \*\*(\d+)\*\*、`ThemeRegistry::API_VERSION`）/u', $adr, 'ADR 0093 の約束の表'), 'ADR 0093 の約束の表のテーマ API の番号を合わせる');
        $this->assertSame(PluginRegistry::API_VERSION, $this->number('/プラグイン API\*\*（今のバージョン \*\*(\d+)\*\*、`PluginRegistry::API_VERSION`）/u', $adr, 'ADR 0093 の約束の表'), 'ADR 0093 の約束の表のプラグイン API の番号を合わせる');
    }

    public function test_the_theme_api_document_and_changelog_state_the_current_versions(): void
    {
        // テーマ API の文書の見出し「# 店頭のテーマ API（第 2 バージョン）」
        $themeApi = $this->read('docs/design/theme-api.md');
        $this->assertSame(ThemeRegistry::API_VERSION, $this->number('/\A# 店頭のテーマ API（第 (\d+) バージョン）/u', $themeApi, 'theme-api.md の見出し'), 'docs/design/theme-api.md の見出しを ThemeRegistry::API_VERSION に合わせる');

        // CHANGELOG の「未リリース」の「今のテーマ API は **2**、プラグイン API は **1**」
        $changelog = $this->read('CHANGELOG.md');
        $this->assertSame(ThemeRegistry::API_VERSION, $this->number('/今のテーマ API は \*\*(\d+)\*\*/u', $changelog, 'CHANGELOG'), 'CHANGELOG.md の今のテーマ API の番号を合わせる');
        $this->assertSame(PluginRegistry::API_VERSION, $this->number('/プラグイン API は \*\*(\d+)\*\*/u', $changelog, 'CHANGELOG'), 'CHANGELOG.md の今のプラグイン API の番号を合わせる');
    }

    private function read(string $path): string
    {
        $file = dirname(__DIR__, 2).'/'.$path;
        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /**
     * 文書の中の決まった言い回しから番号を読む。言い回しが無ければ落とす（番号の書き方を変えたら、ここも直す）
     */
    private function number(string $pattern, string $text, string $where): int
    {
        $this->assertSame(1, preg_match($pattern, $text, $m), "{$where}に番号の言い回しが見つからない（{$pattern}）");

        return (int) $m[1];
    }
}
