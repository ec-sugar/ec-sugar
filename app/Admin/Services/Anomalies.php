<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Models\ShopDigest;
use App\Core\Payment\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * おかしな動きの知らせ（ADR 0103）。「破られた時に早く気づく」ために、決まった 5 つの形を数え、運営の様子の要対応の困りごとに出す。
 * メールは今の知らせの係（operations:alert）が、ほかの困りごとと同じ決まりで送る（出ている間は 1 回だけ。覚えるのは alerted_issues）。
 *
 * - 数えるのは operations:alert の回（毎分）。見るのは直前の 1 時間だけ（監査ログは種類 × 時刻の索引で引く）。返金だけは店の DB にあるので、
 *   店の数を集める回（ShopDigests、10 分ごと）が店の中で山を数え、中央には件数と合計の円だけを置く（ADR 0042 の決まりのまま）
 * - 見つけたら中央の表 anomalies に「種類・店・件数（返金は合計の円も）・最初と最後に見つけた時刻」だけを書く。接続元・鍵・運営・お客さんは書かない
 * - 最後に見つけてから HOLD_HOURS の間は運営の様子に出し続ける（しきい値の前後で出たり消えたりして、メールが何通も届かないように）
 *
 * しきい値は誤報で手間を増やさないよう控えめにした（方針: 守りは攻撃に、手間はお客さんや店の人に掛けない）。理由は定数ごとに書く。
 * 店ごとに直せるようにするのは、使われてから（まずは決まった値）
 */
final class Anomalies
{
    /** 短い間の大量の返金（店ごと） */
    public const string REFUNDS = 'anomaly_refunds';

    /** 支払いの設定の鍵の変更が、深夜かいつもと違う接続元から（店ごと） */
    public const string PAYMENT_KEYS = 'anomaly_payment_keys';

    /** 同じ接続元から、多くの店へのログインの失敗・権限の拒否（店に依らない） */
    public const string DENIALS = 'anomaly_denials';

    /** 1 つの AI エージェントの鍵からの書く操作の急な増え方（店ごと） */
    public const string AGENT_WRITES = 'anomaly_agent_writes';

    /** 運営・権限・回復・鍵の変化の束（店に依らない） */
    public const string ACCESS_CHANGES = 'anomaly_access_changes';

    public const array KEYS = [self::REFUNDS, self::PAYMENT_KEYS, self::DENIALS, self::AGENT_WRITES, self::ACCESS_CHANGES];

    /** 数える間（分）。どの種類も「この 1 時間に」 */
    public const int WINDOW_MINUTES = 60;

    /** 最後に見つけてから運営の様子に出し続ける時間。要対応のエラーと同じ 24 時間（ADR 0043） */
    public const int HOLD_HOURS = 24;

    /** 表に残す日数（最後に見つけた時から。夜の --prune で消す） */
    public const int KEEP_DAYS = 30;

    /**
     * 返金: 1 時間に 10 件以上。小さな店の返金はふつう 1 日に数件までで、取り消しの続く日でも 1 時間に 10 件は越えない。
     * 越えるのは、盗まれたログイン・鍵で返金を続けて出された時と、店の人が自分で一斉に取り消した時（後者は心当たりがあるので 1 通で済む）
     */
    public const int REFUND_COUNT = 10;

    /**
     * 返金: 1 時間に合計 ¥300,000 以上。小さな店の 1 件の注文はふつう数千円〜数万円で、高い品 1 件の返金では越えない額にした
     */
    public const int REFUND_AMOUNT = 300_000;

    /** 店の中で返金の山を見る範囲（分）。集める回（10 分ごと）の間を覆い、1 回遅れても見落とさない */
    public const int REFUND_LOOKBACK_MINUTES = 20;

    /** 店の様子の返金の山を信じる古さ（分）。止まった集め直しの古い山で、出し続けない */
    public const int REFUND_FRESH_MINUTES = 30;

    /** 深夜（日本の時刻の 0 時〜6 時の手前）。店の人が鍵を変えることはまれで、盗まれたログインが使われやすい */
    public const int NIGHT_UNTIL_HOUR = 6;

    /**
     * 拒否: 同じ接続元から 1 時間に 3 つ以上の店で断られた。運営は与えられた店しか画面に出ないので、ふつうは 1 店でも断られない
     * （権限を外された後に開いたままの画面が断られても 1 店）。3 店は、店の ID を当てて回っている形
     */
    public const int DENIED_SHOPS = 3;

    /**
     * 拒否: 同じ接続元から 1 時間に失敗が 30 回以上（店への拒否・パスキーのログインの失敗・AI エージェントの鍵の違う要求の合計）。
     * パスキーの取り消し（押し間違い）は 1 回ずつ数えられるが、30 回は人の手では届かない。鍵の当て推量は 1 分 20 回で止まるので、2 分ほどで越える
     */
    public const int DENIAL_FAILURES = 30;

    /**
     * AI エージェント: 1 つの鍵から 1 時間に書く操作が 60 件以上。まとめて値段を直すような使い方（数十件）では越えない数にした。
     * 書く道具は 1 分 10 回まで（ADR 0101）なので、越えるのは 6 分以上、上限いっぱいで書き続けた時
     */
    public const int AGENT_WRITE_COUNT = 60;

    /**
     * AI エージェント: お金に届く操作（取消・返金・クーポン）が 1 つの鍵から 1 時間に 10 件以上。承認が必須の操作で、
     * 案を大量に作って承認の一覧を埋める（承認の疲れを狙う）形。店の人がふだん頼むのは 1 件ずつ
     */
    public const int AGENT_MONEY_COUNT = 10;

    /** 書く道具（ADR 0101 の表）。道具を足したらここにも足す */
    public const array AGENT_WRITE_TOOLS = ['create_product', 'update_product', 'adjust_stock', 'mark_shipped', 'cancel_order', 'refund_order', 'create_coupon'];

    /** お金に届く書く道具（店の設定にかかわらず承認が必須のもの。ADR 0101） */
    public const array AGENT_MONEY_TOOLS = ['cancel_order', 'refund_order', 'create_coupon'];

    /**
     * 変化の束: 1 時間に 4 つの種類（運営の追加・オーナーへの変更・回復の申し込み・AI エージェントの鍵の発行）のうち 3 つ以上。
     * 初めて入れた時（運営を作る・鍵を作る）や、新しいオーナーを迎えた時（追加・オーナーへ）は 2 つまでで、3 つ重なるのは乗っ取りの後の足場づくりの形
     */
    public const int ACCESS_KINDS = 3;

    /** 拒否に数える監査ログの種類 */
    private const array DENIAL_TYPES = ['operator.tenant_denied', 'operator.login_failed', 'operator.agent_denied'];

    /** 変化の束に数える監査ログの種類と、その種類の名前（同じ名前は 1 つに数える） */
    private const array ACCESS_TYPES = [
        'operator.created' => 'created',
        'operator.role_changed' => 'owner',
        'operator.recovery_email_sent' => 'recovery',
        'operator.recovery_email_waiting' => 'recovery',
        'operator.recovery_code_used' => 'recovery',
        'operator.agent_token_issued' => 'token',
        // OAuth で AI エージェントのつなぎを許可した（鍵を作るのと同じ足場。ADR 0101）
        'operator.agent_oauth_approved' => 'token',
    ];

    /**
     * 店の中で、この 1 時間ほどの返金の山を数える（ShopDigests::collect から。店の文脈の中だけ）。
     * 終わりがこの REFUND_LOOKBACK_MINUTES 分に入る 1 時間の枠のうち、いちばん多い件数と、いちばん多い合計の円。
     * テストのモードの支払いの返金は数えない（お金が動かない。公開の前に試す店で誤報にしない）。取消・返金が要る支払い・返品・外で行った返金のどれも数える
     *
     * @return array{count: int, amount: int}
     */
    public static function refundPeak(CarbonImmutable $now): array
    {
        if (tenant() === null) {
            throw new LogicException('返金の山は店の文脈の中で数える');
        }

        $window = self::WINDOW_MINUTES;
        $row = (new Refund)->getConnection()->selectOne(<<<SQL
            SELECT coalesce(max(n), 0) AS n, coalesce(max(total), 0) AS total FROM (
                SELECT r.created_at, count(*) OVER w AS n, sum(r.amount) OVER w AS total
                FROM refunds r JOIN payment_attempts a ON a.id = r.attempt_id
                WHERE r.created_at >= ? AND a.test_mode = false
                WINDOW w AS (ORDER BY r.created_at RANGE BETWEEN INTERVAL '{$window} minutes' PRECEDING AND CURRENT ROW)
            ) peaks WHERE created_at >= ?
            SQL, [
            $now->utc()->subMinutes(self::WINDOW_MINUTES + self::REFUND_LOOKBACK_MINUTES),
            $now->utc()->subMinutes(self::REFUND_LOOKBACK_MINUTES),
        ]);

        return ['count' => (int) ($row->n ?? 0), 'amount' => (int) ($row->total ?? 0)];
    }

    /**
     * 中央で 5 つの形を数え、見つけたものを anomalies に書く（operations:alert の回から。店の文脈の外で）。
     * 1 つの形が数えられなくても（表が無い・壊れた値）、ほかの形は数える
     */
    public static function detect(CarbonImmutable $now): void
    {
        if (tenant() !== null) {
            throw new LogicException('おかしな動きは店の文脈の外で数える');
        }

        // 切るのはテストだけ（config/admin.php。環境変数では切れない）
        if (config('admin.detect_anomalies') === false) {
            return;
        }

        $since = $now->utc()->subMinutes(self::WINDOW_MINUTES);

        foreach ([
            static fn (): array => self::refunds($now),
            static fn (): array => self::paymentKeys($since),
            static fn (): array => self::denials($since),
            static fn (): array => self::agentWrites($since),
            static fn (): array => self::accessChanges($since),
        ] as $count) {
            try {
                foreach ($count() as $found) {
                    self::remember($found, $now);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * 運営の様子の要対応の困りごと（最後に見つけてから HOLD_HOURS の間）。中身は種類・店・件数（返金は合計の円も）だけ
     *
     * @param  array<string, string>  $names  店の ID → 名前
     * @return list<array{key: string, level: string, count: int, tenant: array{id: string, name: string}|null, amount?: int}>
     */
    public static function present(CarbonImmutable $now, array $names): array
    {
        return self::central()->table('anomalies')
            ->where('last_at', '>=', $now->utc()->subHours(self::HOLD_HOURS))
            ->orderBy('key')->orderBy('tenant_id')
            ->get(['key', 'tenant_id', 'count', 'amount'])
            ->map(static function (object $row) use ($names): array {
                $issue = [
                    'key' => (string) $row->key,
                    'level' => 'urgent',
                    'count' => (int) $row->count,
                    // 消えた店は名前の代わりに ID
                    'tenant' => $row->tenant_id === null ? null : ['id' => (string) $row->tenant_id, 'name' => $names[(string) $row->tenant_id] ?? (string) $row->tenant_id],
                ];

                return $row->amount === null ? $issue : $issue + ['amount' => (int) $row->amount];
            })->values()->all();
    }

    /**
     * 最後に見つけたのが KEEP_DAYS 日より前の行を消す。消した数
     */
    public static function prune(CarbonImmutable $now): int
    {
        return self::central()->table('anomalies')->where('last_at', '<', $now->utc()->subDays(self::KEEP_DAYS))->delete();
    }

    /**
     * 1. 返金（店の様子の山。新しく集めた店だけ）
     *
     * @return list<array{key: string, tenant: string|null, count: int, amount?: int}>
     */
    private static function refunds(CarbonImmutable $now): array
    {
        return ShopDigest::query()
            ->where('collected_at', '>=', $now->utc()->subMinutes(self::REFUND_FRESH_MINUTES))
            ->where(static fn ($query) => $query->where('refund_peak_count', '>=', self::REFUND_COUNT)->orWhere('refund_peak_amount', '>=', self::REFUND_AMOUNT))
            ->orderBy('tenant_id')
            ->get(['tenant_id', 'refund_peak_count', 'refund_peak_amount'])
            ->map(static fn (ShopDigest $digest): array => [
                'key' => self::REFUNDS,
                'tenant' => $digest->tenant_id,
                'count' => max(1, $digest->refund_peak_count),
                'amount' => $digest->refund_peak_amount,
            ])->values()->all();
    }

    /**
     * 2. 支払いの設定の鍵の変更（Stripe の鍵・テスト用の鍵・Webhook の秘密・Stripe へ接続）。深夜（日本の時刻）か、
     * いつもと違う接続元から入ったセッション（ADR 0083 の印。変えた時に監査ログの unusual に写す）からなら 1 回で数える
     *
     * @return list<array{key: string, tenant: string|null, count: int}>
     */
    private static function paymentKeys(CarbonImmutable $since): array
    {
        $rows = self::central()->select(<<<'SQL'
            SELECT tenant_id, count(*) AS n FROM audit_events
            WHERE type = 'operator.payment_keys_changed' AND occurred_at >= ? AND tenant_id IS NOT NULL
              AND ((data->>'unusual') = 'true' OR extract(hour FROM occurred_at AT TIME ZONE ?) < ?)
            GROUP BY tenant_id ORDER BY tenant_id
            SQL, [$since, ShopDigests::timezone(), self::NIGHT_UNTIL_HOUR]);

        return array_map(static fn (object $row): array => ['key' => self::PAYMENT_KEYS, 'tenant' => (string) $row->tenant_id, 'count' => (int) $row->n], $rows);
    }

    /**
     * 3. 同じ接続元（IPv4 は /24、IPv6 は /64。いつもと違う接続元の決め方と同じ）からの拒否と失敗。越えた接続元の数を 1 つの困りごとにする
     * （接続元が多くても 1 通。接続元そのものは置かない。どこからかは運営のログイン履歴と監査ログで見る）
     *
     * @return list<array{key: string, tenant: string|null, count: int}>
     */
    private static function denials(CarbonImmutable $since): array
    {
        $types = implode(', ', array_fill(0, count(self::DENIAL_TYPES), '?'));
        $row = self::central()->selectOne(<<<SQL
            SELECT count(*) AS sources FROM (
                SELECT network(set_masklen(ip::inet, CASE WHEN family(ip::inet) = 4 THEN 24 ELSE 64 END)) AS source
                FROM audit_events
                WHERE type IN ({$types}) AND occurred_at >= ? AND ip IS NOT NULL
                GROUP BY 1
                HAVING count(DISTINCT tenant_id) FILTER (WHERE type = 'operator.tenant_denied') >= ? OR count(*) >= ?
            ) crossed
            SQL, [...self::DENIAL_TYPES, $since, self::DENIED_SHOPS, self::DENIAL_FAILURES]);

        $sources = (int) ($row->sources ?? 0);

        return $sources > 0 ? [['key' => self::DENIALS, 'tenant' => null, 'count' => $sources]] : [];
    }

    /**
     * 4. 1 つの AI エージェントの鍵からの書く操作（MCP の tools/call の書く道具。案を作るだけの時も数える）。店ごとに、いちばん多い鍵の件数。
     *    OAuth の鍵は 1 時間ごとに入れ替わるので、許可の系統（data の grant）で 1 つに数える（PR 186 の見直しの中 2）。
     *    2 店以上の系統は、店に散らすと店ごとでは越えないので、系統の全部の店の合計も数え、越えたら店を空にした 1 行で知らせる（ADR 0104、PR 187 の見直しの低 1）
     *
     * @return list<array{key: string, tenant: string|null, count: int}>
     */
    private static function agentWrites(CarbonImmutable $since): array
    {
        $writes = implode(', ', array_fill(0, count(self::AGENT_WRITE_TOOLS), '?'));
        $money = implode(', ', array_fill(0, count(self::AGENT_MONEY_TOOLS), '?'));
        $rows = self::central()->select(<<<SQL
            SELECT tenant_id, max(n) AS n FROM (
                SELECT tenant_id, coalesce(data->>'grant', data->>'token') AS token, count(*) AS n
                FROM audit_events
                WHERE type = 'operator.agent_request' AND occurred_at >= ? AND tenant_id IS NOT NULL
                  AND data->>'method' = 'tools/call' AND data->>'tool' IN ({$writes})
                GROUP BY tenant_id, coalesce(data->>'grant', data->>'token')
                HAVING count(*) >= ? OR count(*) FILTER (WHERE data->>'tool' IN ({$money})) >= ?
            ) crossed GROUP BY tenant_id ORDER BY tenant_id
            SQL, [$since, ...self::AGENT_WRITE_TOOLS, self::AGENT_WRITE_COUNT, ...self::AGENT_MONEY_TOOLS, self::AGENT_MONEY_COUNT]);

        $out = array_map(static fn (object $row): array => ['key' => self::AGENT_WRITES, 'tenant' => (string) $row->tenant_id, 'count' => (int) $row->n], $rows);

        $spread = self::central()->selectOne(<<<SQL
            SELECT max(n) AS n FROM (
                SELECT data->>'grant' AS grant_id, count(*) AS n
                FROM audit_events
                WHERE type = 'operator.agent_request' AND occurred_at >= ? AND tenant_id IS NOT NULL AND data->>'grant' IS NOT NULL
                  AND data->>'method' = 'tools/call' AND data->>'tool' IN ({$writes})
                GROUP BY data->>'grant'
                HAVING count(DISTINCT tenant_id) > 1 AND (count(*) >= ? OR count(*) FILTER (WHERE data->>'tool' IN ({$money})) >= ?)
            ) spread
            SQL, [$since, ...self::AGENT_WRITE_TOOLS, self::AGENT_WRITE_COUNT, ...self::AGENT_MONEY_TOOLS, self::AGENT_MONEY_COUNT]);

        if (is_object($spread) && $spread->n !== null) {
            $out[] = ['key' => self::AGENT_WRITES, 'tenant' => null, 'count' => (int) $spread->n];
        }

        return $out;
    }

    /**
     * 5. 運営・権限・回復・鍵の変化の束（全部の運営で 1 つ）。オーナーへの変更は「オーナーへ上げた」時だけ数える。数は重なった種類の数
     *
     * @return list<array{key: string, tenant: string|null, count: int}>
     */
    private static function accessChanges(CarbonImmutable $since): array
    {
        $types = array_keys(self::ACCESS_TYPES);
        $marks = implode(', ', array_fill(0, count($types), '?'));
        $found = self::central()->table('audit_events')
            ->whereRaw("type IN ({$marks})", $types)
            ->where('occurred_at', '>=', $since)
            ->whereRaw("(type <> 'operator.role_changed' OR data->>'to' = 'owner')")
            ->distinct()->pluck('type')
            ->map(static fn (mixed $type): string => self::ACCESS_TYPES[(string) $type])
            ->unique()->count();

        return $found >= self::ACCESS_KINDS ? [['key' => self::ACCESS_CHANGES, 'tenant' => null, 'count' => $found]] : [];
    }

    /**
     * 見つけたものを書く。前に見つけてから HOLD_HOURS より後なら新しい続きとして数え直し、続きの中なら大きい方の数を残す
     *
     * @param  array{key: string, tenant: string|null, count: int, amount?: int}  $found
     */
    private static function remember(array $found, CarbonImmutable $now): void
    {
        $signature = mb_strcut($found['key'].':'.($found['tenant'] ?? ''), 0, 128, 'UTF-8');
        $at = $now->utc();
        $fresh = $now->utc()->subHours(self::HOLD_HOURS);

        self::central()->statement(<<<'SQL'
            INSERT INTO anomalies (signature, key, tenant_id, count, amount, first_at, last_at) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (signature) DO UPDATE SET
                first_at = CASE WHEN anomalies.last_at < ? THEN EXCLUDED.first_at ELSE anomalies.first_at END,
                count = CASE WHEN anomalies.last_at < ? THEN EXCLUDED.count ELSE GREATEST(anomalies.count, EXCLUDED.count) END,
                amount = CASE WHEN anomalies.last_at < ? THEN EXCLUDED.amount ELSE GREATEST(anomalies.amount, EXCLUDED.amount) END,
                last_at = GREATEST(anomalies.last_at, EXCLUDED.last_at)
            SQL, [$signature, $found['key'], $found['tenant'], max(1, $found['count']), $found['amount'] ?? null, $at, $at, $fresh, $fresh, $fresh]);
    }

    private static function central(): ConnectionInterface
    {
        return DB::connection(ShopDigests::central());
    }
}
