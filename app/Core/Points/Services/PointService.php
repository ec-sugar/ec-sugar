<?php

declare(strict_types=1);

namespace App\Core\Points\Services;

use App\Core\Event\EventStore;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Points\Models\OrderPoints;
use App\Core\Points\Models\PointEntry;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * ポイント（ADR 0068）。会員だけが貯めて使える。1 ポイント = 1 円。帳簿（point_entries）に書くのはここだけ。
 *
 * - 使う（recordOrder）: 注文を作るトランザクションの中、台帳のロックの後で、会員の台帳の行を錠で取り、残高を確かめてから spend を書く
 * - 戻す（release）: 払われずに消えた注文・払った後の取消。期限は戻した時から 1 年
 * - 付ける（earn）: 注文の受渡が全部発送された時。数は注文の時に決めた「付く予定」（注文の時の率で切り捨て）
 * - 取り消す（refunded）: 全額の返金。付けた分を引き（残高まで。足りない分は shortfall に残して負にしない）、使った分を戻す
 * - 割合で取り消す・戻す（refundedPart）: 一部の返金（ADR 0048）。返した額 ÷ 受け取った額の割合まで、付けた分を取り消し、使った分を戻す（cause = partial）
 * - 消す（expireDue・forfeit）: 最後に動いて（付く・使う・戻す・手で足し引き）から 1 年を過ぎた残高と、退会した会員の残高
 * - 手で足す・引く（adjust）: 店の人が理由を付けて。理由は暗号化して帳簿に、イベントには数だけ
 * - App が付ける（grant）: 決まった理由（cause。友だち紹介の referral）で足す。種類は adjust。ADR 0075
 *
 * どの書き込みも EventStore::lock() を先に取り、同じトランザクションでイベントを残す（payload に個人情報を入れない。お客さんは台帳の ID）。
 * 残高が負にならないことは DB のトリガーも守る（アプリの確かめをすり抜けても断る）
 */
final class PointService
{
    public const string NOT_MEMBER = 'ポイントは会員の方だけがお使いいただけます。';

    public const string DISABLED = '現在ポイントはお使いいただけません。';

    /** 手で足し引きできる 1 回の数の上限 */
    public const int MAX_ADJUST = 1_000_000;

    public const int REASON_MAX = 200;

    /** App が grant で付けてよい決まった理由（帳簿の cause。DB の CHECK も同じ一覧） */
    public const array GRANT_CAUSES = ['referral'];

    /** 期限を延ばす動き（付く・使う・戻す・手で足し引き）。取り消し・消えたは延ばさない */
    private const array MOVES = ['earn', 'spend', 'release', 'adjust'];

    private const array EVENT = ['earn' => 'earned', 'spend' => 'spent', 'release' => 'released', 'revoke' => 'revoked', 'expire' => 'expired', 'adjust' => 'adjusted'];

    public function __construct(
        private readonly EventStore $events,
        private readonly PointSettings $settings,
    ) {}

    /**
     * 付くポイント（対象の額 × 率の切り捨て。率は万分率）
     */
    public static function earnFor(int $base, int $rateBp): int
    {
        return $base <= 0 || $rateBp <= 0 ? 0 : intdiv($base * $rateBp, 10000);
    }

    /**
     * 動いた時からの期限（1 年）
     */
    public static function expiryFrom(CarbonImmutable $at): CarbonImmutable
    {
        return $at->addYear();
    }

    /**
     * ポイントを貯めて使える会員か（会員で、止められていない・まとめられていない台帳）
     */
    public static function isMember(CustomerAccount $account): bool
    {
        return $account->member_since !== null && $account->disabled_at === null && $account->merged_into_id === null;
    }

    public function balance(string $accountId): int
    {
        return (int) ($this->latest($accountId)->balance_after ?? 0);
    }

    /**
     * 残っているポイントの期限（残高が 0 なら null）
     */
    public function expiresAt(string $accountId): ?CarbonImmutable
    {
        $latest = $this->latest($accountId);

        return $latest === null || $latest->balance_after <= 0 ? null : $latest->expires_at;
    }

    /**
     * 払われて発送を待つ注文の、付く予定のポイント（注文ごと。新しい順）
     *
     * @return list<array{transactionId: string, points: int, placedAt: CarbonImmutable}>
     */
    public function pendingOrders(string $accountId): array
    {
        return OrderPoints::query()
            ->where('customer_account_id', $accountId)
            ->where('earn', '>', 0)
            ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from('point_entries')->whereColumn('point_entries.transaction_id', 'order_points.transaction_id')
                ->where(static fn ($w) => $w->whereIn('point_entries.kind', ['earn', 'revoke'])->orWhere(static fn ($r) => $r->where('point_entries.kind', 'release')->whereNull('point_entries.cause'))))
            ->whereIn('transaction_id', Transaction::query()->select('id')->where('status', TransactionStatus::Open)->whereIn('phase', [Phase::Payment, Phase::Fulfillment]))
            ->orderByDesc('created_at')->limit(50)->get()
            ->map(static fn (OrderPoints $o): array => ['transactionId' => $o->transaction_id, 'points' => $o->earn, 'placedAt' => $o->created_at])->values()->all();
    }

    /**
     * 帳簿の行（新しい順）
     *
     * @return Collection<int, PointEntry>
     */
    public function history(string $accountId, int $limit = 50): Collection
    {
        return PointEntry::query()->where('customer_account_id', $accountId)->orderByDesc('account_seq')->limit($limit)->get();
    }

    /**
     * 注文のポイントの様子（使った・付く予定・付いた）。ポイントの決まりの無い注文は null。
     * state: pending（付く予定）・earned（付いた）・revoked（付けた分を返金で取り消した）・cancelled（取消・返金で終わった注文。付かない）・
     * none（付かない注文。付く予定が 0・退会などで会員でなくなった台帳）。「付く予定です」を出すのは pending の時だけ
     *
     * @return array{spent: int, earn: int, earned: int, state: string}|null
     */
    public function forOrder(string $transactionId): ?array
    {
        $order = OrderPoints::query()->find($transactionId);

        if ($order === null) {
            return null;
        }

        $entries = PointEntry::query()->where('transaction_id', $transactionId)->get(['kind', 'points', 'cause']);
        $earned = (int) ($entries->firstWhere('kind', 'earn')->points ?? 0);
        // 全額の返金の取り消しだけ（一部の返金の割合の取り消し〔partial〕は、付いたまま）
        $revoked = $entries->contains(static fn (PointEntry $e): bool => $e->kind === 'revoke' && $e->cause === 'refund');
        $released = $entries->contains(static fn (PointEntry $e): bool => $e->kind === 'release' && $e->cause === null);
        $closed = Transaction::query()->whereKey($transactionId)->first()?->status->isClosed() ?? true;
        $account = CustomerAccount::query()->find($order->customer_account_id);
        $state = match (true) {
            $revoked && $earned > 0 => 'revoked',
            $earned > 0 => 'earned',
            $revoked || $released || $closed => 'cancelled',
            $order->earn === 0, $account === null, ! self::isMember($account) => 'none',
            default => 'pending',
        };

        return ['spent' => $order->spent, 'earn' => $order->earn, 'earned' => $earned, 'state' => $state];
    }

    /**
     * まだ使われていないポイントの合計（店の負債。1 ポイント = 1 円）
     */
    public function outstanding(): int
    {
        return (int) DB::table(DB::raw('(SELECT DISTINCT ON (customer_account_id) balance_after FROM point_entries ORDER BY customer_account_id, account_seq DESC) AS latest'))->sum('balance_after');
    }

    /**
     * 残高のある会員の数
     */
    public function holders(): int
    {
        return DB::table(DB::raw('(SELECT DISTINCT ON (customer_account_id) balance_after FROM point_entries ORDER BY customer_account_id, account_seq DESC) AS latest'))->where('balance_after', '>', 0)->count();
    }

    /**
     * 注文のポイントを記録する（注文を作るトランザクションの中、台帳のロックの後で呼ぶ）。
     * 会員の台帳の行を錠で取り、会員か・使う店か・残高が足りるかをその錠の中で確かめてから、使った分（spend）を引く。
     * 付く予定は「対象の額 × 今の店の率」の切り捨てで、率と一緒に注文に写す（後で率を変えても、この注文は変わらない）。
     * 使わず付かない注文（使わない店・率 0・対象 0 円）は何も書かない
     *
     * @param  int  $earnBase  付ける額の元（商品の代金。値引きと使ったポイントの後、税込み、送料を除く）
     * @param  array<int|string, int>  $allocations  使ったポイントの明細の位置ごとの割り振り
     * @return int 付く予定のポイント
     */
    public function recordOrder(string $transactionId, string $accountId, int $spend, int $earnBase, array $allocations, ?string $actor): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('注文のポイントは注文を作るトランザクションの中で');
        }

        if ($spend < 0 || $earnBase < 0 || array_sum($allocations) !== $spend) {
            throw new LogicException('使うポイントと割り振りが合わない');
        }

        $this->events->lock();
        $account = CustomerAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();

        if (! self::isMember($account)) {
            if ($spend > 0) {
                throw new PointException(self::NOT_MEMBER);
            }

            return 0;
        }

        // 率は店の率に、その会員の特典の上乗せ（ADR 0079）を足したもの。カートの見せ方（CartService::pointsFor）と同じ決め方で、錠の中で読み直す
        $rate = $this->settings->rateBpFor($account->id);

        if ($spend > 0 && ! $this->settings->enabled()) {
            throw new PointException(self::DISABLED);
        }

        $balance = $this->balance($account->id);

        if ($spend > $balance) {
            throw new PointException('お持ちのポイント（'.number_format($balance).' ポイント）が、お使いになるポイントより少なくなりました。');
        }

        $earn = self::earnFor($earnBase, $rate);

        if ($spend === 0 && $earn === 0) {
            return 0;
        }

        OrderPoints::create(['transaction_id' => $transactionId, 'customer_account_id' => $account->id, 'rate_bp' => $rate, 'spent' => $spend, 'earn' => $earn, 'earn_base' => $earnBase, 'allocations' => $allocations]);

        if ($spend > 0) {
            $this->append($account->id, 'spend', -$spend, $transactionId, $actor);
        }

        return $earn;
    }

    /**
     * 発送で付ける（注文の受渡が全部発送された時。呼ぶ側の DB トランザクションの中でもよい）。
     * 付く予定の無い注文・もう付けた・取り消した（取消・返金）注文・会員でなくなった台帳には付けない
     *
     * @return int 付けたポイント
     */
    public function earn(string $transactionId, ?string $actor): int
    {
        return DB::transaction(function () use ($transactionId, $actor): int {
            $this->events->lock();
            $order = OrderPoints::query()->find($transactionId);

            if ($order === null || $order->earn === 0) {
                return 0;
            }

            // もう付けた・取り消した・取消で戻した（送料の差額の戻し〔cause = shipping〕は数えない）・取消と返金で終わった注文には付けない
            if (self::settledOrder($transactionId) || Transaction::query()->whereKey($transactionId)->first()?->status->isClosed() !== false) {
                return 0;
            }

            $account = CustomerAccount::query()->whereKey($order->customer_account_id)->lockForUpdate()->firstOrFail();

            if (! self::isMember($account)) {
                return 0;
            }

            $this->append($account->id, 'earn', $order->earn, $transactionId, $actor);

            return $order->earn;
        });
    }

    /**
     * 使った分を戻す（払われずに消えた注文・払った後の取消。呼ぶ側の DB トランザクションの中でもよい）。期限は戻した時から 1 年。
     * 会員でなくなった台帳に戻ったポイントは、すぐに消す（退会した人は使えない）。もう戻した・使っていない注文は何もしない
     *
     * @return int 戻したポイント
     */
    public function release(string $transactionId, ?string $actor): int
    {
        return DB::transaction(function () use ($transactionId, $actor): int {
            $this->events->lock();

            return $this->releaseLocked($transactionId, $actor);
        });
    }

    /**
     * 贈り物の送料の差額のうち、現金で返せなかった分をポイントで戻す（ADR 0068 の作って決めたこと。呼ぶ側の DB トランザクションの中でもよい）。
     * 使ったポイントの数までで、注文に 1 回だけ（release、cause = shipping。一意の索引も守る）。期限は戻した時から 1 年。取消で全部戻した注文には戻さない
     *
     * @param  int  $amount  現金で返せなかった差額（円 = ポイント）
     * @return int 戻したポイント
     */
    public function creditShipping(string $transactionId, int $amount, ?string $actor): int
    {
        if ($amount < 1) {
            return 0;
        }

        return DB::transaction(function () use ($transactionId, $amount, $actor): int {
            $this->events->lock();
            $order = OrderPoints::query()->find($transactionId);

            // もう送料の差額で戻した・取消で全部戻した注文には戻さない。一部の返金の戻し（partial）は数えない（cause で分ける）
            if ($order === null || $order->spent === 0 || PointEntry::query()->where('transaction_id', $transactionId)->where('kind', 'release')
                ->where(static fn ($q) => $q->whereNull('cause')->orWhere('cause', 'shipping'))->exists()) {
                return 0;
            }

            $account = CustomerAccount::query()->whereKey($order->customer_account_id)->lockForUpdate()->firstOrFail();
            // 使った数のうち、まだ戻していない分まで（一部の返金で戻した分を引く）
            $back = min($amount, $order->spent - (int) PointEntry::query()->where('transaction_id', $transactionId)->where('kind', 'release')->sum('points'));

            if ($back < 1) {
                return 0;
            }

            $this->append($account->id, 'release', $back, $transactionId, $actor, cause: 'shipping');
            $this->forfeitIfLeft($account, $actor);

            return $back;
        });
    }

    /**
     * 全額の返金の後（ADR 0068 の 4。呼ぶのは返金の目的が外でした返金〔external〕で、注文が閉じた・もう発送するものが無い時だけ）:
     * 使った分を戻し、付けた分を取り消す。付けた分より残高が少なければ残高まで引き、足りない分は shortfall に残す（負にしない）。
     * まだ付けていない注文にも 0 の取り消しを残し、後で付けない
     */
    public function refunded(string $transactionId, ?string $actor): void
    {
        DB::transaction(function () use ($transactionId, $actor): void {
            $this->events->lock();
            $order = OrderPoints::query()->find($transactionId);

            if ($order === null) {
                return;
            }

            $this->releaseLocked($transactionId, $actor);

            if (PointEntry::query()->where('transaction_id', $transactionId)->where('kind', 'revoke')->where('cause', 'refund')->exists()) {
                return;
            }

            CustomerAccount::query()->whereKey($order->customer_account_id)->lockForUpdate()->firstOrFail();
            $earned = (int) PointEntry::query()->where('transaction_id', $transactionId)->where('kind', 'earn')->value('points');
            // 一部の返金で割合ぶん取り消した分（引けなかった数を含む）は引いてから、残りを取り消す
            $left = max(0, $earned - $this->revokedSoFar($transactionId));
            $take = min($left, $this->balance($order->customer_account_id));
            $this->append($order->customer_account_id, 'revoke', -$take, $transactionId, $actor, cause: 'refund', shortfall: $left - $take);
        });
    }

    /**
     * 一部の返金の後（ADR 0048・0068 の作って決めたこと。呼ぶ側の DB トランザクションの中でもよい）: 返した額 ÷ 受け取った額の割合まで、
     * 使った分を戻し（送料の差額でポイントで戻した分は除く）、付けた分を取り消す（残高まで。足りない分は shortfall に残して負にしない）。
     * 「割合まで」に合わせるので、何度呼んでも同じ（2 回目は差が 0 で何も書かない）。まだ付けていない注文は戻すだけ（付けた時に呼び直す）。
     * 全額の返金（返した額が受け取った額に届いた）は refunded を呼ぶ（今までどおりの全額の取り消し）
     *
     * @param  int  $refunded  これまでに返した額の合計（返品・一部返金と記録した外の返金）
     * @param  int  $received  割合の元（受け取った額から送料の差額の返金を引いた額）
     */
    public function refundedPart(string $transactionId, int $refunded, int $received, ?string $actor): void
    {
        if ($refunded <= 0 || $received <= 0 || $refunded >= $received) {
            return;
        }

        DB::transaction(function () use ($transactionId, $refunded, $received, $actor): void {
            $this->events->lock();
            $order = OrderPoints::query()->find($transactionId);

            if ($order === null) {
                return;
            }

            $entries = PointEntry::query()->where('transaction_id', $transactionId)->get(['kind', 'points', 'cause', 'shortfall']);

            // 全額の返金・取消でもう戻した注文は動かさない
            if ($entries->contains(static fn (PointEntry $e): bool => ($e->kind === 'release' && $e->cause === null) || ($e->kind === 'revoke' && $e->cause === 'refund'))) {
                return;
            }

            $account = CustomerAccount::query()->whereKey($order->customer_account_id)->lockForUpdate()->firstOrFail();
            $spent = -(int) $entries->where('kind', 'spend')->sum('points');
            $credited = (int) $entries->where('kind', 'release')->where('cause', 'shipping')->sum('points');
            $released = (int) $entries->where('kind', 'release')->where('cause', 'partial')->sum('points');
            $back = intdiv(max(0, $spent - $credited) * $refunded, $received) - $released;

            if ($back > 0) {
                $this->append($account->id, 'release', $back, $transactionId, $actor, cause: 'partial');
                $this->forfeitIfLeft($account, $actor);
            }

            $earned = (int) $entries->where('kind', 'earn')->sum('points');
            $take = intdiv($earned * $refunded, $received) - $this->revokedSoFar($transactionId);

            if ($earned > 0 && $take > 0) {
                $taken = min($take, $this->balance($account->id));
                $this->append($account->id, 'revoke', -$taken, $transactionId, $actor, cause: 'partial', shortfall: $take - $taken);
            }
        });
    }

    /**
     * 注文の取り消しの合計（引けなかった数を含む）
     */
    private function revokedSoFar(string $transactionId): int
    {
        return (int) PointEntry::query()->where('transaction_id', $transactionId)->where('kind', 'revoke')->sum(DB::raw('shortfall - points'));
    }

    /**
     * 店の人の手で足す・引く（ADR 0068 の 6）。理由（200 文字まで）は暗号化して帳簿に残し、イベントには数だけ。会員の台帳だけ。残高より多くは引けない
     */
    public function adjust(CustomerAccount $account, int $points, string $reason, ?string $actor): PointEntry
    {
        $reason = trim(str_replace(["\r\n", "\r"], "\n", $reason));

        if ($points === 0 || abs($points) > self::MAX_ADJUST) {
            throw new PointException('調整するポイントは 1〜'.number_format(self::MAX_ADJUST).' の範囲で入力してください。');
        }

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/u', $reason) === 1 || ! mb_check_encoding($reason, 'UTF-8')) {
            throw new PointException('理由は '.self::REASON_MAX.' 文字以内で入力してください。');
        }

        return DB::transaction(function () use ($account, $points, $reason, $actor): PointEntry {
            $this->events->lock();
            $locked = CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if (! self::isMember($locked)) {
                throw new PointException('会員でないお客さんのポイントは調整できません。');
            }

            $balance = $this->balance($locked->id);

            if ($balance + $points < 0) {
                throw new PointException('残高（'.number_format($balance).' ポイント）より多くは減らせません。');
            }

            return $this->append($locked->id, 'adjust', $points, null, $actor, note: $reason);
        });
    }

    /**
     * App が決まった理由で付ける（友だち紹介。ADR 0075）。呼ぶ側の DB トランザクションの中でもよい。
     * 種類は手の足し引き（adjust）で、理由は文でなく決まった印（cause）。店の人の理由の文（note）は無い。
     * 会員でない・止めた・まとめた台帳と、ポイントを使わない店には付けず null を返す（App の側で「付けなかった」と記録する）
     */
    public function grant(string $accountId, int $points, string $cause, ?string $actor): ?PointEntry
    {
        if (! in_array($cause, self::GRANT_CAUSES, true)) {
            throw new LogicException("App が付けるポイントの理由が違う: {$cause}");
        }

        if ($points < 1 || $points > self::MAX_ADJUST) {
            throw new LogicException('App が付けるポイントは 1 以上');
        }

        return DB::transaction(function () use ($accountId, $points, $cause, $actor): ?PointEntry {
            $this->events->lock();
            $account = CustomerAccount::query()->whereKey($accountId)->lockForUpdate()->first();

            if ($account === null || ! self::isMember($account) || ! $this->settings->enabled()) {
                return null;
            }

            return $this->append($account->id, 'adjust', $points, null, $actor, cause: $cause);
        });
    }

    /**
     * 期限を過ぎた残高を消す（毎日。points:expire）。同時に 2 つ走っても、会員ごとに錠の中で確かめ直すので 1 回だけ
     *
     * @return int 消した会員の数
     */
    public function expireDue(CarbonImmutable $now, ?string $actor): int
    {
        $count = 0;

        foreach ($this->dueAccounts($now) as $accountId) {
            $count += $this->expireAccount($accountId, $now, $actor) ? 1 : 0;
        }

        return $count;
    }

    /**
     * その会員の残高が期限を過ぎていれば消す（錠の中で確かめ直す。もう消えた・動いて延びた残高は消さない）
     */
    public function expireAccount(string $accountId, CarbonImmutable $now, ?string $actor): bool
    {
        return DB::transaction(function () use ($accountId, $now, $actor): bool {
            $this->events->lock();
            CustomerAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $latest = $this->latest($accountId);

            if ($latest === null || $latest->balance_after <= 0 || $latest->expires_at === null || $latest->expires_at->greaterThan($now)) {
                return false;
            }

            $this->append($accountId, 'expire', -$latest->balance_after, null, $actor, cause: 'inactive');

            return true;
        });
    }

    /**
     * 退会した会員の残高を消す（退会と同じトランザクション。CustomerLeft）
     */
    public function forfeit(string $accountId, ?string $actor): int
    {
        return DB::transaction(function () use ($accountId, $actor): int {
            $this->events->lock();
            CustomerAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $balance = $this->balance($accountId);

            if ($balance > 0) {
                $this->append($accountId, 'expire', -$balance, null, $actor, cause: 'left');
            }

            return $balance;
        });
    }

    /**
     * 期限を過ぎて残高のある会員（錠の外で集める。消す時に錠の中で確かめ直す）
     *
     * @return list<string>
     */
    public function dueAccounts(CarbonImmutable $now): array
    {
        return DB::table(DB::raw('(SELECT DISTINCT ON (customer_account_id) customer_account_id, balance_after, expires_at FROM point_entries ORDER BY customer_account_id, account_seq DESC) AS latest'))
            ->where('balance_after', '>', 0)->where('expires_at', '<=', $now)->orderBy('customer_account_id')->limit(10000)
            ->pluck('customer_account_id')->map(static fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * 使った分のうち、まだ戻していない分を戻す（送料の差額でポイントを戻した注文は、その残り）
     */
    private function releaseLocked(string $transactionId, ?string $actor): int
    {
        $spent = PointEntry::query()->where('transaction_id', $transactionId)->where('kind', 'spend')->first();

        if ($spent === null || PointEntry::query()->where('transaction_id', $transactionId)->where('kind', 'release')->whereNull('cause')->exists()) {
            return 0;
        }

        $account = CustomerAccount::query()->whereKey($spent->customer_account_id)->lockForUpdate()->firstOrFail();
        $points = -$spent->points - (int) PointEntry::query()->where('transaction_id', $transactionId)->where('kind', 'release')->sum('points');

        if ($points < 1) {
            return 0;
        }

        $this->append($account->id, 'release', $points, $transactionId, $actor);
        $this->forfeitIfLeft($account, $actor);

        return $points;
    }

    /**
     * 退会した人（会員の印が無い台帳）に戻ったポイントは使えないので、すぐに消す。止めた台帳（disabled_at）は消さない（止めを解けばまた使える）
     */
    private function forfeitIfLeft(CustomerAccount $account, ?string $actor): void
    {
        $balance = $this->balance($account->id);

        if ($account->member_since === null && $balance > 0) {
            $this->append($account->id, 'expire', -$balance, null, $actor, cause: 'left');
        }
    }

    /**
     * もう付けた・取り消した・取消で戻した注文か（送料の差額の戻しは数えない）
     */
    private static function settledOrder(string $transactionId): bool
    {
        return PointEntry::query()->where('transaction_id', $transactionId)
            ->where(static fn ($q) => $q->whereIn('kind', ['earn', 'revoke'])->orWhere(static fn ($r) => $r->where('kind', 'release')->whereNull('cause')))
            ->exists();
    }

    private function latest(string $accountId): ?PointEntry
    {
        return PointEntry::query()->where('customer_account_id', $accountId)->orderByDesc('account_seq')->first();
    }

    /**
     * 帳簿に 1 行足し、同じトランザクションでイベントを残す（呼ぶ側が連鎖のロックと台帳の行の錠を持つ）。
     * 残高は DB のトリガーが入れる。期限は動き（付く・使う・戻す・手で足し引き）なら今から 1 年、ほかは前の行のまま
     */
    private function append(string $accountId, string $kind, int $points, ?string $transactionId, ?string $actor, ?string $cause = null, ?string $note = null, int $shortfall = 0): PointEntry
    {
        $now = CarbonImmutable::now();
        $expires = in_array($kind, self::MOVES, true) ? self::expiryFrom($now) : $this->latest($accountId)?->expires_at;

        if ($kind === 'expire' && $points === 0) {
            throw new LogicException('消す残高が無い');
        }

        $entry = PointEntry::create([
            'customer_account_id' => $accountId, 'kind' => $kind, 'points' => $points, 'shortfall' => $shortfall, 'transaction_id' => $transactionId,
            'cause' => $cause, 'note' => $note, 'actor' => $actor, 'expires_at' => $expires, 'occurred_at' => $now,
        ]);
        $entry->refresh();

        $this->events->append('points.'.self::EVENT[$kind], 'customer_account', $accountId, array_filter([
            'points' => $points,
            'balance' => $entry->balance_after,
            'transaction' => $transactionId,
            'cause' => $cause,
            'shortfall' => $shortfall === 0 ? null : $shortfall,
        ], static fn (mixed $v): bool => $v !== null), $actor);

        return $entry;
    }
}
