<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder;

use App\Core\Plugin\Models\PluginState;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginCartReminder\Jobs\SendCartReminder;
use EcSugar\PluginCartReminder\Models\CartReminder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 毎時の処理（cart-reminders:run）の中身。店の文脈の中で、この App を使う店だけで呼ばれる（ADR 0067）。何度流しても同じ結果。
 *
 * 1. 送っている途中で止まった記録（1 時間より前から sending）を「送れなかった」にする（送れたかが分からないので、2 通にしないよう送り直さない）
 * 2. 払われずに消えたショップの注文で、消えてから店の決めた時間（既定 3 時間）がたったものに、注文ごとに 1 行を作り、送る仕事をキューに積む。
 *    1 行は注文ごとの一意の制約で守る（同時に回っても、行を作れた方だけが積む）。送るか・送らないかは送る仕事が決める（SendCartReminder）
 * 3. キューから消えた（積んだまま 30 分たった）記録を積み直す
 * 4. リンクから戻った人の、そこから払われた注文を探して記録する（数のため）
 *
 * 対象は、ショップで確定した物販の注文（支払いの画面で払う注文）で、1 円以上・払われていない（払われた支払いが無い）・コンビニ払いの入金待ちにならなかった
 * （入金待ちの期限切れには核の「お支払いの期限が過ぎた」の知らせがある）もの。消えてから待つ時間＋ 24 時間より前のものと、この App を使い始める前に消えたものは送らない
 */
final class CartReminders
{
    /** リンクの期限（日） */
    public const int LINK_DAYS = 7;

    /** 同じメールへ、この日数のうちに送っていれば送らない */
    public const int RECENT_DAYS = 7;

    /** 待つ時間を過ぎてから拾う幅（時間）。毎時の処理が止まっていても、この幅のうちなら後から拾う */
    public const int LOOKBACK_HOURS = 24;

    /** 1 回に 1 店で積む数 */
    public const int BATCH = 200;

    /** 送っている途中で止まったと見るまで（分） */
    public const int STALE_SENDING_MINUTES = 60;

    /** キューから消えたと見るまで（分） */
    public const int LOST_QUEUED_MINUTES = 30;

    /** 管理画面の数を数える日数 */
    public const int STATS_DAYS = 30;

    /** 設定の「待つ時間」の値と時間 */
    public const array WAIT = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h6' => 6, 'h12' => 12, 'h24' => 24, 'h48' => 48, 'h72' => 72];

    public const int DEFAULT_WAIT = 3;

    /**
     * @return array{queued: int, stale: int, requeued: int, recovered: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $stale = CartReminder::query()->where('status', CartReminder::SENDING)->where('updated_at', '<', $now->subMinutes(self::STALE_SENDING_MINUTES))
            ->update(['status' => CartReminder::FAILED, 'token_hash' => null, 'unsubscribe_hash' => null, 'expires_at' => null, 'updated_at' => $now]);

        $queued = 0;

        foreach ($this->candidates($now) as $transactionId) {
            $id = strtolower((string) Str::ulid());
            // 注文ごとの一意の制約: 同時に回っている別の処理が先に作っていれば 0 行（積まない）
            $inserted = DB::table('cart_reminders')->insertOrIgnore([
                'id' => $id, 'transaction_id' => $transactionId, 'status' => CartReminder::QUEUED, 'created_at' => $now, 'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                SendCartReminder::dispatch($id);
                $queued++;
            }
        }

        $requeued = 0;

        foreach (CartReminder::query()->where('status', CartReminder::QUEUED)->where('updated_at', '<', $now->subMinutes(self::LOST_QUEUED_MINUTES))->limit(self::BATCH)->pluck('id') as $id) {
            // 積み直した時刻を付けて、次の回にまた積まない（送る仕事は queued の行だけを 1 文の条件つきの更新で取るので、2 つ積んでも 1 通）
            if (CartReminder::query()->whereKey($id)->where('status', CartReminder::QUEUED)->update(['updated_at' => $now]) === 1) {
                SendCartReminder::dispatch((string) $id);
                $requeued++;
            }
        }

        return ['queued' => $queued, 'stale' => $stale, 'requeued' => $requeued, 'recovered' => $this->recover($now)];
    }

    /**
     * 店の設定の「待つ時間」（時間）
     */
    public static function waitHours(): int
    {
        $value = app(PluginSettings::class)->publicValues('cart-reminder')['wait'] ?? null;

        return is_string($value) && isset(self::WAIT[$value]) ? self::WAIT[$value] : self::DEFAULT_WAIT;
    }

    /**
     * 店の設定の「書き出しの一文」（空なら null）
     */
    public static function opening(): ?string
    {
        $value = app(PluginSettings::class)->publicValues('cart-reminder')['opening'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * お知らせを送る候補の注文（まだ記録の無いもの。消えた順）
     *
     * @return list<string>
     */
    public function candidates(CarbonImmutable $now): array
    {
        $until = $now->subHours(self::waitHours());
        $from = $until->subHours(self::LOOKBACK_HOURS);
        // この App を使い始める前に消えた注文には送らない（使い始めた時に、前の分をまとめて送らない）
        $since = PluginState::query()->where('plugin', 'cart-reminder')->where('enabled', true)->value('changed_at');

        if ($since instanceof \DateTimeInterface && $since > $from) {
            $from = CarbonImmutable::instance($since);
        }

        return DB::table('transactions as t')
            ->join('checkout_orders as c', 'c.transaction_id', '=', 't.id')
            ->where('c.pays_with', 'checkout')
            ->where('t.kind', 'sale')->where('t.status', 'cancelled')->where('t.total_amount', '>', 0)
            ->whereNotNull('t.customer_id')
            ->where('t.closed_at', '<=', $until)->where('t.closed_at', '>', $from)
            ->whereNotExists(static fn (Builder $q) => $q->from('cart_reminders as r')->whereColumn('r.transaction_id', 't.id'))
            // 払われた支払いがある（取消は返金つき）・コンビニ払いの入金待ちになった（期限切れの知らせが別にある）注文は送らない
            ->whereNotExists(static fn (Builder $q) => $q->from('payment_attempts as p')->whereColumn('p.transaction_id', 't.id')
                ->where(static fn (Builder $w) => $w->where('p.status', 'paid')->orWhereNotNull('p.pay_by')))
            ->orderBy('t.closed_at')->limit(self::BATCH)
            ->pluck('t.id')->map(static fn (mixed $id): string => (string) $id)->values()->all();
    }

    /**
     * 同じメールの、この注文より後の注文があるか（払われた・払う途中・入金待ち・発送の前後。払われずに消えたものは数えない）
     */
    public static function orderedLater(Transaction $order, string $emailIndex): bool
    {
        return DB::table('transactions as t')
            ->join('parties as p', 'p.id', '=', 't.customer_id')
            ->where('p.email_index', $emailIndex)
            ->where('t.kind', 'sale')->where('t.id', '!=', $order->id)
            ->where('t.created_at', '>', $order->getAttribute('created_at'))
            ->where(static fn (Builder $q) => $q->where('t.status', '!=', 'cancelled')
                ->orWhereExists(static fn (Builder $e) => $e->from('payment_attempts as a')->whereColumn('a.transaction_id', 't.id')->where('a.status', 'paid')))
            ->exists();
    }

    /**
     * 同じメールへ、ほかのお知らせを送っている・RECENT_DAYS 日のうちに送ったか
     */
    public static function sentRecently(string $reminderId, string $emailIndex, CarbonImmutable $now): bool
    {
        return DB::table('cart_reminders as r')
            ->join('transactions as t', 't.id', '=', 'r.transaction_id')
            ->join('parties as p', 'p.id', '=', 't.customer_id')
            ->where('p.email_index', $emailIndex)
            ->where('r.id', '!=', $reminderId)
            ->where(static fn (Builder $q) => $q->where('r.status', CartReminder::SENDING)
                ->orWhere(static fn (Builder $s) => $s->where('r.status', CartReminder::SENT)->where('r.sent_at', '>', $now->subDays(self::RECENT_DAYS))))
            ->exists();
    }

    /**
     * リンクから戻った人の、そこから払われた注文（同じメールで、戻った後のリンクの期限のうちに作られ、払われた注文）を記録する
     */
    private function recover(CarbonImmutable $now): int
    {
        $count = 0;
        $rows = CartReminder::query()->whereNotNull('returned_at')->whereNull('recovered_transaction_id')
            ->where('returned_at', '>', $now->subDays(self::LINK_DAYS * 2))->limit(self::BATCH)->get();

        foreach ($rows as $row) {
            $index = DB::table('transactions as t')->join('parties as p', 'p.id', '=', 't.customer_id')->where('t.id', $row->transaction_id)->value('p.email_index');

            if (! is_string($index) || $row->returned_at === null) {
                continue;
            }

            $paid = DB::table('transactions as t')
                ->join('parties as p', 'p.id', '=', 't.customer_id')
                ->where('p.email_index', $index)->where('t.kind', 'sale')
                ->where('t.created_at', '>=', $row->returned_at)->where('t.created_at', '<=', $row->returned_at->addDays(self::LINK_DAYS))
                ->whereExists(static fn (Builder $e) => $e->from('payment_attempts as a')->whereColumn('a.transaction_id', 't.id')->where('a.status', 'paid'))
                ->orderBy('t.created_at')->value('t.id');

            if (is_string($paid)) {
                $count += CartReminder::query()->whereKey($row->id)->whereNull('recovered_transaction_id')
                    ->update(['recovered_transaction_id' => $paid, 'recovered_at' => $now, 'updated_at' => $now]);
            }
        }

        return $count;
    }

    /**
     * リンクの合い言葉（32 バイトの乱数。URL に載る形で 43 文字）
     */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
