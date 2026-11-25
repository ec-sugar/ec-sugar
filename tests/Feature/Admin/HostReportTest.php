<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Console\HostReportCommand;
use App\Admin\Mail\OperationsAlertMail;
use App\Admin\Models\Operator;
use App\Admin\Services\OperationsOverview;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ホストから見た状態（ADR 0043）: ホストの ec-sugar が呼ぶ operations:host-report は決まった形の値だけを受け、
 * 前の報告を置き換える。運営の様子は証明書・空き容量・更新・バックアップ・報告の古さを決まりの境目で判定し、
 * 報告が一度も無ければ「まだ見ていません」。要対応の新しいものはオーナーへのメールに出ている間 1 回だけ乗る
 */
final class HostReportTest extends TestCase
{
    use DatabaseTruncation;

    private const string COMMIT = '0123456789abcdef0123456789abcdef01234567';

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-10-31 04:00', 'Asia/Tokyo');
        $this->travelTo($this->now);
        // APP_KEY の控えは確かめた後のサーバーとして流す（報告が来たサーバーでは、答えるまで「近いうちに」に出て 1 回メールが出る。
        // その確かめは OperationsOverviewTest と OperationsAlertTest。ここはホストの報告の決まりだけを見る）
        DB::table('operations_confirmations')->insert(['key' => 'app_key_backup', 'confirmed_at' => $this->now, 'operator_id' => null]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function badReports(): array
    {
        return [
            '自由な文（更新の結果）' => [['--update-result' => '更新に失敗した（ログを見る）', '--update-at' => '2026-10-31T03:30:00+09:00']],
            '決まっていない語（更新の結果）' => [['--update-result' => 'broken', '--update-at' => '2026-10-31T03:30:00+09:00']],
            '結果だけで時刻が無い' => [['--update-result' => 'ok']],
            '時刻だけで結果が無い' => [['--backup-at' => '2026-10-31T03:30:00+09:00']],
            'バージョンが 16 進 40 桁でない' => [['--update-result' => 'ok', '--update-at' => '2026-10-31T03:30:00+09:00', '--update-version' => 'v1.2.3']],
            '時刻の形が違う（日本語）' => [['--backup-result' => 'ok', '--backup-at' => '2026年10月31日 03:30']],
            '時刻が繰り上がる（13 月）' => [['--backup-result' => 'ok', '--backup-at' => '2026-13-01T03:30:00+09:00']],
            '時刻が未来（1 時間より先）' => [['--backup-result' => 'ok', '--backup-at' => '2026-10-31T05:01:00+09:00']],
            'バックアップの結果が決まった語でない' => [['--backup-result' => 'rolled_back', '--backup-at' => '2026-10-31T03:30:00+09:00']],
            '自動の更新の有無が yes・no でない' => [['--update-auto' => '入れた']],
            'ホスト名が大文字' => [['--cert' => ['Admin.Example.com,2026-12-01T00:00:00Z']]],
            'ホスト名にスキーム' => [['--cert' => ['https://admin.example.com,2026-12-01T00:00:00Z']]],
            'ホスト名に空白と文' => [['--cert' => ['admin.example.com 期限が近い,2026-12-01T00:00:00Z']]],
            '証明書の区切りが多い' => [['--cert' => ['admin.example.com,2026-12-01T00:00:00Z,extra']]],
            '証明書の期限が 20 年より先' => [['--cert' => ['admin.example.com,2047-01-01T00:00:00Z']]],
            '証明書のホストが重なる' => [['--cert' => ['admin.example.com,2026-12-01T00:00:00Z', 'admin.example.com,unreadable']]],
            '証明書が多すぎる' => [['--cert' => array_map(static fn (int $i): string => "h{$i}.example.com,unreadable", range(1, 21))]],
            '空きの割合が 100 より上' => [['--disk' => ['data,101,1000']]],
            '空きの割合が負' => [['--disk' => ['data,-1,1000']]],
            '空きの割合が小数' => [['--disk' => ['data,12.5,1000']]],
            '保存先の名前が決まっていない' => [['--disk' => ['/var/lib/docker,12,1000']]],
            'バイト数が大きすぎる' => [['--disk' => ['data,12,9999999999999999999']]],
            '保存先が重なる' => [['--disk' => ['data,12,1000', 'data,13,1000']]],
            // Cloudflare Tunnel の見張り（見直しの中 5）: 決まった語と時刻だけ。住所や文は受けない
            'トンネルの語が決まっていない' => [['--tunnel' => 'leaked', '--tunnel-at' => '2026-10-31T03:50:00+09:00']],
            'トンネルの語に住所を付ける' => [['--tunnel' => 'foreign 203.0.113.5', '--tunnel-at' => '2026-10-31T03:50:00+09:00']],
            'トンネルの語が大文字' => [['--tunnel' => 'OK', '--tunnel-at' => '2026-10-31T03:50:00+09:00']],
            'トンネルの語だけで時刻が無い' => [['--tunnel' => 'ok']],
            'トンネルの時刻だけで語が無い' => [['--tunnel-at' => '2026-10-31T03:50:00+09:00']],
            'トンネルの時刻が未来' => [['--tunnel' => 'ok', '--tunnel-at' => '2026-10-31T05:01:00+09:00']],
            // 中の照らしと外の見張り（ADR 0105）: 決まった語・時刻・違いの名前だけ。値・パス・文は受けない
            '照らしの語が決まっていない' => [['--integrity' => 'tampered', '--integrity-at' => '2026-10-31T03:50:00+09:00']],
            '照らしの時刻が無い' => [['--integrity' => 'ok']],
            '違いの名前が無いのに mismatch' => [['--integrity' => 'mismatch', '--integrity-at' => '2026-10-31T03:50:00+09:00']],
            'ok なのに違いの名前がある' => [['--integrity' => 'ok', '--integrity-at' => '2026-10-31T03:50:00+09:00', '--integrity-problem' => ['image:app']]],
            '違いの名前にパス' => [['--integrity' => 'mismatch', '--integrity-at' => '2026-10-31T03:50:00+09:00', '--integrity-problem' => ['file:/srv/ec-sugar/compose']]],
            '違いの名前に値（ハッシュの後ろに空白）' => [['--integrity' => 'mismatch', '--integrity-at' => '2026-10-31T03:50:00+09:00', '--integrity-problem' => ['image:app sha256:abc']]],
            '違いの名前が大文字' => [['--integrity' => 'mismatch', '--integrity-at' => '2026-10-31T03:50:00+09:00', '--integrity-problem' => ['IMAGE:app']]],
            '違いの名前が重なる' => [['--integrity' => 'mismatch', '--integrity-at' => '2026-10-31T03:50:00+09:00', '--integrity-problem' => ['image:app', 'image:app']]],
            '違いの名前が多すぎる' => [['--integrity' => 'mismatch', '--integrity-at' => '2026-10-31T03:50:00+09:00', '--integrity-problem' => array_map(static fn (int $i): string => "file:unit-{$i}", range(1, 21))]],
            '違いの名前だけで語が無い' => [['--integrity-problem' => ['image:app']]],
            '見張りの語が決まっていない' => [['--sentinel' => 'gone', '--sentinel-at' => '2026-10-31T03:50:00+09:00']],
            '見張りの時刻が無い' => [['--sentinel' => 'silent']],
            '見張りの時刻が未来' => [['--sentinel' => 'seen', '--sentinel-at' => '2026-10-31T05:01:00+09:00']],
            // サーバーの外のバックアップ（ADR 0110）: 決まった語と時刻だけ。入口・バケット・鍵・バックアップの名前・文は受けない
            '外のバックアップの語が決まっていない' => [['--offsite' => 'sent', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00']],
            '外のバックアップの語が大文字' => [['--offsite' => 'OK', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00']],
            '外のバックアップの語だけで試した時刻が無い' => [['--offsite' => 'ok']],
            '外のバックアップの試した時刻だけで語が無い' => [['--offsite-tried-at' => '2026-10-31T03:50:00+09:00']],
            '外のバックアップの試した時刻が未来' => [['--offsite' => 'ok', '--offsite-tried-at' => '2026-10-31T05:01:00+09:00']],
            '外のバックアップの送れた時刻の形が違う（バックアップの名前）' => [['--offsite' => 'ok', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-at' => '20261031-034500']],
            '外のバックアップの送れた時刻が未来' => [['--offsite' => 'ok', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-at' => '2026-10-31T05:01:00+09:00']],
            '外のバックアップの送れた時刻だけ' => [['--offsite-at' => '2026-10-31T03:50:00+09:00']],
            '外のバックアップの理由が決まっていない語' => [['--offsite' => 'failed', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-reason' => 'timeout']],
            '外のバックアップの理由が文' => [['--offsite' => 'failed', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-reason' => '保存先の答え 403']],
            '外のバックアップの理由に入口の URL' => [['--offsite' => 'failed', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-reason' => 'put https://s3.example.com/bucket']],
            '外のバックアップの理由が長い' => [['--offsite' => 'failed', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-reason' => str_repeat('put', 100)]],
            '外のバックアップが ok なのに理由がある' => [['--offsite' => 'ok', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-reason' => 'put']],
            '外のバックアップの理由だけ' => [['--offsite-reason' => 'put']],
            '保存先のロックの語が決まっていない' => [['--offsite' => 'ok', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-lock' => 'locked']],
            '保存先のロックの語だけ' => [['--offsite-lock' => 'open']],
            '連鎖の先頭の語が決まっていない' => [['--offsite' => 'ok', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-heads' => 'skip']],
            '連鎖の先頭の時刻だけで語が無い' => [['--offsite' => 'ok', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-heads-at' => '2026-10-31T03:50:00+09:00']],
            '連鎖の先頭の時刻の形が違う' => [['--offsite' => 'ok', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-heads' => 'ok', '--offsite-heads-at' => '20261031T035000Z']],
            '何も渡さない' => [[]],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('badReports')]
    public function test_values_of_the_wrong_shape_are_refused_and_nothing_is_written(array $options): void
    {
        $this->report(['--disk' => ['data,40,1000']]);
        $before = DB::table('host_checks')->get()->all();

        // 終わりの番号は 0 でない。前の報告はそのまま
        $this->artisan('operations:host-report', $options)->assertFailed();
        $this->assertEquals($before, DB::table('host_checks')->get()->all());
    }

    public function test_free_text_as_an_argument_or_an_unknown_option_is_refused(): void
    {
        // 位置の引数（自由な文）と、決めていない選択肢は、コマンドが受け付けない（CLI では終わりの番号 1）
        foreach (['operations:host-report 更新に失敗した', 'operations:host-report --message=失敗'] as $command) {
            try {
                $this->artisan($command);
                $this->fail("断られなかった: {$command}");
            } catch (ConsoleRuntimeException) {
                $this->assertSame(0, DB::table('host_checks')->count());
            }
        }
    }

    public function test_a_report_replaces_the_previous_one_and_holds_only_fixed_columns(): void
    {
        $this->report([
            '--cert' => ['admin.example.com,2026-12-31T00:00:00+09:00', 'shop.example.com,unreadable'],
            '--disk' => ['data,40,123456789', 'backup,70,987654321'],
            '--update-result' => 'ok', '--update-at' => '2026-10-31T03:31:02+09:00', '--update-version' => self::COMMIT,
            '--update-checked-at' => '2026-10-31T03:30:10+09:00',
            '--backup-result' => 'ok', '--backup-at' => '2026-10-30T18:15:00Z',
        ]);
        $this->assertSame(6, DB::table('host_checks')->count());
        $this->assertSame(
            ['kind', 'name', 'result', 'at', 'checked_at', 'version', 'free_percent', 'free_bytes', 'reported_at'],
            array_keys((array) DB::table('host_checks')->first()),
        );

        // 次の報告は丸ごと置き換える（前にあった証明書・更新・バックアップは残らない）
        $this->report(['--disk' => ['data,39,1000']]);
        $this->assertSame([['disk', 'data', 39]], DB::table('host_checks')->get()->map(static fn (object $row): array => [$row->kind, $row->name, $row->free_percent])->all());
    }

    public function test_the_database_also_refuses_free_text(): void
    {
        $this->expectException(QueryException::class);
        DB::table('host_checks')->insert(['kind' => 'update', 'name' => '', 'result' => '失敗した', 'at' => now(), 'reported_at' => now()]);
    }

    public function test_the_database_refuses_a_free_text_auto_update_flag(): void
    {
        // 更新の行の name は空・yes・no だけ。自由な文は DB も拒む
        $this->expectException(QueryException::class);
        DB::table('host_checks')->insert(['kind' => 'update', 'name' => '入れた', 'result' => 'ok', 'at' => now(), 'reported_at' => now()]);
    }

    public function test_the_database_refuses_a_tunnel_row_of_the_wrong_shape(): void
    {
        // 決まった語でない・時刻が無い・ほかの列に値がある行は、DB も拒む
        foreach ([
            ['result' => 'leaked', 'at' => now()],
            ['result' => 'ok', 'at' => null],
            ['result' => 'ok', 'at' => now(), 'version' => self::COMMIT],
            ['result' => 'ok', 'at' => now(), 'name' => '203.0.113.5'],
        ] as $index => $row) {
            try {
                DB::transaction(static fn () => DB::table('host_checks')->insert($row + ['kind' => 'tunnel', 'name' => '', 'reported_at' => now()]));
                $this->fail("{$index} 個目の形の違う行が入った");
            } catch (QueryException) {
                $this->assertSame(0, DB::table('host_checks')->count());
            }
        }
    }

    public function test_tunnel_watch_results(): void
    {
        $at = '2026-10-31T03:55:00+09:00';

        // Tunnel の形でない（報告に見張りが無い）なら、画面に出さない
        $this->report($this->healthy());
        $this->assertNull($this->overview()['health']['tunnel']);

        foreach ([
            'ok' => ['ok', []],
            'foreign' => ['bad', [['tunnel_foreign', 'urgent', '', 1]]],
            'down' => ['bad', [['tunnel_down', 'urgent', '', 1]]],
            'unknown' => ['warn', [['tunnel_unknown', 'soon', '', 1]]],
            'unwatched' => ['unknown', []],
        ] as $result => [$status, $issues]) {
            $this->report($this->healthy() + ['--tunnel' => $result, '--tunnel-at' => $at]);
            $body = $this->overview();
            $this->assertSame($issues, $this->hostIssues($body), $result);
            $this->assertSame(['status' => $status, 'result' => $result, 'at' => '2026-10-30T18:55:00+00:00'], $body['health']['tunnel'], $result);
        }

        // 見張りが止まった（最後に見たのが 1 時間より前）: 近いうちに。よそからの接続役は止まっても要対応のまま。鍵の無いものは古さを問わない
        $this->report($this->healthy() + ['--tunnel' => 'ok', '--tunnel-at' => $at]);
        $this->assertSame([], $this->hostIssues($this->overview($this->now->addMinutes(55))));
        $body = $this->overview($this->now->addMinutes(56));
        $this->assertSame([['tunnel_watch_stale', 'soon', '', 1]], $this->hostIssues($body));
        $this->assertSame('warn', $body['health']['tunnel']['status']);
        $this->report($this->healthy() + ['--tunnel' => 'foreign', '--tunnel-at' => $at]);
        $body = $this->overview($this->now->addHours(2));
        $this->assertSame([['tunnel_foreign', 'urgent', '', 1], ['tunnel_watch_stale', 'soon', '', 1]], $this->hostIssues($body));
        $this->assertSame('bad', $body['health']['tunnel']['status']);
        $this->report($this->healthy() + ['--tunnel' => 'unwatched', '--tunnel-at' => $at]);
        $this->assertSame([], $this->hostIssues($this->overview($this->now->addHours(2))));
    }

    public function test_a_foreign_connector_or_a_down_tunnel_is_mailed_and_unknown_is_not(): void
    {
        Mail::fake();
        $owner = Operator::create(['name' => 'オーナー', 'email' => 'owner@example.com']);
        $owner->forceFill(['role' => 'owner'])->save();
        $at = '2026-10-31T03:55:00+09:00';

        // 確かめられない（API に届かない）は近いうちに。メールにしない
        $this->report($this->healthy() + ['--tunnel' => 'unknown', '--tunnel-at' => $at]);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertNothingSent();

        // よそからの接続役は要対応でメール（出ている間は 1 回だけ）
        $this->report($this->healthy() + ['--tunnel' => 'foreign', '--tunnel-at' => $at]);
        $this->artisan('operations:alert')->assertSuccessful();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === [
            'ほかの場所からトンネルに接続されています（トークンが漏れたかもしれません）',
        ]);

        // 接続役が 0 も要対応でメール
        $this->report($this->healthy() + ['--tunnel' => 'down', '--tunnel-at' => $at]);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === [
            'トンネルが接続されていません（ショップと管理画面に外からアクセスできません）',
        ]);
    }

    public function test_the_database_refuses_an_integrity_row_of_the_wrong_shape(): void
    {
        // 決まった語でない・名前にパスや空白・名前の行が ok・時刻が無い・ほかの列に値がある行は、DB も拒む
        foreach ([
            ['kind' => 'integrity', 'name' => '', 'result' => 'tampered', 'at' => now()],
            ['kind' => 'integrity', 'name' => 'file:/etc/passwd', 'result' => 'mismatch', 'at' => now()],
            ['kind' => 'integrity', 'name' => 'image:app sha256:abc', 'result' => 'mismatch', 'at' => now()],
            ['kind' => 'integrity', 'name' => 'image:app', 'result' => 'ok', 'at' => now()],
            ['kind' => 'integrity', 'name' => '', 'result' => 'ok', 'at' => null],
            ['kind' => 'integrity', 'name' => '', 'result' => 'ok', 'at' => now(), 'version' => self::COMMIT],
            ['kind' => 'sentinel', 'name' => '', 'result' => 'gone', 'at' => now()],
            ['kind' => 'sentinel', 'name' => 'x', 'result' => 'seen', 'at' => now()],
        ] as $index => $row) {
            try {
                DB::transaction(static fn () => DB::table('host_checks')->insert($row + ['reported_at' => now()]));
                $this->fail("{$index} 個目の形の違う行が入った");
            } catch (QueryException) {
                $this->assertSame(0, DB::table('host_checks')->count());
            }
        }
    }

    public function test_integrity_mismatch_and_a_silent_sentinel_are_urgent(): void
    {
        $at = '2026-10-31T03:55:00+09:00';

        // 報告に中の照らしが無ければ、画面に出さない
        $this->report($this->healthy());
        $this->assertNull($this->overview()['health']['integrity']);

        // あるべき姿のまま・外の見張りは一度も来ていない（行が無い）: 困りごとなし
        $this->report($this->healthy() + ['--integrity' => 'ok', '--integrity-at' => $at]);
        $body = $this->overview();
        $this->assertSame([], $this->hostIssues($body));
        $this->assertSame(['status' => 'ok', 'result' => 'ok', 'at' => '2026-10-30T18:55:00+00:00', 'problems' => [], 'sentinel' => null], $body['health']['integrity']);

        // 違いは名前ごとに要対応。外の見張りが来ているなら困りごとにしない
        $this->report($this->healthy() + [
            '--integrity' => 'mismatch', '--integrity-at' => $at, '--integrity-problem' => ['image:app', 'file:compose'],
            '--sentinel' => 'seen', '--sentinel-at' => $at,
        ]);
        $body = $this->overview();
        // 名前の順（file:… が image:… より先）
        $this->assertSame([['integrity_mismatch', 'urgent', 'file:compose', 1], ['integrity_mismatch', 'urgent', 'image:app', 1]], $this->hostIssues($body));
        $this->assertSame('bad', $body['health']['integrity']['status']);
        $this->assertSame(['file:compose', 'image:app'], $body['health']['integrity']['problems']);
        $this->assertSame(['result' => 'seen', 'at' => '2026-10-30T18:55:00+00:00'], $body['health']['integrity']['sentinel']);

        // 外の見張りが 30 分来ていない: 要対応（ホストが決めた語のまま。ここで古さは見ない）
        $this->report($this->healthy() + ['--integrity' => 'ok', '--integrity-at' => $at, '--sentinel' => 'silent', '--sentinel-at' => $at]);
        $body = $this->overview($this->now->addDays(3));
        $this->assertContains(['sentinel_silent', 'urgent', '', 1], $this->hostIssues($body));
        $this->assertNotContains('integrity_mismatch', array_column($this->hostIssues($body), 0));
        $this->assertSame('bad', $body['health']['integrity']['status']);
    }

    public function test_integrity_troubles_are_mailed_once_per_name(): void
    {
        Mail::fake();
        $owner = Operator::create(['name' => 'オーナー', 'email' => 'owner@example.com']);
        $owner->forceFill(['role' => 'owner'])->save();
        $at = '2026-10-31T03:55:00+09:00';

        $this->report($this->healthy() + ['--integrity' => 'mismatch', '--integrity-at' => $at, '--integrity-problem' => ['image:app']]);
        $this->artisan('operations:alert')->assertSuccessful();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === [
            'サーバー上のファイルや設定が、基準の状態と違います（image:app）。更新や設定の変更に心当たりが無ければ、不正に侵入されたかもしれません（sudo ec-sugar status で確認してください）',
        ]);

        // 別の違いが足されたら、その名前だけをもう一度知らせる。外の見張りが来なくなったのも知らせる
        $this->report($this->healthy() + [
            '--integrity' => 'mismatch', '--integrity-at' => $at, '--integrity-problem' => ['image:app', 'file:tool-ec-sugar'],
            '--sentinel' => 'silent', '--sentinel-at' => $at,
        ]);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === [
            'サーバー上のファイルや設定が、基準の状態と違います（file:tool-ec-sugar）。更新や設定の変更に心当たりが無ければ、不正に侵入されたかもしれません（sudo ec-sugar status で確認してください）',
            '外部監視（ec-sugar-sentinel）からの確認が 30 分以上届いていません（監視用のサーバーが止まったか、無効にされた可能性があります）',
        ]);
    }

    public function test_the_database_refuses_an_offsite_row_of_the_wrong_shape(): void
    {
        // 決まった語でない・試した時刻が無い・理由が文・名前が決まっていない・ほかの列に値がある行は、DB も拒む
        foreach ([
            ['name' => '', 'result' => 'sent', 'checked_at' => now()],
            ['name' => '', 'result' => 'ok', 'checked_at' => null],
            ['name' => '', 'result' => 'ok', 'checked_at' => now(), 'version' => self::COMMIT],
            ['name' => 'reason', 'result' => '保存先の答え 403'],
            ['name' => 'reason', 'result' => 'timeout'],
            ['name' => 'reason', 'result' => 'put', 'at' => now()],
            ['name' => 'lock', 'result' => 'locked'],
            ['name' => 'lock', 'result' => 'open', 'checked_at' => now()],
            ['name' => 'heads', 'result' => 'skip'],
            ['name' => 'ec-sugar-offsite', 'result' => 'ok', 'checked_at' => now()],
            ['name' => 's3.example.com', 'result' => 'ok'],
        ] as $index => $row) {
            try {
                DB::transaction(static fn () => DB::table('host_checks')->insert($row + ['kind' => 'offsite', 'reported_at' => now()]));
                $this->fail("{$index} 個目の形の違う行が入った");
            } catch (QueryException) {
                $this->assertSame(0, DB::table('host_checks')->count());
            }
        }
    }

    public function test_without_an_offsite_report_nothing_is_shown(): void
    {
        // 置き場を設定していないサーバー（報告に --offsite… が無い）: 札も困りごとも出さない。何日経っても出ない（消えない警告にしない）
        $this->report($this->healthy());
        $body = $this->overview();
        $this->assertNull($body['health']['offsite']);
        $this->assertSame([], $this->hostIssues($body));
        $this->assertSame([], $this->offsiteIssues($this->overview($this->now->addDays(30))));
        $this->assertSame(0, DB::table('host_checks')->where('kind', 'offsite')->count());

        // 報告が一度も無いサーバー（手元の開発）も同じ
        DB::table('host_checks')->delete();
        $body = $this->overview();
        $this->assertNull($body['health']['offsite']);
        $this->assertSame([], $this->hostIssues($body));
    }

    public function test_offsite_results(): void
    {
        $tried = '2026-10-31T03:50:00+09:00';
        $sent = '2026-10-31T03:20:00+09:00';
        $ok = $this->healthy() + ['--offsite' => 'ok', '--offsite-tried-at' => $tried, '--offsite-at' => $sent];

        // 送れている: 困りごとなし。札には結果と時刻だけ（入口・バケット・バックアップの名前は持っていない）
        $this->report($ok + ['--offsite-lock' => 'ok', '--offsite-heads' => 'ok', '--offsite-heads-at' => $tried]);
        $body = $this->overview();
        $this->assertSame([], $this->hostIssues($body));
        $this->assertSame([
            'status' => 'ok', 'result' => 'ok', 'reason' => null, 'at' => '2026-10-30T18:20:00+00:00', 'tried_at' => '2026-10-30T18:50:00+00:00',
            'lock' => 'ok', 'heads' => ['result' => 'ok', 'at' => '2026-10-30T18:50:00+00:00'],
        ], $body['health']['offsite']);
        $this->assertSame(
            [['', 'ok'], ['heads', 'ok'], ['lock', 'ok']],
            DB::table('host_checks')->where('kind', 'offsite')->orderBy('name')->get()->map(static fn (object $row): array => [$row->name, $row->result])->all(),
        );

        // 最後の回が失敗。対象は理由の決まった語。置き場に置けなかっただけ（put）は近いうちに、待っても直らない理由は要対応
        foreach (HostReportCommand::OFFSITE_REASONS as $reason) {
            $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-at' => $sent, '--offsite-reason' => $reason]);
            $body = $this->overview();
            $this->assertSame([['offsite_failed', $reason === 'put' ? 'soon' : 'urgent', $reason, 1]], $this->hostIssues($body), $reason);
            $this->assertSame($reason === 'put' ? 'warn' : 'bad', $body['health']['offsite']['status'], $reason);
            $this->assertSame($reason, $body['health']['offsite']['reason'], $reason);
        }

        // 理由の語が無い失敗（ホストが知らない語を渡さなかった）も、失敗として出す。前に送れていれば近いうちに
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-at' => $sent]);
        $this->assertSame([['offsite_failed', 'soon', '', 1]], $this->hostIssues($this->overview()));
        // まだ 1 回も送れていないサーバーの失敗は要対応（「長く送れていない」では拾えないので、ここで知らせる）
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried]);
        $body = $this->overview();
        $this->assertSame([['offsite_failed', 'urgent', '', 1]], $this->hostIssues($body));
        $this->assertSame(['status' => 'bad', 'result' => 'failed', 'reason' => null, 'at' => null, 'tried_at' => '2026-10-30T18:50:00+00:00', 'lock' => null, 'heads' => null], $body['health']['offsite']);

        // 次の成功で消える（前の理由の行も残らない）
        $this->report($ok);
        $this->assertSame([], $this->hostIssues($this->overview()));
        $this->assertSame(0, DB::table('host_checks')->where('kind', 'offsite')->where('name', 'reason')->count());

        // 置き場のロックが効いていない: 要対応。確かめられなかっただけ（unknown）は困りごとにしない。効いた回で消える
        $this->report($ok + ['--offsite-lock' => 'open']);
        $body = $this->overview();
        $this->assertSame([['offsite_lock_open', 'urgent', '', 1]], $this->hostIssues($body));
        $this->assertSame('bad', $body['health']['offsite']['status']);
        $this->report($ok + ['--offsite-lock' => 'unknown']);
        $body = $this->overview();
        $this->assertSame([], $this->hostIssues($body));
        $this->assertSame('unknown', $body['health']['offsite']['lock']);
        $this->report($ok + ['--offsite-lock' => 'ok']);
        $this->assertSame([], $this->hostIssues($this->overview()));

        // 失敗とロックが重なれば両方（札は重い方。困りごとは要対応が先に並ぶ）
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-at' => $sent, '--offsite-reason' => 'put', '--offsite-lock' => 'open']);
        $body = $this->overview();
        $this->assertSame([['offsite_lock_open', 'urgent', '', 1], ['offsite_failed', 'soon', 'put', 1]], $this->hostIssues($body));
        $this->assertSame('bad', $body['health']['offsite']['status']);

        // 連鎖の先頭を送れなかった: 近いうちに（次の回が送り直す）。札は warn。送れたら消える（operations:chain-heads ができたので出す。ADR 0110 の 5）
        $this->report($ok + ['--offsite-heads' => 'failed']);
        $body = $this->overview();
        $this->assertSame([['offsite_heads_failed', 'soon', '', 1]], $this->hostIssues($body));
        $this->assertSame(['result' => 'failed', 'at' => null], $body['health']['offsite']['heads']);
        $this->assertSame('warn', $body['health']['offsite']['status']);
        $this->report($ok + ['--offsite-heads' => 'ok', '--offsite-heads-at' => $tried]);
        $this->assertSame([], $this->hostIssues($this->overview()));
        // 先頭を送れた（か、前と同じと確かめた）時刻から 48 時間を越えた: 近いうちに（バックアップは送れている）
        $this->report($ok + ['--offsite-at' => '2026-10-31T03:20:00+09:00', '--offsite-heads' => 'ok', '--offsite-heads-at' => '2026-10-29T03:00:00+09:00']);
        $this->assertSame([['offsite_heads_stale', 'soon', '', 1]], $this->hostIssues($this->overview()));
        $this->report($ok + ['--offsite-heads' => 'ok', '--offsite-heads-at' => $tried]);
        $this->assertSame([], $this->hostIssues($this->overview()));

        // 置き場の設定を外した（次の報告に --offsite… が無い）: 行も困りごとも札も消える
        $this->report($ok + ['--offsite-lock' => 'open']);
        $this->report($this->healthy());
        $body = $this->overview();
        $this->assertNull($body['health']['offsite']);
        $this->assertSame([], $this->hostIssues($body));
    }

    public function test_offsite_age_boundary(): void
    {
        // 最後に送れてから 48 時間ちょうどは問題なし・それより前は要対応（最後の回が ok でも。送る回が止まっていても、こちらの時計で気づく）
        $exactly = $this->now->subHours(48)->format(DATE_ATOM);
        $older = $this->now->subHours(48)->subMinute()->format(DATE_ATOM);
        $tried = '2026-10-31T03:50:00+09:00';

        $this->report($this->healthy() + ['--offsite' => 'ok', '--offsite-tried-at' => $tried, '--offsite-at' => $exactly]);
        $body = $this->overview();
        $this->assertSame([], $this->offsiteIssues($body));
        $this->assertSame('ok', $body['health']['offsite']['status']);

        $this->report($this->healthy() + ['--offsite' => 'ok', '--offsite-tried-at' => $tried, '--offsite-at' => $older]);
        $body = $this->overview();
        $this->assertSame([['offsite_stale', 'urgent', '', 1]], $this->offsiteIssues($body));
        $this->assertSame('bad', $body['health']['offsite']['status']);

        // 同じ報告のまま時間だけが経っても出る（ホストは変わった時と毎晩しか報告しない）。境目は送れた時刻からちょうど 48 時間
        $this->report($this->healthy() + ['--offsite' => 'ok', '--offsite-tried-at' => $tried, '--offsite-at' => $tried]);
        $sentAt = CarbonImmutable::parse($tried);
        $this->assertSame([], $this->offsiteIssues($this->overview($sentAt->addHours(48))));
        $this->assertSame([['offsite_stale', 'urgent', '', 1]], $this->offsiteIssues($this->overview($sentAt->addHours(48)->addSecond())));

        // 失敗が続いて 48 時間を越えたら、失敗と長く送れていない（要対応）の両方。置き場に置けないだけの失敗は近いうちにのまま
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-at' => $older, '--offsite-reason' => 'put']);
        $this->assertEqualsCanonicalizing([['offsite_stale', 'urgent', '', 1], ['offsite_failed', 'soon', 'put', 1]], $this->offsiteIssues($this->overview()));
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-at' => $older, '--offsite-reason' => 'gave-up']);
        $this->assertEqualsCanonicalizing([['offsite_stale', 'urgent', '', 1], ['offsite_failed', 'urgent', 'gave-up', 1]], $this->offsiteIssues($this->overview()));

        // まだ 1 回も送れていないサーバーは、比べる時刻が無いので「長く送れていない」は出さない。失敗そのものを要対応で出す
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-reason' => 'config']);
        $this->assertSame([['offsite_failed', 'urgent', 'config', 1]], $this->offsiteIssues($this->overview($this->now->addDays(10))));
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-reason' => 'put']);
        $this->assertSame([['offsite_failed', 'urgent', 'put', 1]], $this->offsiteIssues($this->overview()));

        // 新しく送れたら消える
        $this->report($this->healthy() + ['--offsite' => 'ok', '--offsite-tried-at' => $tried, '--offsite-at' => $tried]);
        $this->assertSame([], $this->offsiteIssues($this->overview()));
    }

    public function test_offsite_troubles_are_mailed_when_urgent_and_once_while_they_last(): void
    {
        Mail::fake();
        $owner = Operator::create(['name' => 'オーナー', 'email' => 'owner@example.com']);
        $owner->forceFill(['role' => 'owner'])->save();
        $tried = '2026-10-31T03:50:00+09:00';
        $older = $this->now->subHours(49)->format(DATE_ATOM);

        // 最後の回の失敗だけ（近いうちに）はメールにしない
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-at' => $tried, '--offsite-reason' => 'put']);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertNothingSent();

        // 48 時間を越えて送れていない: 要対応でメール（出ている間は 1 回だけ）
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-at' => $older, '--offsite-reason' => 'put']);
        $this->artisan('operations:alert')->assertSuccessful();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === [
            'サーバーの外へ、バックアップを 48 時間以上送れていません（サーバーで sudo ec-sugar offsite status）',
        ]);

        // 置き場のロックが効いていない: 要対応でメール
        $this->report($this->healthy() + ['--offsite' => 'ok', '--offsite-tried-at' => $tried, '--offsite-at' => $tried, '--offsite-lock' => 'open']);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === [
            'サーバーの外の保存先で、送ったバックアップを上書き・削除できる状態です（削除できない期間の設定が解除されているか、設定されていません。保存先の画面で確認してください）',
        ]);

        // 消えて、また出たら知らせる
        $this->report($this->healthy() + ['--offsite' => 'ok', '--offsite-tried-at' => $tried, '--offsite-at' => $tried, '--offsite-lock' => 'ok']);
        $this->artisan('operations:alert')->assertSuccessful();
        $this->report($this->healthy() + ['--offsite' => 'ok', '--offsite-tried-at' => $tried, '--offsite-at' => $tried, '--offsite-lock' => 'open']);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(3);

        // 待っても直らない理由の失敗（回数の上限であきらめた）: 48 時間を待たずに要対応でメール
        $this->report($this->healthy() + ['--offsite' => 'ok', '--offsite-tried-at' => $tried, '--offsite-at' => $tried, '--offsite-lock' => 'ok']);
        $this->artisan('operations:alert')->assertSuccessful();
        $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => $tried, '--offsite-at' => $tried, '--offsite-reason' => 'gave-up']);
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(4);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === [
            'サーバーの外へ、バックアップを送れていません（待っても直らない理由か、まだ 1 回も送れていません。サーバーで sudo ec-sugar offsite status）',
        ]);
    }

    public function test_the_offsite_arguments_the_host_script_builds_are_accepted(): void
    {
        // ホストのスクリプトが state/offsite から作る引数を、そのままコマンドへ（約束がずれていないか）。置き場を設定した時だけ出る
        $state = sys_get_temp_dir().'/t0182-state-'.bin2hex(random_bytes(4));
        mkdir("{$state}/secrets", 0700, true);
        $script = 'set -euo pipefail; . "$1/deploy/lib/common.sh"; EC_STATE="$2"; EC_OFFSITE_DIR="$2/secrets"; ec_report_state_args';
        $args = static function () use ($script, $state): array {
            $process = new Process(['bash', '-c', $script, 'sh', base_path(), $state]);
            $process->mustRun();

            // ほかの値（その機械にたまたまあるバックアップなど）は見ない
            return array_values(array_filter(explode("\n", $process->getOutput()), static fn (string $arg): bool => str_starts_with($arg, '--offsite')));
        };

        try {
            // 入口・バケット・バックアップの名前（last_backup）はそもそも渡さない
            file_put_contents("{$state}/offsite", "offsite=failed\noffsite_tried_at=2026-10-31T03:50:00+09:00\noffsite_at=2026-10-30T04:25:00+09:00\nlast_backup=20261030-041500\n"
                ."reason=put\noffsite_lock=open\noffsite_heads=ok\noffsite_heads_at=2026-10-31T03:50:00+09:00\n");
            // 置き場の鍵のファイルが無い（設定していない・外した）なら、state が残っていても 1 つも出さない
            $none = $args();
            touch("{$state}/secrets/credentials");
            $built = $args();
            // 形の違う値（時刻でない値・文の付いた理由・知らないロックの語・知らない先頭の語）は渡さない
            file_put_contents("{$state}/offsite", "offsite=failed\noffsite_tried_at=2026-10-31T03:50:00+09:00\noffsite_at=きのう\nreason=put https://s3.example.com\noffsite_lock=locked\noffsite_heads=skip\n");
            $odd = $args();
            // 結果の語が決まったものでなければ、1 つも渡さない
            file_put_contents("{$state}/offsite", "offsite=sent\noffsite_tried_at=2026-10-31T03:50:00+09:00\n");
            $unknown = $args();
        } finally {
            array_map('unlink', glob("{$state}/secrets/*") ?: []);
            rmdir("{$state}/secrets");
            array_map('unlink', glob("{$state}/*") ?: []);
            rmdir($state);
        }

        $this->assertSame([], $none);
        $this->assertSame([
            '--offsite=failed', '--offsite-tried-at=2026-10-31T03:50:00+09:00', '--offsite-at=2026-10-30T04:25:00+09:00', '--offsite-reason=put',
            '--offsite-lock=open', '--offsite-heads=ok', '--offsite-heads-at=2026-10-31T03:50:00+09:00',
        ], $built);
        $this->assertSame(['--offsite=failed', '--offsite-tried-at=2026-10-31T03:50:00+09:00'], $odd);
        $this->assertSame([], $unknown);

        $options = ['--disk' => ['data,40,1000']];

        foreach ($built as $arg) {
            [$name, $value] = explode('=', $arg, 2);
            $options[$name] = $value;
        }

        $this->artisan('operations:host-report', $options)->assertSuccessful();
        $this->assertSame([['offsite_lock_open', 'urgent', '', 1], ['offsite_failed', 'soon', 'put', 1]], $this->offsiteIssues($this->overview()));
    }

    public function test_the_host_and_the_app_know_the_same_offsite_reasons(): void
    {
        // 理由の語は 3 か所（ホストの一覧・コマンドの一覧・DB の決まり）で同じ。ずれると、ホストが渡した語で報告ごと断られる
        $source = (string) file_get_contents(base_path('deploy/lib/common.sh'));
        $this->assertSame(1, preg_match('/^EC_OFFSITE_REASONS="([a-z -]+)"$/m', $source, $match));
        $this->assertSame(HostReportCommand::OFFSITE_REASONS, explode(' ', $match[1]));

        foreach (HostReportCommand::OFFSITE_REASONS as $reason) {
            $this->report($this->healthy() + ['--offsite' => 'failed', '--offsite-tried-at' => '2026-10-31T03:50:00+09:00', '--offsite-reason' => $reason]);
            $this->assertSame($reason, DB::table('host_checks')->where('kind', 'offsite')->where('name', 'reason')->value('result'));
        }
    }

    public function test_without_any_report_the_host_items_are_not_yet_seen(): void
    {
        $body = $this->overview();

        foreach (['certificates', 'disk', 'updates', 'backup'] as $key) {
            $this->assertSame(['status' => 'unknown'], $body['health'][$key]);
        }
        $this->assertSame(['status' => 'unknown', 'reported_at' => null], $body['health']['host']);
        $this->assertSame([], $this->hostIssues($body));
    }

    public function test_certificate_boundaries(): void
    {
        $at = static fn (string $offset): string => CarbonImmutable::parse('2026-10-31 04:00', 'Asia/Tokyo')->modify($offset)->utc()->format('Y-m-d\TH:i:s\Z');
        $this->report($this->healthy() + ['--cert' => [
            'd14.example.com,'.$at('+14 days'),
            'd13.example.com,'.$at('+14 days -1 minute'),
            'd3.example.com,'.$at('+3 days'),
            'd2.example.com,'.$at('+3 days -1 minute'),
            'gone.example.com,'.$at('-1 minute'),
            'dark.example.com,unreadable',
        ]]);
        $body = $this->overview();

        // 14 日は問題なし・13 日は近いうちに・3 日は近いうちに・2 日は要対応・切れたら要対応・読めなければ近いうちに
        $this->assertSame([
            ['certificate_urgent', 'urgent', 'd2.example.com', 2],
            ['certificate_expired', 'urgent', 'gone.example.com', -1],
            ['certificate_expiring', 'soon', 'd13.example.com', 13],
            ['certificate_expiring', 'soon', 'd3.example.com', 3],
            ['certificate_unreadable', 'soon', 'dark.example.com', 1],
        ], $this->hostIssues($body));
        $this->assertSame('bad', $body['health']['certificates']['status']);
        $this->assertSame(
            ['d13.example.com' => 'warn', 'd14.example.com' => 'ok', 'd2.example.com' => 'bad', 'd3.example.com' => 'warn', 'dark.example.com' => 'warn', 'gone.example.com' => 'bad'],
            array_column($body['health']['certificates']['items'], 'status', 'host'),
        );
    }

    public function test_disk_boundaries(): void
    {
        foreach ([[15, []], [14, [['disk_low', 'soon', 'data', 14]]], [5, [['disk_low', 'soon', 'data', 5]]], [4, [['disk_critical', 'urgent', 'data', 4]]]] as [$percent, $issues]) {
            $this->report(['--disk' => ["data,{$percent},1000"]] + $this->healthy());
            $this->assertSame($issues, $this->hostIssues($this->overview()), "空き {$percent}%");
        }
    }

    public function test_a_failed_or_rolled_back_update_is_urgent_until_the_next_success(): void
    {
        foreach (['failed' => 'update_failed', 'rolled_back' => 'update_rolled_back', 'stopped' => 'update_stopped'] as $result => $key) {
            $this->report(['--update-result' => $result, '--update-at' => '2026-10-31T03:31:00+09:00', '--update-version' => self::COMMIT] + $this->healthy());
            $body = $this->overview();
            $this->assertSame([[$key, 'urgent', '0123456789ab', 1]], $this->hostIssues($body), $result);
            $this->assertSame('bad', $body['health']['updates']['status']);
        }

        // 次の成功で消える。手でのロールバックは困りごとにしない（注意だけ）
        $this->report(['--update-result' => 'ok', '--update-at' => '2026-10-31T03:40:00+09:00'] + $this->healthy());
        $this->assertSame([], $this->hostIssues($this->overview()));
        $this->report(['--update-result' => 'manual_rollback', '--update-at' => '2026-10-31T03:40:00+09:00'] + $this->healthy());
        $body = $this->overview();
        $this->assertSame([], $this->hostIssues($body));
        $this->assertSame('warn', $body['health']['updates']['status']);
    }

    public function test_backup_failure_and_age_and_the_reports_own_age(): void
    {
        $exactly = $this->now->subHours(48)->format(DATE_ATOM);
        $older = $this->now->subHours(48)->subMinute()->format(DATE_ATOM);
        $disk = ['--disk' => ['data,40,1000']];

        // 48 時間ちょうどは問題なし・それより前は要対応・失敗は要対応・報告に無ければ近いうちに
        $this->report($disk + ['--backup-result' => 'ok', '--backup-at' => $exactly]);
        $this->assertSame([], $this->hostIssues($this->overview()));
        $this->report($disk + ['--backup-result' => 'ok', '--backup-at' => $older]);
        $this->assertSame([['backup_stale', 'urgent', '', 1]], $this->hostIssues($this->overview()));
        $this->report($disk + ['--backup-result' => 'failed', '--backup-at' => $exactly]);
        $this->assertSame([['backup_failed', 'urgent', '', 1]], $this->hostIssues($this->overview()));
        $this->report($disk);
        $this->assertSame([['backup_missing', 'soon', '', 1]], $this->hostIssues($this->overview()));

        // 取得元に 48 時間より前から届いていなければ近いうちに
        $this->report($this->healthy() + ['--update-checked-at' => $older]);
        $this->assertSame([['update_not_checked', 'soon', '', 1]], $this->hostIssues($this->overview()));

        // 自動の更新を入れているサーバーなら同じ（yes を渡しても変わらない）
        $this->report($this->healthy() + ['--update-checked-at' => $older, '--update-auto' => 'yes']);
        $this->assertSame([['update_not_checked', 'soon', '', 1]], $this->hostIssues($this->overview()));
        $this->assertTrue($this->overview()['health']['updates']['auto']);

        // 自動の更新を入れていないサーバー（開発の形の既定）は、誰も取得元を見に行かないので知らせない
        $this->report($this->healthy() + ['--update-checked-at' => $older, '--update-auto' => 'no']);
        $this->assertSame([], $this->hostIssues($this->overview()));
        $health = $this->overview()['health']['updates'];
        $this->assertFalse($health['auto']);
        $this->assertSame('ok', $health['status']);

        // 古い台本からの報告（渡してこない）は今までどおり知らせる＝分からないときは安全側
        $this->report($this->healthy() + ['--update-checked-at' => $older]);
        $this->assertNull($this->overview()['health']['updates']['auto']);

        // 報告そのものが 48 時間来ていなければ近いうちに（48 時間ちょうどはまだよい）
        // （バックアップの古さは別の困りごとなので、ここでは報告の古さだけを見る）
        $this->report($this->healthy());
        $this->assertNotContains(['host_report_stale', 'soon', '', 1], $this->hostIssues($this->overview($this->now->addHours(48))));
        $this->assertContains(['host_report_stale', 'soon', '', 1], $this->hostIssues($this->overview($this->now->addHours(48)->addMinute())));
        $this->assertSame('warn', $this->overview($this->now->addHours(49))['health']['host']['status']);
    }

    public function test_a_new_urgent_host_trouble_is_mailed_once_while_it_lasts(): void
    {
        Mail::fake();
        $owner = Operator::create(['name' => 'オーナー', 'email' => 'owner@example.com']);
        $owner->forceFill(['role' => 'owner'])->save();

        // 近いうちに（空き 10%）はメールにしない
        $this->report(['--disk' => ['data,10,1000']] + $this->healthy());
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertNothingSent();

        // 要対応（証明書が 2 日・空き 4%）は 1 通に
        $soon = $this->now->addDays(2)->addHour()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->report(['--disk' => ['data,4,1000'], '--cert' => ["admin.example.com,{$soon}"]] + $this->healthy());
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === [
            'admin.example.com の証明書の期限まで 2 日です（更新が遅れています）',
            'データ（DB・写真）の保存先の空きが 4% です',
        ]);

        // 出ている間は 1 回だけ
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);

        // 消えて、また出たら知らせる
        $this->report($this->healthy());
        $this->artisan('operations:alert')->assertSuccessful();
        $this->report(['--disk' => ['data,3,1000']] + $this->healthy());
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(2);
    }

    public function test_the_arguments_the_host_script_builds_are_accepted(): void
    {
        // ホストのスクリプト（deploy/lib/common.sh）が state のファイルから作る引数を、そのままコマンドへ（約束がずれていないか）
        $state = sys_get_temp_dir().'/t0096-state-'.bin2hex(random_bytes(4));
        mkdir($state);

        try {
            file_put_contents("{$state}/last_update", "time=2026-10-31T03:31:02+09:00\nresult=rolled_back\nfrom=".str_repeat('a', 40)."\nto=".self::COMMIT."\nmessage=新しいバージョンがヘルスチェックを通らなかった\n");
            file_put_contents("{$state}/last_check", "2026-10-31T03:30:10+09:00\n");
            file_put_contents("{$state}/last_backup", "time=2026-10-31T03:00:00+09:00\nresult=ok\n");
            file_put_contents("{$state}/tunnel_watch", "foreign 2026-10-31T03:55:00+09:00\n");
            // 中の照らし: ec_integrity_note が残す形（形の違う名前〔パス〕は渡さない）
            file_put_contents("{$state}/integrity", "mismatch 2026-10-30T18:50:00Z image:app file:compose /etc/passwd\n");
            file_put_contents("{$state}/integrity_sentinel", "silent 2026-10-30T18:20:00Z\n");
            $process = new Process(['bash', '-c', 'set -euo pipefail; . "$1/deploy/lib/common.sh"; EC_STATE="$2"; ec_report_state_args; ec_disk_args "$2" "$2"', 'sh', base_path(), $state]);
            $process->mustRun();
            $args = array_values(array_filter(explode("\n", $process->getOutput())));
        } finally {
            array_map('unlink', glob("{$state}/*") ?: []);
            rmdir($state);
        }

        $this->assertSame([
            '--update-result=rolled_back', '--update-at=2026-10-31T03:31:02+09:00', '--update-version='.self::COMMIT,
            '--update-checked-at=2026-10-31T03:30:10+09:00', '--backup-result=ok', '--backup-at=2026-10-31T03:00:00+09:00',
            '--tunnel=foreign', '--tunnel-at=2026-10-31T03:55:00+09:00',
            '--integrity=mismatch', '--integrity-at=2026-10-30T18:50:00Z', '--integrity-problem=image:app', '--integrity-problem=file:compose',
            '--sentinel=silent', '--sentinel-at=2026-10-30T18:20:00Z',
        ], array_slice($args, 0, 14));
        $this->assertMatchesRegularExpression('/\Adata,\d{1,3},\d+\z/', $args[14]);

        $options = [];

        // ec_host_report と同じ組み立て（空き容量の行は --disk= を前に付ける。違いの名前は繰り返す）
        foreach ($args as $arg) {
            if (str_starts_with($arg, 'data,')) {
                $options['--disk'] = [$arg];
            } else {
                [$name, $value] = explode('=', $arg, 2);

                if ($name === '--integrity-problem') {
                    $options[$name][] = $value;
                } else {
                    $options[$name] = $value;
                }
            }
        }

        $this->artisan('operations:host-report', $options)->assertSuccessful();
        $this->assertSame([
            ['update_rolled_back', 'urgent', '0123456789ab', 1], ['tunnel_foreign', 'urgent', '', 1],
            ['integrity_mismatch', 'urgent', 'file:compose', 1], ['integrity_mismatch', 'urgent', 'image:app', 1], ['sentinel_silent', 'urgent', '', 1],
        ], array_values(array_filter(
            $this->hostIssues($this->overview()),
            static fn (array $issue): bool => ! str_starts_with($issue[0], 'disk_'),
        )));
    }

    /**
     * 何も困っていない報告（空き容量・更新・バックアップ）。証明書は渡さない
     *
     * @return array<string, mixed>
     */
    private function healthy(): array
    {
        return [
            '--disk' => ['data,40,1000'],
            '--update-result' => 'ok', '--update-at' => '2026-10-31T03:31:00+09:00',
            '--backup-result' => 'ok', '--backup-at' => '2026-10-31T03:00:00+09:00',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function report(array $options): void
    {
        $this->artisan('operations:host-report', $options)->assertSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(?CarbonImmutable $at = null): array
    {
        return app(OperationsOverview::class)->present($at ?? $this->now);
    }

    /**
     * ホストの困りごとのうち、サーバーの外のバックアップのもの
     *
     * @param  array<string, mixed>  $body
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function offsiteIssues(array $body): array
    {
        return array_values(array_filter($this->hostIssues($body), static fn (array $issue): bool => str_starts_with($issue[0], 'offsite_')));
    }

    /**
     * ホストの困りごと（subject の付いたもの）を [印, 段, 対象, 数] で
     *
     * @param  array<string, mixed>  $body
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function hostIssues(array $body): array
    {
        $issues = array_values(array_filter($body['issues'], static fn (array $issue): bool => array_key_exists('subject', $issue)));

        return array_map(static fn (array $issue): array => [$issue['key'], $issue['level'], $issue['subject'], $issue['count']], $issues);
    }
}
