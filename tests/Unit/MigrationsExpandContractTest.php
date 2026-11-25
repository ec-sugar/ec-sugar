<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 移行は「足す → 次の版で消す」（ADR 0006）。更新に失敗して前のバージョンへ戻しても、前のプログラムが同じ DB で動くように、
 * 移行の up() の中で列や表を消す・名前を変える書き方をしない（ADR 0006 の「作って決めたこと」2026-10-31）。
 * 公開の前は「// 公開の前の例外（ADR 0006）: 理由」の印がある移行だけ通す。公開した日（tests/Support/launched-on.txt）より後の移行は、印があっても通さない
 */
final class MigrationsExpandContractTest extends TestCase
{
    private const string MARK = '公開の前の例外（ADR 0006）:';

    /** 列や表を消す・名前を変える書き方（down() は見ない） */
    private const string DESTRUCTIVE = '/dropColumn|renameColumn|dropConstrainedForeignId|dropMorphs|dropTimestamps|dropSoftDeletes|dropRememberToken|Schema::drop\(|Schema::dropIfExists|Schema::rename|->rename\(|DROP\s+COLUMN|RENAME\s+COLUMN|RENAME\s+TO|DROP\s+TABLE/i';

    public function test_migrations_do_not_drop_or_rename_without_the_pre_launch_mark(): void
    {
        $files = array_merge(
            glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [],
            glob(dirname(__DIR__, 2).'/database/migrations/tenant/*.php') ?: [],
            // プラグインの表の移行（ADR 0050 の 2）も同じ決まり
            glob(dirname(__DIR__, 2).'/plugins/*/database/migrations/*.php') ?: [],
        );
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $up = self::upBody((string) file_get_contents($file));

            if ($up === null || preg_match(self::DESTRUCTIVE, $up) !== 1) {
                continue;
            }

            $name = basename($file);
            $this->assertStringContainsString(self::MARK, $up, "{$name}: up() で列や表を消す・名前を変えている。足す → 次の版で消す、にする（公開の前の例外なら印と理由を書く）");

            $launched = self::launchedOn();

            if ($launched !== null) {
                $this->assertLessThan(0, strcmp(substr($name, 0, 10), $launched), "{$name}: 公開の後の移行は、消す・名前を変えるを例外にできない");
            }
        }
    }

    public function test_the_check_finds_a_drop_in_up_but_not_in_down(): void
    {
        $dropInUp = "<?php return new class { public function up(): void { Schema::table('t', fn (\$t) => \$t->dropColumn('a')); } public function down(): void {} };";
        $dropInDown = "<?php return new class { public function up(): void { Schema::table('t', fn (\$t) => \$t->string('a')); } public function down(): void { Schema::table('t', fn (\$t) => \$t->dropColumn('a')); } };";

        $this->assertSame(1, preg_match(self::DESTRUCTIVE, (string) self::upBody($dropInUp)));
        $this->assertSame(0, preg_match(self::DESTRUCTIVE, (string) self::upBody($dropInDown)));
        $this->assertSame(1, preg_match(self::DESTRUCTIVE, 'ALTER TABLE t DROP COLUMN a'));
    }

    /**
     * 公開した日（YYYY_MM_DD）。tests/Support/launched-on.txt に書く（空ならまだ公開していない）。
     * これより後の日付の移行は、消す・名前を変えるを例外にできない
     */
    private static function launchedOn(): ?string
    {
        $value = trim((string) @file_get_contents(dirname(__DIR__).'/Support/launched-on.txt'));

        return preg_match('/\A\d{4}_\d{2}_\d{2}\z/', $value) === 1 ? $value : null;
    }

    /**
     * up() の本体（down() の手前まで。無ければ null）
     */
    private static function upBody(string $source): ?string
    {
        if (preg_match('/function\s+up\s*\([^)]*\)[^{]*\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $down = strpos($source, 'function down', $start);

        return substr($source, $start, $down === false ? null : $down - $start);
    }
}
