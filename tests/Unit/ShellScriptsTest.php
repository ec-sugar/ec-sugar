<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * シェルスクリプト（docker・deploy・git hooks・ops/dev の診断とテストの環境）は、手元の Mac の bash 3.2 でも同じに動く書き方にする。
 * bash 3.2 は、変数名のすぐ後ろに続く日本語の文字を名前の続きとして読み、`set -u` では「unbound variable」で止まる
 * （`（$dev_pass）` が `dev_pass（` という名前になる）。日本語が続くときは `${dev_pass}` の形にする。
 * コメントの行とシングルクォートの中（展開されない）は見ない
 */
final class ShellScriptsTest extends TestCase
{
    public function test_variables_followed_by_japanese_are_braced(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob("{$root}/docker/*.sh") ?: [],
            glob("{$root}/docker/db/init/*.sh") ?: [],
            glob("{$root}/deploy/*.sh") ?: [],
            glob("{$root}/deploy/lib/*.sh") ?: [],
            // deploy/recovery-kit は持ち主の手元（macOS の bash 3.2 も）で流す、復元のキットの道具
            ["{$root}/deploy/ec-sugar", "{$root}/deploy/recovery-kit"],
            glob("{$root}/.githooks/*") ?: [],
            glob("{$root}/ops/dev/*.sh") ?: [],
            // 公開用への書き出しの道具。開発用にだけあり、公開用の木には無い（無ければ glob が空）
            glob("{$root}/release/*.sh") ?: [],
        );
        $this->assertContains("{$root}/docker/check-compose.sh", $files);
        $this->assertFileExists("{$root}/deploy/recovery-kit");

        foreach ($files as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (preg_match('/^\s*#/', $line)) {
                    continue;
                }

                $code = (string) preg_replace("/'[^']*'/", "''", $line);
                $this->assertSame(0, preg_match('/(?<![\\\\$])\$[A-Za-z_][A-Za-z0-9_]*(?=[^\x00-\x7F])/', $code, $match),
                    basename($file).':'.($number + 1).': 日本語の前の変数は ${名前} の形に: '.($match[0] ?? ''));
            }
        }
    }

    /**
     * 管の先で「あるか」を見るのに grep -q を使わない（ec_q・q を使う）。grep -q は見つけた時点で読むのをやめるので、前のコマンドが
     * 書き込みの途中だと管が切れ、pipefail の下では「無い」と同じ失敗になる。タイミングしだいで、たまにだけ起きる
     * （2026-11-20 に CI の検査が偽の NG で落ちた。運用の道具では、コマンドの有無や動いているかを読み違える）
     */
    public function test_pipes_do_not_end_in_grep_quiet(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob("{$root}/docker/check-*.sh") ?: [],
            glob("{$root}/deploy/*.sh") ?: [],
            glob("{$root}/deploy/lib/*.sh") ?: [],
            ["{$root}/deploy/ec-sugar", "{$root}/deploy/recovery-kit"],
        );
        $this->assertContains("{$root}/deploy/lib/common.sh", $files);
        // check-image.sh の確かめは、コンテナの中の /bin/sh（pipefail なし・部品も無い）で動くので見ない
        $files = array_values(array_filter($files, static fn (string $file): bool => basename($file) !== 'check-image.sh'));

        foreach ($files as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (preg_match('/^\s*#/', $line)) {
                    continue;
                }

                $this->assertSame(0, preg_match('/(?<!\|)\|\s*grep\s+(-[A-Za-z]*q|--quiet|--silent)/', $line),
                    basename($file).':'.($number + 1).': 管の先の grep -q は、全部を受け取ってから探す ec_q（検査の台本では q）に');
            }
        }
    }
}
