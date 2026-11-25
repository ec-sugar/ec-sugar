<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Models\ShopDailySale;
use App\Admin\Models\ShopDigest;
use App\Core\Event\EventStore;
use App\Core\Event\Outbox\EventRelay;
use App\Core\Media\Services\MediaQuota;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Models\Tenant;
use App\Storefront\Orders\SentMail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 運営全体の画面の数を、店の文脈の中で数えて中央に書く（ADR 0042）。中央は店の DB を読みに行かない。
 *
 * - 店ごとの定期の処理（operations:collect、10 分ごと）が店に 1 つずつ入り、ここを呼ぶ。数えるのは店の DB のロールで、書くのは中央の 2 つの表だけ
 * - 日ごとの数は、決まった範囲（今月と 30 日の動きを覆う WINDOW_DAYS 日。作り直すときは全部）を数え直して置き換える。
 *   何度呼んでも同じ結果になる（取り消しで前の日の数が減っても、次の回で直る）
 * - 数え方はホームの数（ShopHome::counted）と同じ（返品・一部返金の済んだ分は注文の額から引く。ADR 0048）。テストの注文は件数だけ別に。金額は円の整数のまま足す
 * - 中央に送るのは件数と金額と公開の状態だけ。お客さん・注文の中身・商品名・注文の ID は送らない
 */
final class ShopDigests
{
    /** 数え直す日の範囲（今日を含めて何日前まで）。今月（最大 31 日）と 30 日の動きを覆う */
    public const int WINDOW_DAYS = 35;

    /** 送りかけのまま、これより古い知らせのメールを「届かなかった」とみなす（分） */
    public const int STUCK_MAIL_MINUTES = 60;

    public function __construct(
        private readonly StorefrontAccessService $access,
        private readonly EventStore $events,
        private readonly EventRelay $relay,
    ) {}

    /**
     * 控えた連鎖の先頭を忘れる。店の DB を作り直した後（デモの店の毎晩の戻し）と、バックアップから戻した後に呼ぶ。
     * 記録が正当に前の状態へ戻るので、忘れないと次の回が「巻き戻された」と知らせ続ける。次の回で控え直す
     *
     * @return array{sequence: int|null, hash: string|null, rolled_back: bool|null} 忘れる前の控え（記録に残すため）
     */
    public static function forgetChainHead(string $tenantId): array
    {
        // 集める回と同じ鍵の中で（集める回が古い読みで控えを書き戻さないように）
        return DB::connection(self::central())->transaction(static function () use ($tenantId): array {
            DB::connection(self::central())->select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::lockName($tenantId)]);
            $current = ShopDigest::query()->find($tenantId);
            ShopDigest::query()->whereKey($tenantId)->update(['chain_head_sequence' => null, 'chain_head_hash' => null, 'chain_rolled_back' => null]);

            return ['sequence' => $current?->chain_head_sequence, 'hash' => $current?->chain_head_hash, 'rolled_back' => $current?->chain_rolled_back];
        });
    }

    /**
     * 今の店の数を数えて中央に書く。$all で全部の日を数え直す（作り直すコマンド）、$verify で記録の改竄の検証もする（全件を読むので 1 日 1 回）。
     * もっと新しい時刻の数がもう書かれていれば書かずに false
     */
    public function collect(?CarbonImmutable $now = null, bool $all = false, bool $verify = false): bool
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('運営全体の数は店の文脈の中で数える');
        }

        $tenantId = (string) $tenant->getTenantKey();
        $timezone = self::timezone();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $from = $all ? null : $now->startOfDay()->subDays(self::WINDOW_DAYS - 1);

        $days = [];

        foreach ([false, true] as $test) {
            foreach (self::byDay($test, $from, $timezone) as $day => [$orders, $sales]) {
                $days[$day] ??= ['orders' => 0, 'sales' => 0, 'test_orders' => 0];

                if ($test) {
                    $days[$day]['test_orders'] = $orders;
                } else {
                    $days[$day]['orders'] = $orders;
                    $days[$day]['sales'] = $sales;
                }
            }
        }

        $retries = $this->relay->counts();
        // この 1 時間ほどの返金の山（おかしな動きの知らせ。ADR 0103）。件数と合計の円だけ
        $refunds = Anomalies::refundPeak($now);
        $digest = [
            'status' => $this->access->status()->value,
            'stray_payments' => StrayPayment::query()->where('status', 'pending')->count(),
            'refund_issues' => ShopHome::refundIssues($now)->count(),
            'stuck_mails' => SentMail::query()->where('status', 'sending')->where('created_at', '<', $now->subMinutes(self::STUCK_MAIL_MINUTES)->utc())->count(),
            // 流し損ねた知らせ（ADR 0089）: 届け直しを待つ記録と、何度も落ちて止めた記録の数だけ
            'event_waiting' => $retries['waiting'],
            'event_stopped' => $retries['stopped'],
            'refund_peak_count' => $refunds['count'],
            'refund_peak_amount' => $refunds['amount'],
            'media_bytes' => MediaQuota::used(),
            'collected_at' => $now->utc(),
            'failed_at' => null,
        ];

        // ---- 記録の連鎖の先頭の控え（ADR 0012）----
        // 連鎖の検証は「つながっているか」しか見ないので、末尾を切り落とされても残りは無傷に見える。先頭（最後のイベントの番号と
        // hash）を中央に控え、次の回で「前に控えた番号のイベントが、同じ hash で今もあるか」を見る。
        // **全件の検証（1 日 1 回）の時だけでなく、10 分ごとの回でも毎回やる**（どちらも 1 行読むだけ）。検証の時だけだと、
        // 前の検証より後に積まれた記録（最大 24 時間ぶん。跡を消したい人がいちばん消したい所）を切り落とされても気づけない
        $remembered = ShopDigest::query()->find($tenantId);
        $known = [$remembered?->chain_head_sequence, $remembered?->chain_head_hash];
        $rolledBack = false;

        if ($remembered?->chain_head_hash !== null && $remembered->chain_head_sequence !== null) {
            $hashNow = $this->events->hashAt($remembered->chain_head_sequence);
            $rolledBack = $hashNow === null || ! hash_equals($remembered->chain_head_hash, $hashNow);
        }

        $verified = [];
        $intact = $remembered?->chain_intact;

        if ($verify) {
            $verification = $this->events->verify();
            $intact = $verification->intact();
            $verified = ['chain_intact' => $intact, 'chain_checked_at' => $now->utc()];
            $head = [$verification->headSequence, $verification->headHash];
        } else {
            $head = $this->events->head();
        }

        $remember = ['chain_rolled_back' => $rolledBack];

        // 巻き戻しを見つけた時と連鎖が切れている時は、控えを進めない。進めると次の回で「無傷」に戻り、
        // 知らせがひとりでに消える（切り落とした側の思うつぼ）。戻した後に控え直すのは operations:accept-chain-head
        if (! $rolledBack && $intact !== false) {
            $remember += ['chain_head_sequence' => $head[0], 'chain_head_hash' => $head[1]];
        }

        $rows = [];

        foreach ($days as $day => $counts) {
            $rows[] = ['tenant_id' => $tenantId, 'day' => $day] + $counts + ['collected_at' => $now->utc()];
        }

        // 中央の 2 つの表を 1 つのトランザクションで置き換える（読む側が半端な数を見ない）。
        // 同じ店の集め直しが同時に走ることがある（10 分ごとの回と夜の記録の検証、手で流す --rebuild）。店ごとの鍵で順番にし、
        // 自分より新しい時刻の数がもう書かれていれば、古い数で上書きしない（記録の検証の結果だけは新しければ残す）
        $collectedAt = $now->utc();
        // 悪い知らせ（巻き戻し・連鎖の切れ）は、時刻の前後に関係なく必ず書く（同じ晩に別の回が先に書いていても捨てない）
        $bad = $rolledBack || $intact === false;

        return DB::connection(self::central())->transaction(static function () use ($tenantId, $from, $rows, $digest, $collectedAt, $verified, $remember, $known, $bad): bool {
            DB::connection(self::central())->select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::lockName($tenantId)]);
            $current = ShopDigest::query()->find($tenantId);
            // 控えを読んでからここまでの間に、ほかの回が控えを進めた・忘れた（デモの店の戻し・accept-chain-head）なら、
            // 古い読みで決めた控えと巻き戻しの判定は書かない（次の回が今の控えで見直す）
            $chain = $verified + ([$current?->chain_head_sequence, $current?->chain_head_hash] === $known ? $remember : []);

            if ($current?->collected_at !== null && $current->collected_at->greaterThan($collectedAt)) {
                if ($chain !== [] && ($bad || $current->chain_checked_at === null || $current->chain_checked_at->lessThan($collectedAt))) {
                    ShopDigest::query()->whereKey($tenantId)->update($chain);
                }

                return false;
            }

            ShopDailySale::query()->where('tenant_id', $tenantId)
                ->when($from !== null, static fn (Builder $query) => $query->where('day', '>=', $from?->toDateString()))
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                ShopDailySale::query()->insert($chunk);
            }

            $write = $digest + $chain;
            ShopDigest::query()->upsert([['tenant_id' => $tenantId] + $write], ['tenant_id'], array_keys($write));

            return true;
        });
    }

    private static function lockName(string $tenantId): string
    {
        return 'shop_digest:'.$tenantId;
    }

    /**
     * 店を集められなかった（店の DB が無い・マイグレーションが足りない）。中央だけに印を付け、前の数は残す。店の文脈の外でも呼べる
     */
    public static function recordFailure(string $tenantId, ?CarbonImmutable $now = null): void
    {
        ShopDigest::query()->upsert([['tenant_id' => $tenantId, 'failed_at' => ($now ?? CarbonImmutable::now())->utc()]], ['tenant_id'], ['failed_at']);
    }

    /**
     * 日と月の区切りの時刻（ホームの数と同じ。日本の時刻）
     */
    public static function timezone(): string
    {
        $timezone = (string) config('app.display_timezone', 'Asia/Tokyo');

        // SQL に渡すので、時刻の名前として正しいものだけ（設定が壊れていても日本の時刻で数える）
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Tokyo';
    }

    public static function central(): string
    {
        return (string) config('tenancy.database.central_connection');
    }

    /**
     * 日ごとの件数と金額（日は $timezone の日付）。$test でテストのモードで払われた注文だけ、でなければそれ以外
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private static function byDay(bool $test, ?CarbonImmutable $from, string $timezone): array
    {
        $query = ShopHome::counted()
            ->when($from !== null, static fn (Builder $query) => $query->where('created_at', '>=', $from?->utc()));
        $test ? $query->whereExists(ShopHome::paidInTestMode(...)) : $query->whereNotExists(ShopHome::paidInTestMode(...));

        return $query->toBase()
            ->selectRaw("to_char(created_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day, count(*) AS n, coalesce(sum(total_amount - ".ShopHome::REFUNDED_SQL.'), 0) AS amount', [$timezone])
            ->groupByRaw('1')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->day => [(int) $row->n, (int) $row->amount]])
            ->all();
    }
}
