<?php

declare(strict_types=1);

namespace App\Core\Event\Outbox;

use App\Core\Event\Models\DomainEvent;
use App\Core\Trace\TraceId;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * 記録（domain_events）をTransactional Outboxとして、名前のある受け手へ届ける送り出し役（ADR 0089）。店ごとに、店の DB の中で。
 *
 * - 受け手ごとに「どこまで流したか」（event_receiver_positions）を持つ。コミットの後に同じプロセスで流し（EventStore が kick）、
 *   落ちて流し損ねた分は毎分の見回り（events:relay）が印から先を拾って流す。少なくとも 1 回届く（2 回届くことがある。受け手は冪等に書く）
 * - 1 つの店で同時に流すのは 1 本だけ（店の DB のセッションのアドバイザリロックを待たずに取る。取れなければ取った方に任せる）。
 *   流している間に積まれた記録は、取った方が鍵を放した後に確かめ直して拾う（取りこぼさない）
 * - 受け手が落ちたら: ログに残して EventReceiverFailed を出し（壊れたら知らせる仕組みが数える。記録の処理の ID の下で）、event_retries に移して間を空けて届け直す。
 *   同じ受け手・同じ集約の後ろの記録は、前の記録が届くまで待たせる（同じ集約の順を守る）。MAX_ATTEMPTS 回落ちたら止めて要対応に出し、後ろの記録は先へ進める（毒の記録で詰まらない）
 * - App に結びつく受け手は、その App を使っている店で、使うにした後に起きた記録だけ届ける（止めていた間の記録は、戻しても届けない）
 * - 届ける時は、元の記録の処理の ID の下で動かす（TraceId::within。ADR 0087）
 *
 * 位置と届け直しの行は状態ではなく、お金と在庫に触れない「流したか」の印（書き方の決まり 3 の例外）。連鎖のロックとイベントを使わない
 */
final class EventRelay
{
    /** これだけ落ちたら止める */
    public const int MAX_ATTEMPTS = 5;

    /** 初めて見る受け手は、この分より前の記録の最後から始める（入れ替えの間に流し損ねた分を拾う。前の記録を全部は流さない） */
    public const int LOOKBACK_MINUTES = 5;

    /** 見回り 1 回で 1 店に流す記録の数 */
    public const int SWEEP_LIMIT = 5000;

    /** コミットの後に同じプロセスで流す数（残りは見回り） */
    private const int KICK_LIMIT = 200;

    private const int BATCH = 200;

    /** n 回目に落ちた後に空ける分 */
    private const array BACKOFF_MINUTES = [1 => 1, 2 => 5, 3 => 30, 4 => 120];

    // pg_try_advisory_lock のキー（連鎖のロックとは別。店ごとに DB が別なので店ごとに閉じる）
    private const int RELAY_LOCK = 0x5E_C0_E8;

    private static bool $running = false;

    private static bool $again = false;

    private static int $paused = 0;

    /** @var array<string, int> */
    private static array $muted = [];

    public function __construct(private readonly EventReceivers $receivers) {}

    /**
     * コミットの後（EventStore）。落ちても書き込みの答えは変えない（報告だけ。見回りが拾う）
     */
    public function kick(): void
    {
        if (self::$paused > 0 || tenant() === null || DB::transactionLevel() > 0) {
            return;
        }

        // 受け手の中の書き込みのコミットから呼ばれた: 外側の回が拾う
        if (self::$running) {
            self::$again = true;

            return;
        }

        try {
            for ($round = 0; $round < 3; $round++) {
                if ($this->run(self::KICK_LIMIT) === null || ! $this->behind()) {
                    return;
                }
            }
        } catch (Throwable $error) {
            report($error);
        }
    }

    /**
     * 今の店の記録を流す（見回り・流し直しの操作・kick）。ほかの処理が流していれば何もせず null
     *
     * @return array{delivered: int, failed: int, stopped: int, held: int, skipped: int}|null
     */
    public function run(int $limit = self::SWEEP_LIMIT, ?CarbonImmutable $now = null): ?array
    {
        if (tenant() === null) {
            throw new LogicException('記録は店の文脈の中で流す');
        }

        if (DB::transactionLevel() > 0) {
            throw new LogicException('記録はトランザクションの外で流す（受け手のコミットの後の処理が動くように）');
        }

        if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(?) AS ok', [self::RELAY_LOCK])->ok) {
            return null;
        }

        self::$running = true;
        $stats = ['delivered' => 0, 'failed' => 0, 'stopped' => 0, 'held' => 0, 'skipped' => 0];

        try {
            $now ??= CarbonImmutable::now();
            $states = $this->receivers->appStates();
            $this->retryWaiting($now, $states, $stats);
            $done = 0;

            self::$again = false;

            do {
                $scanned = $this->forward($now, $states, $stats);
                $done += $scanned;
            } while (($scanned >= self::BATCH || self::againRequested()) && $done < $limit);
        } finally {
            self::$running = false;
            DB::select('SELECT pg_advisory_unlock(?)', [self::RELAY_LOCK]);
        }

        return $stats;
    }

    /**
     * 止めた記録を待ちに戻し、待っている記録もすぐ届け直す対象にする（運営の「流し直す」）。戻した数
     */
    public function resume(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        DB::table('event_retries')->where('status', 'waiting')->update(['next_at' => $now, 'updated_at' => $now]);

        return DB::table('event_retries')->where('status', 'stopped')->update(['status' => 'waiting', 'attempts' => 0, 'next_at' => $now, 'updated_at' => $now]);
    }

    /**
     * 届け直しを待つ記録と、止めた記録の数（運営の様子）
     *
     * @return array{waiting: int, stopped: int}
     */
    public function counts(): array
    {
        $rows = DB::table('event_retries')->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status');

        return ['waiting' => (int) ($rows['waiting'] ?? 0), 'stopped' => (int) ($rows['stopped'] ?? 0)];
    }

    /**
     * テストだけ: 中の処理の間、コミットの後に流さない（コミットの直後にプロセスが落ちた形）
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function pausing(Closure $work): mixed
    {
        self::$paused++;

        try {
            return $work();
        } finally {
            self::$paused--;
        }
    }

    /**
     * テストだけ: 中の処理の間、名前の受け手を呼ばずに「流した」にする（受け手が何もしなかった形）
     *
     * @template T
     *
     * @param  list<string>  $names
     * @param  Closure(): T  $work
     * @return T
     */
    public static function muting(array $names, Closure $work): mixed
    {
        foreach ($names as $name) {
            self::$muted[$name] = (self::$muted[$name] ?? 0) + 1;
        }

        try {
            return $work();
        } finally {
            foreach ($names as $name) {
                if (--self::$muted[$name] <= 0) {
                    unset(self::$muted[$name]);
                }
            }
        }
    }

    /**
     * 流している間に、受け手の中の書き込みのコミットから流してほしいと頼まれたか（読んだら消す）
     */
    private static function againRequested(): bool
    {
        $again = self::$again;
        self::$again = false;

        return $again;
    }

    /**
     * 印より先に記録が残っているか
     */
    private function behind(): bool
    {
        $names = array_keys($this->receivers->all());

        if ($names === []) {
            return false;
        }

        $positions = DB::table('event_receiver_positions')->whereIn('receiver', $names)->pluck('position', 'receiver');
        $max = (int) DomainEvent::query()->max('sequence');

        return count($positions) < count($names) || (int) $positions->min() < $max;
    }

    /**
     * 届け直しを待つ記録を、記録の順に届ける。同じ受け手・同じ集約で前の記録が待っていれば、後ろは待たせる
     *
     * @param  array<string, array{enabled: bool, changed_at: CarbonImmutable|null}>  $states
     * @param  array<string, int>  $stats
     */
    private function retryWaiting(CarbonImmutable $now, array $states, array &$stats): void
    {
        $rows = DB::table('event_retries')->where('status', 'waiting')->orderBy('sequence')->limit(1000)->get();

        if ($rows->isEmpty()) {
            return;
        }

        $events = DomainEvent::query()->whereIn('sequence', $rows->pluck('sequence')->all())->get()->keyBy('sequence');
        $blocked = [];

        foreach ($rows as $row) {
            $key = $row->receiver.'|'.$row->aggregate_type.'|'.$row->aggregate_id;

            if (isset($blocked[$key])) {
                continue;
            }

            $receiver = $this->receivers->get((string) $row->receiver);
            $event = $events->get((int) $row->sequence);

            // 受け手が無くなった（App を外した）・App を止めた・止めた後に戻した: 届けない
            if (! $receiver instanceof EventReceiver || ! $event instanceof DomainEvent || isset(self::$muted[$receiver->name])
                || ! $this->receivers->allows($receiver->app, $event->occurred_at, $states)) {
                DB::table('event_retries')->where('id', $row->id)->delete();
                $stats['skipped']++;

                continue;
            }

            if ($now->lessThan(CarbonImmutable::parse((string) $row->next_at))) {
                $blocked[$key] = true;

                continue;
            }

            $error = $this->attempt($receiver, $event);

            if ($error === null) {
                DB::table('event_retries')->where('id', $row->id)->delete();
                $stats['delivered']++;

                continue;
            }

            $attempts = (int) $row->attempts + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                DB::table('event_retries')->where('id', $row->id)->update(['status' => 'stopped', 'attempts' => $attempts, 'error' => self::errorName($error), 'updated_at' => $now]);
                $stopped = new EventDeliveryStopped($receiver->name, $event->sequence, self::errorName($error));
                Log::error('記録の受け手が何度も落ちたので止めた（直してから再実行する）', ['receiver' => $receiver->name, 'sequence' => $event->sequence, 'error' => $error::class]);
                TraceId::within($event->trace_id ?? TraceId::current() ?? '', static fn () => event(new EventReceiverFailed($receiver->name, $event->sequence, $stopped, stopped: true)));
                $stats['stopped']++;

                continue;
            }

            DB::table('event_retries')->where('id', $row->id)->update([
                'attempts' => $attempts, 'error' => self::errorName($error), 'next_at' => self::nextAt($now, $attempts), 'updated_at' => $now,
            ]);
            $blocked[$key] = true;
            $stats['failed']++;
        }
    }

    /**
     * 印から先の記録を 1 束流す。見た記録の数
     *
     * @param  array<string, array{enabled: bool, changed_at: CarbonImmutable|null}>  $states
     * @param  array<string, int>  $stats
     */
    private function forward(CarbonImmutable $now, array $states, array &$stats): int
    {
        $receivers = $this->receivers->all();

        if ($receivers === []) {
            return 0;
        }

        $positions = $this->positions($now, array_keys($receivers));
        $from = min($positions);
        $upTo = (int) DomainEvent::query()->max('sequence');

        if ($upTo <= $from) {
            return 0;
        }

        $types = array_values(array_unique(array_merge(...array_map(static fn (EventReceiver $r): array => $r->types, array_values($receivers)))));
        $events = DomainEvent::query()->where('sequence', '>', $from)->where('sequence', '<=', $upTo)->whereIn('type', $types)
            ->orderBy('sequence')->limit(self::BATCH)->get();
        $waiting = [];

        foreach (DB::table('event_retries')->where('status', 'waiting')->get(['receiver', 'aggregate_type', 'aggregate_id']) as $row) {
            $waiting[$row->receiver.'|'.$row->aggregate_type.'|'.$row->aggregate_id] = true;
        }

        foreach ($events as $event) {
            foreach ($receivers as $receiver) {
                if ($positions[$receiver->name] >= $event->sequence || ! $receiver->accepts($event)) {
                    continue;
                }

                $key = $receiver->name.'|'.$event->aggregate_type.'|'.$event->aggregate_id;

                if (isset(self::$muted[$receiver->name]) || ! $this->receivers->allows($receiver->app, $event->occurred_at, $states)) {
                    $stats['skipped']++;
                } elseif (isset($waiting[$key])) {
                    // 同じ集約の前の記録が届け直しを待っている: 順を守るため、後ろに並べる
                    $this->park($receiver, $event, 0, null, $now, $now);
                    $stats['held']++;
                } else {
                    $error = $this->attempt($receiver, $event);

                    if ($error === null) {
                        $stats['delivered']++;
                    } else {
                        $this->park($receiver, $event, 1, self::errorName($error), self::nextAt($now, 1), $now);
                        $waiting[$key] = true;
                        $stats['failed']++;
                    }
                }

                $this->advance([$receiver->name], $event->sequence, $now);
                $positions[$receiver->name] = $event->sequence;
            }
        }

        // 束を見終えたら、どの受け手も見た所まで進める（受け取る種類の無い記録は飛ばしてよい）
        $last = $events->count() < self::BATCH ? $upTo : (int) $events->last()?->sequence;
        $this->advance(array_keys($receivers), $last, $now);

        return $events->count();
    }

    /**
     * 受け手ごとの印。初めて見る受け手は、LOOKBACK_MINUTES より前の記録の最後から始める
     *
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function positions(CarbonImmutable $now, array $names): array
    {
        $positions = DB::table('event_receiver_positions')->whereIn('receiver', $names)->pluck('position', 'receiver')->map(static fn (mixed $p): int => (int) $p)->all();
        $missing = array_values(array_diff($names, array_keys($positions)));

        if ($missing !== []) {
            $start = (int) DomainEvent::query()->where('occurred_at', '<=', $now->subMinutes(self::LOOKBACK_MINUTES))->max('sequence');
            DB::table('event_receiver_positions')->insertOrIgnore(array_map(static fn (string $name): array => ['receiver' => $name, 'position' => $start, 'updated_at' => $now], $missing));
            $positions = DB::table('event_receiver_positions')->whereIn('receiver', $names)->pluck('position', 'receiver')->map(static fn (mixed $p): int => (int) $p)->all();
        }

        return $positions;
    }

    /**
     * @param  list<string>  $names
     */
    private function advance(array $names, int $sequence, CarbonImmutable $now): void
    {
        DB::table('event_receiver_positions')->whereIn('receiver', $names)->where('position', '<', $sequence)->update(['position' => $sequence, 'updated_at' => $now]);
    }

    private function park(EventReceiver $receiver, DomainEvent $event, int $attempts, ?string $error, CarbonImmutable $nextAt, CarbonImmutable $now): void
    {
        DB::table('event_retries')->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'receiver' => $receiver->name,
            'sequence' => $event->sequence,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => $event->aggregate_id,
            'status' => 'waiting',
            'attempts' => $attempts,
            'error' => $error,
            'next_at' => $nextAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 受け手を 1 回呼ぶ。落ちたら報告して例外を返す
     */
    private function attempt(EventReceiver $receiver, DomainEvent $event): ?Throwable
    {
        return TraceId::within($event->trace_id ?? TraceId::current() ?? '', static function () use ($receiver, $event): ?Throwable {
            try {
                ($receiver->handler)($event);

                return null;
            } catch (Throwable $error) {
                // 受け手が開いたまま落としたトランザクションを閉じる（次の受け手と印の書き込みを巻き込まない）
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                // ログには例外ごと（場所と流れ）。壊れたら知らせる仕組みへは、要求の中でも外でも 1 回だけ数える（report は要求の中しか数えず、要求の中では 2 回になる）
                Log::error('記録の受け手が落ちた（間を空けて届け直す）', ['receiver' => $receiver->name, 'sequence' => $event->sequence, 'exception' => $error]);
                event(new EventReceiverFailed($receiver->name, $event->sequence, $error));

                return $error;
            }
        });
    }

    private static function nextAt(CarbonImmutable $now, int $attempts): CarbonImmutable
    {
        return $now->addMinutes(self::BACKOFF_MINUTES[$attempts] ?? 120);
    }

    private static function errorName(Throwable $error): string
    {
        return substr($error::class, 0, 255);
    }
}
