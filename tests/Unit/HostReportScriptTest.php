<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ホストの見張りの報告（ADR 0043）のスクリプトの側: 構文、state のファイルから作る引数（形の違う値や自由な文は渡さない）、
 * バックアップの結果の記録、更新・バックアップ・ロールバックの終わりに報告すること。
 * 手元の Mac の bash 3.2 でも同じに動く部品だけを流す（証明書と Docker に聞く所はサーバーでしか動かない）
 */
final class HostReportScriptTest extends TestCase
{
    private const string COMMIT = '0123456789abcdef0123456789abcdef01234567';

    private string $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = sys_get_temp_dir().'/t0096-script-'.bin2hex(random_bytes(4));
        mkdir($this->state);
    }

    protected function tearDown(): void
    {
        $this->remove($this->state);
        parent::tearDown();
    }

    public function test_the_scripts_parse(): void
    {
        foreach (['deploy/ec-sugar', 'deploy/lib/common.sh', 'deploy/install.sh'] as $file) {
            $process = new Process(['bash', '-n', self::root().'/'.$file]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $file.': '.$process->getErrorOutput());
        }
    }

    public function test_state_files_become_fixed_arguments(): void
    {
        $this->write('last_update', "time=2026-10-31T03:31:02+09:00\nresult=failed\nfrom=".str_repeat('a', 40)."\nto=".self::COMMIT."\nmessage=新しいバージョンがヘルスチェックを通らず\n");
        $this->write('last_check', "2026-10-31T03:30:10+09:00\n");
        $this->write('last_backup', "time=2026-10-31T04:15:01+09:00\nresult=failed\n");
        $this->write('auto_update', "no\n");

        $this->assertSame([
            '--update-result=failed',
            '--update-at=2026-10-31T03:31:02+09:00',
            '--update-version='.self::COMMIT,
            '--update-checked-at=2026-10-31T03:30:10+09:00',
            '--update-auto=no',
            '--backup-result=failed',
            '--backup-at=2026-10-31T04:15:01+09:00',
        ], $this->stateArgs());
    }

    public function test_the_auto_update_flag_is_only_passed_when_it_is_yes_or_no(): void
    {
        $this->write('auto_update', "yes\n");
        $this->assertSame(['--update-auto=yes'], $this->stateArgs());

        // 自由な文・空は渡さない（記録が壊れていても決まった語だけを渡す）
        $this->write('auto_update', "いれた\n");
        $this->assertSame([], $this->stateArgs());
        $this->write('auto_update', "\n");
        $this->assertSame([], $this->stateArgs());
    }

    public function test_odd_values_in_the_state_files_are_not_passed(): void
    {
        // 決まっていない語・自由な文・形の違う時刻・短いハッシュは渡さない（一言 message はもともと渡さない）
        $this->write('last_update', "time=2026-10-31 03:31\nresult=ok\nto=".self::COMMIT."\nmessage=x\n");
        $this->write('last_check', "まだ\n");
        $this->write('last_backup', "time=2026-10-31T04:15:01+09:00\nresult=取った\n");
        $this->assertSame([], $this->stateArgs());

        $this->write('last_update', "time=2026-10-31T03:31:02+09:00\nresult=ok; rm -rf /\nto=".self::COMMIT."\n");
        $this->assertSame([], $this->stateArgs());

        $this->write('last_update', "time=2026-10-31T03:31:02+09:00\nresult=stopped\nto=abc123\n");
        $this->assertSame(['--update-result=stopped', '--update-at=2026-10-31T03:31:02+09:00'], $this->stateArgs());
    }

    public function test_a_backup_from_before_this_record_counts_as_the_last_success(): void
    {
        // last_backup が無ければ（この仕組みの前に取ったもの）、いちばん新しい済んだバックアップの時刻を成功として
        $backup = "{$this->state}/backup";
        mkdir("{$backup}/20261029-041500", 0777, true);
        mkdir("{$backup}/20261030-041500");
        file_put_contents("{$backup}/20261029-041500/manifest", "time=2026-10-29T04:15:00+09:00\n");
        touch("{$backup}/20261029-041500/.complete");
        // 途中で切れたもの（印が無い）は数えない
        file_put_contents("{$backup}/20261030-041500/manifest", "time=2026-10-30T04:15:00+09:00\n");

        $this->assertSame(['--backup-result=ok', '--backup-at=2026-10-29T04:15:00+09:00'], $this->stateArgs());
    }

    public function test_the_backup_result_is_recorded(): void
    {
        // 時刻はサーバーの GNU の date -Is の形（手元の Mac の date は -Is を持たないので、決まった時刻を返す関数に替える）
        $date = 'date() { printf "2026-10-31T04:15:01+09:00\n"; }; ';
        $this->shell($date.'ec_record_backup failed');
        $this->assertSame("time=2026-10-31T04:15:01+09:00\nresult=failed\n", (string) file_get_contents("{$this->state}/last_backup"));
        $this->assertSame(['--backup-result=failed', '--backup-at=2026-10-31T04:15:01+09:00'], $this->stateArgs());
        $this->shell($date.'ec_record_backup ok');
        $this->assertSame("time=2026-10-31T04:15:01+09:00\nresult=ok\n", (string) file_get_contents("{$this->state}/last_backup"));
    }

    public function test_the_disk_argument_has_a_fixed_shape(): void
    {
        $output = $this->shell('ec_disk_args "$EC_STATE" "$EC_STATE"; ec_disk_arg data /no/such/place');

        // 同じファイルシステムなら data だけ。読めない道筋は何も出さない
        $this->assertMatchesRegularExpression('/\Adata,(\d|[1-9]\d|100),\d+\n\z/', $output);
    }

    public function test_the_time_shape(): void
    {
        $output = $this->shell('for t in 2026-10-31T03:31:02+09:00 2026-10-31T03:31:02Z "2026-10-31 03:31:02" 2026-10-31T03:31:02 "2026-10-31T03:31:02+09:00 x"; do if ec_valid_time "$t"; then echo y; else echo n; fi; done');
        $this->assertSame("y\ny\nn\nn\nn\n", $output);
    }

    public function test_update_backup_and_rollback_report_when_they_end(): void
    {
        $script = (string) file_get_contents(self::root().'/deploy/ec-sugar');

        // 鍵を取った後に、終わりの報告の罠（trap）を掛ける（止まって終わった時も渡す）。更新は、途中で止まった時に前のバージョンへそろえる罠と一緒に
        foreach (['cmd_update' => 'ec_update_at_exit', 'cmd_rollback' => 'ec_report_at_exit', 'cmd_backup' => 'ec_report_at_exit'] as $command => $trap) {
            $this->assertSame(1, preg_match('/^'.$command.'\(\) \{\n(.*?)^\}/ms', $script, $match), $command);
            $this->assertMatchesRegularExpression('/ec_lock[^\n]*\n(\s*#[^\n]*\n)*\s*'.$trap.'\n/', $match[1], $command);
        }
        $this->assertSame(1, preg_match('/^ec_update_at_exit\(\) \{\n(.*?)^\}/ms', $script, $match));
        $this->assertMatchesRegularExpression('/trap \'[^\n]*update_interrupted \|\| true;[^\n]*ec_host_report \|\| true;[^\n]*exit "\$ec_exit_code"\' EXIT/', $match[1]);

        // 取得元に届かなかった時は、最後の更新の結果を変えない（届くようになっても要対応が消えないため）
        $this->assertStringNotContainsString('ec_record stopped "$cur" "" "取得元に届かない"', $script);
        $this->assertStringContainsString('ec_record manual_rollback', $script);
        $this->assertStringContainsString('report) cmd_report "$@" ;;', $script);
    }

    /**
     * @return list<string>
     */
    private function stateArgs(): array
    {
        return array_values(array_filter(explode("\n", $this->shell('ec_report_state_args'))));
    }

    /**
     * common.sh を読み込み、state と backup の置き場をこのテストの置き場にして $code を流す（set -euo pipefail はサーバーと同じ）
     */
    private function shell(string $code): string
    {
        $process = new Process(['bash', '-c', 'set -euo pipefail; . "$1/deploy/lib/common.sh"; EC_STATE="$2"; EC_BACKUP="$2/backup"; '.$code, 'sh', self::root(), $this->state]);
        $process->mustRun();

        return $process->getOutput();
    }

    private function write(string $name, string $content): void
    {
        file_put_contents("{$this->state}/{$name}", $content);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove("{$path}/{$entry}");
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
