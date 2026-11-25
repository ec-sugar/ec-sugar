<?php

declare(strict_types=1);

namespace App\Admin\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * ホスト（サーバーの外）から見た状態（ADR 0043）: 証明書の期限・空き容量・最後の更新・最後のバックアップ・
 * Cloudflare Tunnel の見張り（見直しの中 5、ADR 0052）。
 * 書くのはホストの ec-sugar が呼ぶ operations:host-report だけ（置き換えで）。読むのは運営の様子（OperationsOverview）。
 *
 * 決まり（ADR 0043「作って決めたこと（区切り 2）」）:
 * - 証明書: 残り 14 日未満は近いうちに、3 日未満か切れたら要対応。入口から読めなかったホストは近いうちに
 * - 空き容量: 空きが 15% 未満は近いうちに、5% 未満は要対応
 * - 更新: 最後の更新が失敗・ロールバック・止まったなら要対応（次の成功で消える）。手でのロールバックは困りごとにしない。
 *   取得元（リモート）に 48 時間届いていなければ近いうちに
 * - バックアップ: 最後が失敗か、48 時間より前なら要対応。報告にバックアップが無ければ近いうちに
 * - 報告そのもの: 48 時間来ていなければ近いうちに。一度も来ていなければ（ステージングや手元の開発）今どおり「まだ見ていません」
 * - トンネルの見張り（Cloudflare Tunnel の形だけ。ほかの形は行が無く、画面にも出さない）: よそからの接続役（トークンが漏れたかもしれない）と
 *   接続役が 0（トンネルが落ちている）は要対応、確かめられない（API に届かない）は近いうちに、読むだけの鍵が無ければ「見ていません」。
 *   最後に見た時刻が 1 時間より前なら（10 分ごとの見張りが止まった）近いうちに
 * - 中の照らし（ADR 0105。報告が無ければ画面に出さない）: あるべき姿との違いは、違いの名前ごとに要対応（別の違いが出たらまた知らせる）。
 *   外の見張り（ec-sugar-sentinel）が来たことがあって 30 分来ていなければ要対応（見張りの機械が止まったか、黙らされた）。
 *   ホストは変わった時だけ報告するので、ここでは古さを見ない（中が黙らされたことは外の見張りが「様子が古い」で気づく）
 * - サーバーの外のバックアップ（ADR 0110。置き場を設定したサーバーだけ。報告が無ければ画面に出さない）: 最後の回の失敗は、置き場に置けなかっただけなら近いうちに
 *   （1 回の失敗は回線のこともある。次の成功で消える）。最後に送れてから 48 時間より前なら要対応（失敗が続いている・送る回が止まった）。
 *   置き場が上書きか削除を断らなかった（ロックが掛かっていない・外された）は要対応。ロックを確かめられなかっただけなら困りごとにしない
 */
final class HostChecks
{
    public const int CERT_SOON_DAYS = 14;

    public const int CERT_URGENT_DAYS = 3;

    public const int DISK_SOON_PERCENT = 15;

    public const int DISK_URGENT_PERCENT = 5;

    public const int BACKUP_STALE_HOURS = 48;

    public const int REPORT_STALE_HOURS = 48;

    public const int CHECK_STALE_HOURS = 48;

    /** トンネルの見張り（10 分ごと）の結果がこれより古ければ、見張りが止まったとみなす（分） */
    public const int TUNNEL_STALE_MINUTES = 60;

    /** サーバーの外へ最後にバックアップを送れてからこれより経てば要対応（時間。手元のバックアップの古さと同じ。ADR 0110 の 5） */
    public const int OFFSITE_STALE_HOURS = 48;

    /** トンネルの見張りの結果のうち、困りごとにするもの → [困りごとの印, 段] */
    public const array TUNNEL_TROUBLES = ['foreign' => ['tunnel_foreign', 'urgent'], 'down' => ['tunnel_down', 'urgent'], 'unknown' => ['tunnel_unknown', 'soon']];

    /** 更新の結果のうち、困りごと（要対応）にするもの → 困りごとの印 */
    public const array UPDATE_TROUBLES = ['failed' => 'update_failed', 'rolled_back' => 'update_rolled_back', 'stopped' => 'update_stopped'];

    /**
     * 1 回の報告で前の報告を丸ごと置き換える（中央の 1 つのトランザクションで。読む側が半端な報告を見ない）
     *
     * @param  list<array<string, mixed>>  $rows  列の名前 → 値（kind・name・result・at・checked_at・version・free_percent・free_bytes）
     */
    public static function replace(array $rows, CarbonImmutable $now): void
    {
        if (tenant() !== null) {
            throw new LogicException('ホストの報告は店の文脈の外で書く');
        }

        $connection = DB::connection(ShopDigests::central());
        $connection->transaction(static function () use ($connection, $rows, $now): void {
            // 同時に 2 つの報告が来ても、消して入れ直す間に混ざらないよう順番にする（読むだけの運営の様子は待たせない）
            $connection->statement('LOCK TABLE host_checks IN EXCLUSIVE MODE');
            $connection->table('host_checks')->delete();
            // 1 文でまとめて入れるので、どの行も同じ列にそろえる（無い列は空）
            $empty = ['result' => null, 'at' => null, 'checked_at' => null, 'version' => null, 'free_percent' => null, 'free_bytes' => null];
            $connection->table('host_checks')->insert(array_map(
                static fn (array $row): array => $row + $empty + ['reported_at' => $now->utc()],
                $rows,
            ));
        });
    }

    /**
     * 機械の調子のうちホストから見たもの（certificates・disk・updates・backup・host・tunnel・integrity・offsite）と、困りごと。
     * tunnel は見張りの報告が無ければ（Cloudflare Tunnel の形でない）null。offsite も報告に無ければ（置き場を設定していない）null
     *
     * @return array{health: array<string, array<string, mixed>|null>, issues: list<array{key: string, level: string, count: int, tenant: null, subject: string}>}
     */
    public static function present(CarbonImmutable $now): array
    {
        $rows = DB::connection(ShopDigests::central())->table('host_checks')->orderBy('kind')->orderBy('name')->get();

        if ($rows->isEmpty()) {
            // 報告が一度も無い（ステージングや手元の開発）。見ていないことを正直に返す
            return ['health' => [
                'certificates' => ['status' => 'unknown'],
                'disk' => ['status' => 'unknown'],
                'updates' => ['status' => 'unknown'],
                'backup' => ['status' => 'unknown'],
                'host' => ['status' => 'unknown', 'reported_at' => null],
                'tunnel' => null,
                'integrity' => null,
                'offsite' => null,
            ], 'issues' => []];
        }

        $issues = [];
        $time = static fn (mixed $value): ?CarbonImmutable => $value === null ? null : CarbonImmutable::parse((string) $value);
        $reported = $rows->max(static fn (object $row): string => (string) $row->reported_at);
        $reportedAt = CarbonImmutable::parse((string) $reported);
        $add = static function (string $key, string $level, int $count, string $subject = '') use (&$issues): void {
            $issues[] = ['key' => $key, 'level' => $level, 'count' => $count, 'tenant' => null, 'subject' => $subject];
        };

        // 証明書（ホスト名ごと）
        $certificates = [];

        foreach ($rows->where('kind', 'certificate') as $row) {
            $expires = $time($row->at);

            if ($expires === null) {
                $certificates[] = ['host' => $row->name, 'expires_at' => null, 'days' => null, 'status' => 'warn'];
                $add('certificate_unreadable', 'soon', 1, $row->name);

                continue;
            }

            // 残りの日数は切り捨て（13 日と 23 時間は 13 日）。切れたら負
            $days = (int) floor(($expires->getTimestamp() - $now->getTimestamp()) / 86400);
            $status = 'ok';

            if ($expires->lessThanOrEqualTo($now)) {
                $status = 'bad';
                $add('certificate_expired', 'urgent', $days, $row->name);
            } elseif ($days < self::CERT_URGENT_DAYS) {
                $status = 'bad';
                $add('certificate_urgent', 'urgent', $days, $row->name);
            } elseif ($days < self::CERT_SOON_DAYS) {
                $status = 'warn';
                $add('certificate_expiring', 'soon', $days, $row->name);
            }

            $certificates[] = ['host' => $row->name, 'expires_at' => $expires->toIso8601String(), 'days' => $days, 'status' => $status];
        }

        // 空き容量（置き場ごと）
        $disks = [];

        foreach ($rows->where('kind', 'disk') as $row) {
            $percent = (int) $row->free_percent;
            $status = 'ok';

            if ($percent < self::DISK_URGENT_PERCENT) {
                $status = 'bad';
                $add('disk_critical', 'urgent', $percent, $row->name);
            } elseif ($percent < self::DISK_SOON_PERCENT) {
                $status = 'warn';
                $add('disk_low', 'soon', $percent, $row->name);
            }

            $disks[] = ['name' => $row->name, 'free_percent' => $percent, 'free_bytes' => (int) $row->free_bytes, 'status' => $status];
        }

        // 更新
        $updates = ['status' => 'unknown'];
        $update = $rows->firstWhere('kind', 'update');

        if ($update !== null) {
            $checkedAt = $time($update->checked_at);
            $short = $update->version === null ? null : substr((string) $update->version, 0, 12);
            // 自動の更新を入れているか（報告の update の行の name。空なら分からない = 古い台本からの報告）
            $auto = match ($update->name) {
                'yes' => true,
                'no' => false,
                default => null,
            };
            $updates = [
                'status' => 'ok',
                'result' => $update->result,
                'at' => $time($update->at)?->toIso8601String(),
                'version' => $short,
                'checked_at' => $checkedAt?->toIso8601String(),
                'auto' => $auto,
            ];

            if (isset(self::UPDATE_TROUBLES[$update->result])) {
                $updates['status'] = 'bad';
                // 印に試したバージョンを入れる（別のバージョンでまた失敗したら、もう一度知らせる）
                $add(self::UPDATE_TROUBLES[$update->result], 'urgent', 1, $short ?? '');
            } elseif ($update->result === 'manual_rollback') {
                $updates['status'] = 'warn';
            }

            // 自動の更新を入れていないサーバーは、誰も取得元を見に行かないので古くなって当たり前（開発の形は既定で入れない。高）。
            // ここで知らせると消えない「注意」になり、運営の様子そのものを見なくなる
            if ($auto !== false && $checkedAt !== null && $checkedAt->lessThan($now->subHours(self::CHECK_STALE_HOURS))) {
                $updates['status'] = $updates['status'] === 'bad' ? 'bad' : 'warn';
                $add('update_not_checked', 'soon', 1);
            }
        }

        // バックアップ
        $backup = $rows->firstWhere('kind', 'backup');

        if ($backup === null) {
            $backups = ['status' => 'warn', 'result' => null, 'at' => null];
            $add('backup_missing', 'soon', 1);
        } else {
            $at = CarbonImmutable::parse((string) $backup->at);
            $backups = ['status' => 'ok', 'result' => $backup->result, 'at' => $at->toIso8601String()];

            if ($backup->result === 'failed') {
                $backups['status'] = 'bad';
                $add('backup_failed', 'urgent', 1);
            } elseif ($at->lessThan($now->subHours(self::BACKUP_STALE_HOURS))) {
                $backups['status'] = 'bad';
                $add('backup_stale', 'urgent', 1);
            }
        }

        // Cloudflare Tunnel の見張り（見直しの中 5）
        $tunnel = null;
        $watch = $rows->firstWhere('kind', 'tunnel');

        if ($watch !== null) {
            $at = CarbonImmutable::parse((string) $watch->at);
            $tunnel = ['status' => $watch->result === 'unwatched' ? 'unknown' : 'ok', 'result' => $watch->result, 'at' => $at->toIso8601String()];

            if (isset(self::TUNNEL_TROUBLES[$watch->result])) {
                [$key, $level] = self::TUNNEL_TROUBLES[$watch->result];
                $tunnel['status'] = $level === 'urgent' ? 'bad' : 'warn';
                $add($key, $level, 1);
            }

            // 見張りが止まった（結果が古い）。前の結果の困りごとはそのまま残す（よそからの接続役は、直すまで要対応のまま）
            if ($watch->result !== 'unwatched' && $at->lessThan($now->subMinutes(self::TUNNEL_STALE_MINUTES))) {
                $tunnel['status'] = $tunnel['status'] === 'bad' ? 'bad' : 'warn';
                $add('tunnel_watch_stale', 'soon', 1);
            }
        }

        $integrity = self::integrity($rows, $add);
        $offsite = self::offsite($rows, $add, $now);

        // 報告そのもの（来なくなったら、上の値は古いまま）
        $stale = $reportedAt->lessThan($now->subHours(self::REPORT_STALE_HOURS));

        if ($stale) {
            $add('host_report_stale', 'soon', 1);
        }

        return ['health' => [
            'certificates' => $certificates === [] ? ['status' => 'unknown'] : ['status' => self::worst($certificates), 'items' => $certificates],
            'disk' => $disks === [] ? ['status' => 'unknown'] : ['status' => self::worst($disks), 'items' => $disks],
            'updates' => $updates,
            'backup' => $backups,
            'host' => ['status' => $stale ? 'warn' : 'ok', 'reported_at' => $reportedAt->toIso8601String()],
            'tunnel' => $tunnel,
            'integrity' => $integrity,
            'offsite' => $offsite,
        ], 'issues' => $issues];
    }

    /**
     * サーバーの外のバックアップ（ADR 0110）。最後の回の行が無ければ null（置き場を設定していないサーバー。画面に札も困りごとも出さない）
     *
     * @param  Collection<int, stdClass>  $rows
     * @param  callable(string, string, int, string): void  $add
     * @return array{status: string, result: string, reason: string|null, at: string|null, tried_at: string, lock: string|null, heads: array{result: string, at: string|null}|null}|null
     */
    private static function offsite(Collection $rows, callable $add, CarbonImmutable $now): ?array
    {
        $part = static fn (string $name): ?object => $rows->first(static fn (object $row): bool => $row->kind === 'offsite' && $row->name === $name);
        $last = $part('');

        if ($last === null) {
            return null;
        }

        $sentAt = $last->at === null ? null : CarbonImmutable::parse((string) $last->at);
        $reason = $part('reason');
        $lock = $part('lock');
        $heads = $part('heads');
        $status = 'ok';

        if ($last->result === 'failed') {
            // 対象に理由の語を入れる（画面が人の言葉に直す。語は報告で形を確かめた決まったものだけ）
            $word = $reason === null ? '' : (string) $reason->result;
            // 置き場に置けなかっただけ（put。回線のこともあり、次の回が送り直す）は近いうちに。ほかの理由（設定・道具・公開鍵・回数の上限）は
            // 待っても直らないので要対応。まだ 1 回も送れていないサーバーの失敗も要対応（下の「長く送れていない」は比べる時刻が無くて拾えない）
            $urgent = ($word !== '' && $word !== 'put') || $sentAt === null;
            $status = $urgent ? 'bad' : 'warn';
            $add('offsite_failed', $urgent ? 'urgent' : 'soon', 1, $word);
        }

        // 最後に送れてから長い（失敗が続いている・送る回が止まった）。まだ 1 回も送れていないサーバーは、比べる時刻が無いので見ない
        if ($sentAt !== null && $sentAt->lessThan($now->subHours(self::OFFSITE_STALE_HOURS))) {
            $status = 'bad';
            $add('offsite_stale', 'urgent', 1, '');
        }

        if ($lock !== null && $lock->result === 'open') {
            $status = 'bad';
            $add('offsite_lock_open', 'urgent', 1, '');
        }

        // 記録の連鎖の先頭（10 分ごと。ADR 0110 の 5）を送れなかった。次の回が送り直すので近いうちに。
        // 先頭が外に無い間は、中央の DB ごと書き換えられた時に、記録の末尾の切り落としに気づけない
        if ($heads !== null && $heads->result === 'failed') {
            $status = $status === 'ok' ? 'warn' : $status;
            $add('offsite_heads_failed', 'soon', 1, '');
        } elseif ($heads !== null && $heads->at !== null && CarbonImmutable::parse((string) $heads->at)->lessThan($now->subHours(self::OFFSITE_STALE_HOURS))) {
            // 前と同じ中身だった回も時刻は進む（ホストの offsite_heads_at）。報告は毎晩なので、ふつうでも 24 時間ほど古く見える。
            // バックアップと同じ 48 時間を越えたら、送る回が止まったか、アプリが先頭を出せなくなった
            $status = $status === 'ok' ? 'warn' : $status;
            $add('offsite_heads_stale', 'soon', 1, '');
        }

        return [
            'status' => $status,
            'result' => (string) $last->result,
            'reason' => $reason === null ? null : (string) $reason->result,
            'at' => $sentAt?->toIso8601String(),
            'tried_at' => CarbonImmutable::parse((string) $last->checked_at)->toIso8601String(),
            'lock' => $lock === null ? null : (string) $lock->result,
            'heads' => $heads === null ? null : [
                'result' => (string) $heads->result,
                'at' => $heads->at === null ? null : CarbonImmutable::parse((string) $heads->at)->toIso8601String(),
            ],
        ];
    }

    /**
     * 中の照らしと外の見張り（ADR 0105）。どちらの報告も無ければ null（画面に出さない）
     *
     * @param  Collection<int, stdClass>  $rows
     * @param  callable(string, string, int, string): void  $add
     * @return array{status: string, result: string|null, at: string|null, problems: list<string>, sentinel: array{result: string, at: string}|null}|null
     */
    private static function integrity(Collection $rows, callable $add): ?array
    {
        $summary = $rows->first(static fn (object $row): bool => $row->kind === 'integrity' && $row->name === '');
        $watch = $rows->firstWhere('kind', 'sentinel');

        if ($summary === null && $watch === null) {
            return null;
        }

        $problems = $rows->filter(static fn (object $row): bool => $row->kind === 'integrity' && $row->name !== '')
            ->map(static fn (object $row): string => (string) $row->name)->values()->all();
        $status = $summary === null ? 'unknown' : 'ok';

        if ($summary !== null && $summary->result === 'mismatch') {
            $status = 'bad';

            // 違いの名前ごとに知らせる（別の違いが出たらまた知らせる）。名前の無い mismatch は 1 つにまとめる
            foreach ($problems === [] ? [''] : $problems as $name) {
                // 錠が 30 分を超えて握られている（更新が止まった・握り続けられている）は、違いと分けて知らせる
                $add($name === 'lock:held' ? 'integrity_lock_held' : 'integrity_mismatch', 'urgent', 1, $name);
            }
        }

        $sentinel = null;

        if ($watch !== null) {
            $sentinel = ['result' => (string) $watch->result, 'at' => CarbonImmutable::parse((string) $watch->at)->toIso8601String()];

            if ($watch->result === 'silent') {
                $status = 'bad';
                $add('sentinel_silent', 'urgent', 1, '');
            }
        }

        return [
            'status' => $status,
            'result' => $summary === null ? null : (string) $summary->result,
            'at' => $summary === null ? null : CarbonImmutable::parse((string) $summary->at)->toIso8601String(),
            'problems' => $problems,
            'sentinel' => $sentinel,
        ];
    }

    /**
     * @param  list<array{status: string}>  $items
     */
    private static function worst(array $items): string
    {
        $statuses = array_column($items, 'status');

        return in_array('bad', $statuses, true) ? 'bad' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');
    }
}
