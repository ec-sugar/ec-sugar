<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ライセンスの一式（ADR 0033）。composer.json と package.json の license が決めた値か、
 * NOTICE が配る中身の部品を全部載せているか（依存を足して release/notice.sh を流し忘れると落ちる）を見張る。
 * DB もアプリも使わない
 */
final class LicenseTest extends TestCase
{
    /** gnu.org の agpl-3.0.txt の SHA-256。LICENSE は書き換えない */
    private const AGPL_SHA256 = '0d96a4ff68ad6d4b6f1f30f713b18d5184912ba8dd389f86aa7710db079abcb0';

    /** テーマを作る人・App を作る人が下敷きにして写せるように MIT（ADR 0033）。ディレクトリに MIT の LICENSE も置く */
    private const MIT = ['theme-default', 'theme-kinari', 'theme-fashion', 'theme-botanica', 'plugin-faq'];

    /**
     * ほかの App に許す値。どれにするかは運営が App ごとに決める途中で、決まるまでは核と同じ AGPL（2026-11-12）。
     * 決まったら App ごとの表にして、ここに足す（MIT にするものは上の MIT へ）
     */
    private const APP_ALLOWED = ['AGPL-3.0-only'];

    /** 著作権の表示の名前（運営の判断、2026-11-12。大文字の F、design は小文字） */
    private const HOLDER = 'Fivenine design';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_the_core_is_agpl_3_only_and_the_license_text_is_the_unmodified_original(): void
    {
        // 二重ライセンス（商用）を続けるので -or-later にしない（ADR 0033 の「作って決めたこと」）
        $this->assertSame('AGPL-3.0-only', $this->json('composer.json')['license']);
        $this->assertSame('AGPL-3.0-only', $this->json('package.json')['license']);
        $this->assertSame(self::AGPL_SHA256, hash_file('sha256', "{$this->root}/LICENSE"));

        $exception = (string) file_get_contents("{$this->root}/LICENSE-EXCEPTION.md");
        $this->assertStringContainsString('additional permission under Section 7', $exception);
    }

    public function test_themes_and_the_sample_app_are_mit_and_other_apps_are_an_allowed_license(): void
    {
        $dirs = glob("{$this->root}/plugins/*", GLOB_ONLYDIR) ?: [];
        $this->assertNotEmpty($dirs);
        $lock = $this->lockLicenses();

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $composer = $this->json("plugins/{$name}/composer.json");
            $license = $composer['license'] ?? null;

            if (in_array($name, self::MIT, true)) {
                $this->assertSame('MIT', $license, $name);
                $this->assertStringStartsWith('MIT License', (string) @file_get_contents("{$dir}/LICENSE"), "{$name} に MIT の LICENSE が無い");
            } else {
                $this->assertContains($license, self::APP_ALLOWED, "{$name} の license が決めた値でない");
            }

            // composer.lock も同じ値に（composer.json だけ直して lock を作り直し忘れると、composer licenses と配る中身が古いまま）
            $this->assertSame([$license], $lock[$composer['name']] ?? null, "{$name} の composer.lock の license が composer.json と違う（composer update 'ec-sugar/*' --no-install）");
        }

        foreach (self::MIT as $name) {
            $this->assertDirectoryExists("{$this->root}/plugins/{$name}");
        }
    }

    public function test_the_copyright_holder_is_written_the_same_everywhere(): void
    {
        $this->assertStringContainsString('Copyright (C) 2026 '.self::HOLDER."\n", (string) file_get_contents("{$this->root}/NOTICE"));
        $this->assertStringContainsString('Copyright (C) 2026 '.self::HOLDER."\n", (string) file_get_contents("{$this->root}/LICENSE-EXCEPTION.md"));

        foreach (self::MIT as $name) {
            $this->assertStringContainsString('Copyright (c) 2026 '.self::HOLDER."\n", (string) file_get_contents("{$this->root}/plugins/{$name}/LICENSE"), $name);
        }

        // 自社の App とテーマの作り手（composer.json と composer.lock の authors。書いてあるものは）も同じ書き方に
        $lock = [];
        foreach (array_merge($this->json('composer.lock')['packages'], $this->json('composer.lock')['packages-dev']) as $package) {
            $lock[$package['name']] = array_column($package['authors'] ?? [], 'name');
        }
        $seen = 0;
        foreach (glob("{$this->root}/plugins/*/composer.json") ?: [] as $file) {
            $composer = $this->json(substr($file, strlen($this->root) + 1));
            $authors = array_column($composer['authors'] ?? [], 'name');
            if ($authors === []) {
                continue;
            }
            $seen++;
            $this->assertSame([self::HOLDER], $authors, $file);
            $this->assertSame($authors, $lock[$composer['name']] ?? null, "{$composer['name']} の composer.lock の authors が composer.json と違う（composer update 'ec-sugar/*' --no-install）");
        }
        $this->assertGreaterThan(0, $seen);
    }

    public function test_notice_lists_every_production_php_package_with_its_license(): void
    {
        $listed = [];
        foreach ($this->noticeLines('PHP packages') as $line) {
            $this->assertMatchesRegularExpression('#^- (\S+) (\S+) \(([^)]+)\) \S*$#', $line);
            preg_match('#^- (\S+) \S+ \(([^;)]+)#', $line, $match);
            $listed[$match[1]] = $match[2];
        }

        $expected = [];
        foreach ($this->json('composer.lock')['packages'] as $package) {
            if (! str_starts_with($package['name'], 'ec-sugar/')) {
                $expected[$package['name']] = implode(' OR ', $package['license'] ?? []);
            }
        }
        ksort($expected);
        ksort($listed);

        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $listed, 'NOTICE が composer.lock の本番の依存と違う。release/notice.sh で作り直す');
    }

    public function test_notice_lists_the_bundled_javascript_packages_and_fonts(): void
    {
        $js = $this->names($this->noticeLines('JavaScript packages'));
        foreach (array_keys($this->json('package.json')['dependencies']) as $dependency) {
            $this->assertContains($dependency, $js, "NOTICE に {$dependency} が無い。release/notice.sh で作り直す");
        }

        $fonts = $this->names($this->noticeLines('Fonts'));
        $imported = [];
        $css = array_merge(glob("{$this->root}/plugins/*/resources/css/*.css") ?: [], glob("{$this->root}/resources/*/*.css") ?: []);
        foreach ($css as $file) {
            preg_match_all('#@import\s+["\'](@fontsource/[a-z0-9-]+)/#', (string) file_get_contents($file), $match);
            array_push($imported, ...$match[1]);
        }
        $imported = array_values(array_unique($imported));
        sort($imported);

        $this->assertNotEmpty($imported);
        $this->assertSame($imported, $fonts, 'NOTICE の書体がテーマの CSS が読む書体と違う。release/notice.sh で作り直す');

        // 縮めたビルドでは著作権の表示が消えるので、全文を NOTICE に添える
        $notice = (string) file_get_contents("{$this->root}/NOTICE");
        $this->assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', $notice);
        foreach (array_merge($js, $fonts) as $name) {
            $this->assertMatchesRegularExpression('#^\#\#\# .*'.preg_quote($name, '#').' #m', $notice, "{$name} のライセンスの全文が無い");
        }
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $json = json_decode((string) file_get_contents("{$this->root}/{$path}"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        return $json;
    }

    /** @return array<string, list<string>> */
    private function lockLicenses(): array
    {
        $lock = $this->json('composer.lock');
        $licenses = [];
        foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
            $licenses[$package['name']] = $package['license'] ?? [];
        }

        return $licenses;
    }

    /**
     * NOTICE の「## <見出し>」の節の「- 」で始まる行
     *
     * @return list<string>
     */
    private function noticeLines(string $heading): array
    {
        $notice = (string) file_get_contents("{$this->root}/NOTICE");
        $this->assertSame(1, preg_match('#^\#\# '.preg_quote($heading, '#').'.*?$(.*?)(?=^\#\# )#ms', $notice, $section), "NOTICE に「{$heading}」の節が無い");

        return array_values(array_filter(explode("\n", $section[1]), static fn (string $line): bool => str_starts_with($line, '- ')));
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function names(array $lines): array
    {
        $names = array_values(array_unique(array_map(static fn (string $line): string => explode(' ', $line)[1], $lines)));
        sort($names);

        return $names;
    }
}
