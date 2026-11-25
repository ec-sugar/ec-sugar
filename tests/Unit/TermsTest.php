<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 画面・ショップ・メール・コマンドの表示に、使わないと決めた言い方を入れない（docs/design/terms.md の対応表。2026-11-20 の全体の見直し）。
 *
 * 作った和語（合い言葉・置き場・見張り・困りごと など）は、決まりの文書に書いてあっても、新しく書く文で何度も戻ってきた。
 * 言葉だけでは守られなかったので、機械で止める。見るのは利用者の目に触れる所だけで、コードのコメントは見ない。
 * 落ちたら、terms.md の対応表の「使う」の言葉に書き換える（このテストの一覧を緩めない）
 */
final class TermsTest extends TestCase
{
    /**
     * 画面・ショップ・メールで使わない言い方（どの文脈でも作った言い方になるものだけ。「控える」「知らせる」のように普通の使い方があるものは入れない）
     */
    private const array SCREEN = [
        '合い言葉', '合言葉', '置き場', '困りごと', '運営の様子', '見張り', '裏の仕事', '区画', '開ける鍵', '受渡',
        '出品者', '手の上げ下げ', '足し引き', '機械の調子', 'あるべき姿', '流し直', '答え待ち', '黙らされ', '中の照らし', '確かめの画面',
    ];

    /**
     * コマンドとインストーラーの表示で使わない言い方（上に加えて）
     */
    private const array COMMAND = ['読む鍵', '書く鍵', '鍵束', '防火壁', '時刻の帯', '接続役', '切れ端', '前置き', '公開の住所', '私設の住所'];

    public function test_the_admin_and_the_shop_do_not_use_the_words_we_decided_against(): void
    {
        $found = [];

        foreach ($this->files(['resources/admin', 'resources/views', 'resources/storefront', 'plugins'], ['vue', 'js', 'php', 'json']) as $path) {
            // App とテーマは、画面に出る resources/ と宣言（theme.json・composer.json）だけ
            if (str_contains($path, '/plugins/') && ! preg_match('#/plugins/[^/]+/(resources/|theme\.json$|composer\.json$)#', $path)) {
                continue;
            }

            $found = [...$found, ...$this->scan($path, $this->stripComments((string) file_get_contents($path), $path), self::SCREEN)];
        }

        $this->assertSame([], $found, "使わないと決めた言い方があります（docs/design/terms.md の対応表で書き換える）:\n".implode("\n", $found));
    }

    public function test_the_operations_commands_do_not_use_the_words_we_decided_against(): void
    {
        $found = [];

        foreach ($this->files(['deploy'], null) as $path) {
            // 証明書・設定の見本は見ない（表示されない）
            if (preg_match('/\.(crt|pem|example|conf|service|timer|json)$/', $path) === 1) {
                continue;
            }

            // シェルと python の行頭の # のコメントは見ない（ただし ec-sugar のファイル頭の使い方の説明は usage() で表示されるので見る）
            $lines = explode("\n", (string) file_get_contents($path));
            $kept = [];
            // usage() が出すのは、2 行目から「# systemd から呼ぶもの」の前まで
            $usageEnd = str_ends_with($path, '/deploy/ec-sugar') ? (int) array_search(true, array_map(static fn (string $l): bool => str_starts_with($l, '# systemd から呼ぶもの'), $lines), true) : 0;

            foreach ($lines as $index => $line) {
                $usage = $index >= 1 && $index < $usageEnd;
                $kept[] = ! $usage && preg_match('/^\s*#/', $line) === 1 ? '' : $line;
            }

            $found = [...$found, ...$this->scan($path, implode("\n", $kept), [...self::SCREEN, ...self::COMMAND])];
        }

        $this->assertSame([], $found, "使わないと決めた言い方があります（docs/design/terms.md の対応表で書き換える）:\n".implode("\n", $found));
    }

    public function test_the_guard_finds_a_word_it_should_find(): void
    {
        // わざと壊す確かめ: コメントの外の言い方は見つけ、コメントの中は見ない
        $vue = "<template>\n  <!-- 置き場 -->\n  <p>置き場を選ぶ</p>\n</template>\n<script>\n// 合い言葉\nconst a = '合い言葉';\n</script>";
        $found = $this->scan('x.vue', $this->stripComments($vue, 'x.vue'), self::SCREEN);
        $this->assertSame(['x.vue:3: 置き場', 'x.vue:7: 合い言葉'], $found);
        $blade = "{{-- 区画 --}}\n<p>{{ \$x }}区画</p>";
        $this->assertSame(['x.blade.php:2: 区画'], $this->scan('x.blade.php', $this->stripComments($blade, 'x.blade.php'), self::SCREEN));
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function scan(string $path, string $text, array $words): array
    {
        $found = [];
        $short = str_replace(dirname(__DIR__, 2).'/', '', $path);

        foreach (explode("\n", $text) as $index => $line) {
            foreach ($words as $word) {
                if (str_contains($line, $word)) {
                    $found[] = $short.':'.($index + 1).': '.$word;
                }
            }
        }

        return $found;
    }

    /**
     * コメントを、行の数を変えずに消す（見つけた行の番号が元のファイルと合うように）
     */
    private function stripComments(string $text, string $path): string
    {
        $blank = static fn (array $m): string => preg_replace('/[^\n]/u', '', $m[0]) ?? '';
        $patterns = ['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s', '/\/\*.*?\*\//s'];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, $blank, $text) ?? $text;
        }

        // 行のコメント（// と PHP の #）。URL の // を消さないよう、行頭か空白の後だけ
        $text = preg_replace('#(^|\s)//[^\n]*#m', '$1', $text) ?? $text;

        if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
            $text = preg_replace('/^\s*#[^\n\[]*$/m', '', $text) ?? $text;
        }

        return $text;
    }

    /**
     * @param  list<string>  $dirs
     * @param  list<string>|null  $extensions  null なら全部のふつうのファイル
     * @return list<string>
     */
    private function files(array $dirs, ?array $extensions): array
    {
        $root = dirname(__DIR__, 2);
        $paths = [];

        foreach ($dirs as $dir) {
            if (! is_dir("{$root}/{$dir}")) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $path = $file->getPathname();

                if (! $file->isFile() || str_contains($path, '/node_modules/') || str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                    continue;
                }

                if ($extensions !== null && ! in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true)) {
                    continue;
                }

                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }
}
