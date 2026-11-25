<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Jobs\QueueHeartbeat;
use App\Admin\Models\Incident;
use App\Admin\Models\ShopDailySale;
use App\Admin\Models\ShopDigest;
use App\Core\Media\Services\MediaQuota;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Throwable;

/**
 * 運営全体の画面（ADR 0042。オーナーだけ）の中身。中央の表（ShopDigests が店ごとに書いた数）と中央の仕組み（キュー）だけを読み、
 * 店の DB は読まない。返すのは件数・金額（円の整数）・店の名前と ID だけで、お客さん・注文の中身は持っていない。
 *
 * エラー（errors）は中央の incidents（ADR 0043）。この 24 時間に起きていれば要対応に 1 行足す。
 *
 * 困りごと（issues）は 2 段: urgent（要対応）と soon（近いうちに）。1 件ずつ、どこの何が何件か（key・count・店）を返し、文は画面が作る。
 * ホストから見たもの（証明書・空き容量・更新・バックアップ）は、ホストの ec-sugar が報告した値（HostChecks）。
 * 報告が一度も無ければ health で unknown（まだ見ていません）と正直に返す。ホストの困りごとには subject（ホスト名・置き場・バージョン）が付く。
 * 外への通信の出口の代理の数（この 24 時間に通した・断った数）は EgressWatch（代理を使う形だけ health に egress が付く）
 * おかしな動き（anomaly_*。ADR 0103）は Anomalies が中央の表に書いたもの。返金の山には合計の円（amount）が付く
 * APP_KEY の控え（app_key_backup_unconfirmed）は、オーナーが「サーバーの外に取った」と答えるまで「近いうちに」に出し続ける（AppKeyBackup）
 */
final class OperationsOverview
{
    /** 売上の動きの日数（今日を含む） */
    public const int DAYS = 30;

    /** 最後に集めてからこれより経ったら、定期の処理が止まっているとみなす（分。集めるのは 10 分ごと） */
    public const int STALE_MINUTES = 30;

    /** キューの待ちがこれ以上なら詰まっているとみなす */
    public const int QUEUE_BACKLOG = 100;

    /** キューの脈（QueueHeartbeat、5 分ごと）がこれより長く片付かなければ、キューの処理が止まっているとみなす（分） */
    public const int QUEUE_STALL_MINUTES = 10;

    /** 失敗した裏の仕事を数える日数 */
    public const int FAILED_JOB_DAYS = 7;

    /** 記録の改竄の検証（1 日 1 回）がこれより古ければ、検証が止まっているとみなして注意にする（時間） */
    public const int CHAIN_STALE_HOURS = 26;

    /** 店の一覧に並べる数（困りごとのある店から） */
    public const int SHOP_LIMIT = 200;

    /** エラーの節に並べる日数（ADR 0043） */
    public const int ERROR_DAYS = 7;

    /** この時間のうちに起きたエラーがあれば要対応にする */
    public const int ERROR_URGENT_HOURS = 24;

    /** エラーの節に並べる数（最後に起きた順） */
    public const int ERROR_LIMIT = 100;

    /**
     * @return array<string, mixed>
     */
    public function present(?CarbonImmutable $now = null): array
    {
        if (tenant() !== null) {
            throw new LogicException('運営全体の数は店の文脈の外で読む（店の DB は読まない）');
        }

        $timezone = ShopDigests::timezone();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $month = $now->startOfMonth();
        $firstDay = $now->startOfDay()->subDays(self::DAYS - 1);

        // 1 回の画面の数の時点をそろえる（集め直しの途中の別の店の数と混ざらないよう、読むだけの REPEATABLE READ の中で読む）
        $connection = DB::connection(ShopDigests::central());

        return $connection->transaction(function () use ($connection, $now, $month, $firstDay): array {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->read($now, $month, $firstDay);
        });
    }

    /**
     * 要対応の件数だけ（店のホームの上のオーナーへの帯。GET /api/operations/alerts）
     */
    public function urgentCount(?CarbonImmutable $now = null): int
    {
        return count(array_filter($this->present($now)['issues'], static fn (array $issue): bool => $issue['level'] === 'urgent'));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(CarbonImmutable $now, CarbonImmutable $month, CarbonImmutable $firstDay): array
    {
        $tenants = Tenant::query()->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'name', 'created_at']);
        /** @var array<string, ShopDigest> $digests */
        $digests = ShopDigest::query()->get()->keyBy('tenant_id')->all();

        // 今月の数（店ごと）。日は日本の時刻の日付で持っているので、月の境目は日付で切る
        $monthly = ShopDailySale::query()->toBase()
            ->where('day', '>=', $month->toDateString())->where('day', '<', $month->addMonth()->toDateString())
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, sum(orders) AS orders, sum(sales) AS sales, sum(test_orders) AS test_orders')
            ->get()->keyBy('tenant_id');

        $daily = ShopDailySale::query()->toBase()
            ->where('day', '>=', $firstDay->toDateString())->where('day', '<=', $now->toDateString())
            ->groupBy('day')
            ->selectRaw('day::text AS day, sum(orders) AS orders, sum(sales) AS sales')
            ->get()->keyBy('day');

        $issues = [];
        $rows = [];
        $names = [];
        $open = 0;
        $preparing = 0;
        $uncollected = 0;

        foreach ($tenants as $tenant) {
            assert($tenant instanceof Tenant);
            $id = (string) $tenant->getTenantKey();
            $digest = $digests[$id] ?? null;
            $shop = ['id' => $id, 'name' => (string) $tenant->getAttribute('name')];
            $names[$id] = $shop['name'];
            $counts = [];

            if ($digest === null || $digest->collected_at === null) {
                $uncollected++;
            }

            if ($digest?->status === 'open') {
                $open++;
            } elseif ($digest?->status === 'preparing') {
                $preparing++;
            }

            if ($digest !== null) {
                $counts = [
                    // 集められなかった（前の数のまま）
                    'collect_failed' => ['urgent', $digest->failed_at !== null ? 1 : 0],
                    'stray_payments' => ['urgent', $digest->stray_payments],
                    'refund_issues' => ['urgent', $digest->refund_issues],
                    'chain_broken' => ['urgent', $digest->chain_intact === false ? 1 : 0],
                    // 前に控えた連鎖の先頭が今の記録に無い（末尾を切り落とされた）
                    'chain_rolled_back' => ['urgent', $digest->chain_rolled_back === true ? 1 : 0],
                    'stuck_mails' => ['soon', $digest->stuck_mails],
                    // 流し損ねた知らせ（ADR 0089）。止めた記録は人が直して流し直すまで届かないので要対応、待ちは見回りが届け直すので近いうちに
                    'events_stopped' => ['urgent', $digest->event_stopped],
                    'events_waiting' => ['soon', $digest->event_waiting],
                ];
            }

            $total = 0;

            foreach ($counts as $key => [$level, $count]) {
                if ($count > 0) {
                    $issues[] = ['key' => $key, 'level' => $level, 'count' => $count, 'tenant' => $shop];
                    $total += $count;
                }
            }

            // 写真の置き場: 上限の 9 割を超えたら早めに、上限に届いたら（もう上げられない）急ぎ。数は使った割合（%）
            $percent = $digest === null ? 0 : MediaQuota::percent($digest->media_bytes);

            if ($percent >= MediaQuota::WARN_PERCENT) {
                $issues[] = ['key' => 'media_quota', 'level' => $percent >= 100 ? 'urgent' : 'soon', 'count' => $percent, 'tenant' => $shop];
                $total++;
            }

            $sums = $monthly->get($id);
            $rows[] = $shop + [
                'status' => $digest?->status,
                'month' => ['orders' => (int) ($sums->orders ?? 0), 'sales' => (int) ($sums->sales ?? 0)],
                'issues' => $total,
                'collected_at' => $digest?->collected_at?->toIso8601String(),
            ];
        }

        // 困りごとのある店から、次に今月の売上の多い店（同じなら新しい店から。tenants の並びのまま）
        $order = array_keys($rows);
        usort($order, static fn (int $a, int $b): int => [$rows[$b]['issues'], $rows[$b]['month']['sales'], $a] <=> [$rows[$a]['issues'], $rows[$a]['month']['sales'], $b]);
        $sorted = array_map(static fn (int $index): array => $rows[$index], array_slice($order, 0, self::SHOP_LIMIT));

        $health = $this->health($now, $tenants->count(), $digests, $uncollected, $issues);
        $host = HostChecks::present($now);
        $health = $host['health'] + $health;
        array_push($issues, ...$host['issues']);

        // APP_KEY の控え: オーナーが「サーバーの外に取った」と答えるまで出し続ける。ホストの報告が一度でも来たサーバーだけ
        // （ec-sugar で入れた形。手元の開発では出さない。ほかのホストの困りごとと同じ分け方）
        if (($host['health']['host']['reported_at'] ?? null) !== null && AppKeyBackup::confirmedAt() === null) {
            $issues[] = ['key' => AppKeyBackup::ISSUE, 'level' => 'soon', 'count' => 1, 'tenant' => null];
        }
        // 外への通信の出口の代理（ADR 0102）。代理を使わない開発の形では札も困りごとも無い
        $egress = EgressWatch::present($now);
        $health += $egress['health'];
        array_push($issues, ...$egress['issues']);
        // おかしな動き（ADR 0103。数えるのは operations:alert の回で、ここは読むだけ）
        array_push($issues, ...Anomalies::present($now, $names));
        $errors = $this->errors($now, $names);

        // この 24 時間にエラーがあれば、要対応のいちばん上に 1 行（場所と回数は下のエラーの節で見る）
        if ($errors['recent'] > 0) {
            array_unshift($issues, ['key' => 'incidents', 'level' => 'urgent', 'count' => $errors['recent'], 'tenant' => null]);
        }

        // 要対応を先に（同じ段の中は見つけた順）
        usort($issues, static fn (array $a, array $b): int => ($a['level'] === 'urgent' ? 0 : 1) <=> ($b['level'] === 'urgent' ? 0 : 1));

        return [
            'currency' => 'JPY',
            'shops' => ['total' => $tenants->count(), 'open' => $open, 'preparing' => $preparing],
            'month' => [
                'orders' => (int) $monthly->sum('orders'),
                'sales' => (int) $monthly->sum('sales'),
                'test_orders' => (int) $monthly->sum('test_orders'),
            ],
            'daily' => array_map(static function (int $offset) use ($firstDay, $daily): array {
                $day = $firstDay->addDays($offset)->toDateString();
                $row = $daily->get($day);

                return ['day' => $day, 'orders' => (int) ($row->orders ?? 0), 'sales' => (int) ($row->sales ?? 0)];
            }, range(0, self::DAYS - 1)),
            'version' => SystemVersion::present(),
            'health' => $health,
            'issues' => $issues,
            'tenants' => $sorted,
            'tenants_total' => count($rows),
            'errors' => $errors['items'],
            'errors_total' => $errors['total'],
        ];
    }

    /**
     * エラーの節（この 7 日。最後に起きた順）と、この 24 時間に起きたエラーの数（行の数）。中身は種類・例外のクラス・場所・店・回数・時刻だけ
     *
     * @param  array<string, string>  $names  店の ID → 名前
     * @return array{items: list<array<string, mixed>>, total: int, recent: int}
     */
    private function errors(CarbonImmutable $now, array $names): array
    {
        $since = Incident::query()->where('last_at', '>=', $now->subDays(self::ERROR_DAYS)->utc());
        $items = (clone $since)->orderByDesc('last_at')->orderBy('fingerprint')->limit(self::ERROR_LIMIT)->get()
            ->map(static fn (Incident $incident): array => [
                'id' => $incident->fingerprint,
                'kind' => $incident->kind,
                'exception' => $incident->exception,
                'location' => $incident->location,
                // 消えた店は名前の無い ID で出す
                'tenant' => $incident->tenant_id === null ? null : ['id' => $incident->tenant_id, 'name' => $names[$incident->tenant_id] ?? null],
                'count' => $incident->count,
                'first_at' => $incident->first_at->toIso8601String(),
                'last_at' => $incident->last_at->toIso8601String(),
                // 最後に起きた時の処理の ID（ADR 0087）。店の中で起きたものは、店の「処理を追う」で関係する記録を引ける
                'last_trace_id' => $incident->last_trace_id,
            ])->values()->all();

        return [
            'items' => $items,
            'total' => (clone $since)->count(),
            'recent' => Incident::query()->where('last_at', '>=', $now->subHours(self::ERROR_URGENT_HOURS)->utc())->count(),
        ];
    }

    /**
     * 機械の調子（ok・warn・bad・unknown）。困りごとに当たるものは $issues にも足す
     *
     * @param  array<string, ShopDigest>  $digests
     * @param  list<array{key: string, level: string, count: int, tenant: array{id: string, name: string}|null, subject?: string}>  $issues
     * @return array<string, array<string, mixed>>
     */
    private function health(CarbonImmutable $now, int $shops, array $digests, int $uncollected, array &$issues): array
    {
        // 定期の処理: 最後に集めた時刻が古ければ止まっている
        $latest = null;

        foreach ($digests as $digest) {
            if ($digest->collected_at !== null && ($latest === null || $digest->collected_at->greaterThan($latest))) {
                $latest = $digest->collected_at;
            }
        }

        $schedule = ['status' => 'unknown', 'at' => $latest?->toIso8601String()];

        if ($latest !== null) {
            $stale = $latest->lessThan($now->subMinutes(self::STALE_MINUTES));
            $schedule['status'] = $stale ? 'bad' : 'ok';

            if ($stale) {
                $issues[] = ['key' => 'schedule_stopped', 'level' => 'urgent', 'count' => 1, 'tenant' => null];
            }
        }

        if ($shops > 0 && $uncollected > 0) {
            $issues[] = ['key' => 'not_collected', 'level' => 'soon', 'count' => $uncollected, 'tenant' => null];
        }

        // キュー: 待ちの数（つながらなければ要対応）と、この 7 日に失敗した裏の仕事
        $queue = ['status' => 'ok', 'waiting' => null, 'failed' => null];

        try {
            $queue['waiting'] = Queue::connection()->size();
        } catch (Throwable $e) {
            report($e);
            $queue['status'] = 'bad';
            $issues[] = ['key' => 'queue_unreachable', 'level' => 'urgent', 'count' => 1, 'tenant' => null];
        }

        if ($queue['waiting'] !== null && $queue['waiting'] >= self::QUEUE_BACKLOG) {
            $queue['status'] = 'bad';
            $issues[] = ['key' => 'queue_backlog', 'level' => 'urgent', 'count' => $queue['waiting'], 'tenant' => null];
        }

        // キューの処理が止まっている（待ちが少なくても気づけるように、脈が片付かない時間で見る）。数は経った分
        try {
            $pending = QueueHeartbeat::pendingSince();
        } catch (Throwable $e) {
            report($e);
            $pending = null;
        }

        if ($pending !== null && $pending->lessThan($now->subMinutes(self::QUEUE_STALL_MINUTES))) {
            $queue['status'] = 'bad';
            $issues[] = ['key' => 'queue_stopped', 'level' => 'urgent', 'count' => (int) $pending->diffInMinutes($now, true), 'tenant' => null];
        }

        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            try {
                $queue['failed'] = DB::connection((string) config('queue.failed.database'))->table((string) config('queue.failed.table', 'failed_jobs'))
                    ->where('failed_at', '>=', $now->subDays(self::FAILED_JOB_DAYS)->utc())->count();
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (($queue['failed'] ?? 0) > 0) {
            $queue['status'] = $queue['status'] === 'bad' ? 'bad' : 'warn';
            $issues[] = ['key' => 'failed_jobs', 'level' => 'urgent', 'count' => $queue['failed'], 'tenant' => null];
        }

        // 知らせのメール（店ごとに送りかけのまま残った数の合計）
        $stuck = array_sum(array_map(static fn (ShopDigest $digest): int => $digest->stuck_mails, $digests));

        // 記録の改竄の検証（1 日 1 回）。まだ確かめていない店の数も返す
        $checked = array_filter($digests, static fn (ShopDigest $digest): bool => $digest->chain_intact !== null);
        $broken = count(array_filter($checked, static fn (ShopDigest $digest): bool => $digest->chain_intact === false || $digest->chain_rolled_back === true));
        $oldest = null;

        foreach ($checked as $digest) {
            if ($oldest === null || $digest->chain_checked_at?->lessThan($oldest) === true) {
                $oldest = $digest->chain_checked_at;
            }
        }

        return [
            'schedule' => $schedule,
            'queue' => $queue,
            'mail' => ['status' => $stuck > 0 ? 'warn' : ($digests === [] ? 'unknown' : 'ok'), 'stuck' => $stuck],
            'chain' => [
                // 検証が止まっても「問題なし」に見せない（いちばん古い検証が 26 時間より前なら注意）
                'status' => $broken > 0 ? 'bad' : ($checked === [] ? 'unknown' : ($oldest?->lessThan($now->subHours(self::CHAIN_STALE_HOURS)) === true ? 'warn' : 'ok')),
                'broken' => $broken,
                'unchecked' => max(0, $shops - count($checked)),
                'checked_at' => $oldest?->toIso8601String(),
            ],
        ];
    }
}
