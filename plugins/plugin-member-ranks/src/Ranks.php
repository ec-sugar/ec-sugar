<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks;

use App\Core\Event\EventStore;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use Carbon\CarbonImmutable;
use EcSugar\PluginMemberRanks\Jobs\SendRankNotice;
use EcSugar\PluginMemberRanks\Models\RankChange;
use EcSugar\PluginMemberRanks\Models\RankMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 会員のランク（ADR 0079）。ランクを変えるのはここだけ。
 *
 * - 毎日の決め直し（run）: 会員ごとに直近 12 か月の購入額（PurchaseAmounts）から、額が届くいちばん上のランクに。上がるのも下がるのも同じ処理で、
 *   上がった時だけ（設定で知らせる店なら）メールで知らせる。会員でなくなった（退会・止めた・まとめた）台帳はランクなしに（知らせない）。
 *   店で初めてランクが付く決め直し（App を使い始めた夜）は知らせず、ほかの夜も店ごとに 1 分に NOTICES_PER_MINUTE 通までに並べる
 * - 店の人の手の上げ下げ（setManually）: お金の段階・直前の再確認の後（App の画面の money の操作）。決めた日から 1 年は毎日の決め直しで変えない（unpin で購入額に戻す）
 *
 * どの書き込みも EventStore::lock() → 会員の行の錠 → 同じトランザクションでイベント（member_rank.changed。中身は前と後のランク・理由だけ。名前・メール・額は入れない）。
 * 錠の中で今のランクと手で決めた印を読み直すので、毎日の処理が 2 つ同時に走っても・手の操作と重なっても、1 回だけ変わる
 */
final class Ranks
{
    public const string ACTOR = 'system:member-ranks';

    /** 手で決めたランクを毎日の決め直しで変えない日数 */
    public const int PIN_DAYS = 365;

    /** 毎日の決め直しで、店ごとに 1 分に送る知らせの数（なる額を下げた夜などに一斉に送らない） */
    public const int NOTICES_PER_MINUTE = 60;

    /** 毎日の決め直しの中で、知らせを何通目まで並べたか（null は毎日の決め直しの外。手の操作はすぐ送る） */
    private ?int $paced = null;

    public function __construct(
        private readonly EventStore $events,
        private readonly RankSettings $settings,
        private readonly PurchaseAmounts $amounts,
    ) {}

    /**
     * 額が届くいちばん上のランクの位置（どれにも届かなければ 0）
     *
     * @param  array<int, Rank>  $ranks
     */
    public static function rankFor(int $amount, array $ranks): int
    {
        $position = 0;

        foreach ($ranks as $rank) {
            if ($amount >= $rank->threshold) {
                $position = $rank->position;
            }
        }

        return $position;
    }

    /**
     * ランクを持てる台帳か（会員で、止められていない・まとめられていない）
     */
    public static function eligible(CustomerAccount $account): bool
    {
        return $account->member_since !== null && $account->disabled_at === null && $account->merged_into_id === null;
    }

    /**
     * その会員の今のランク（ランクを持てない台帳・ランクなしは 0。店が使うランクを減らしたら、使うランクの上までに抑える）
     */
    public function current(string $accountId): int
    {
        return max(0, $this->viewerRank($accountId));
    }

    /**
     * 見る人のランク（ランクを持てない台帳・見つからない台帳は -1、ランクなしの会員は 0）。会員だけの商品を見せるかに使う
     */
    public function viewerRank(string $accountId): int
    {
        $row = DB::table('customer_accounts')
            ->leftJoin('member_ranks_members', 'member_ranks_members.customer_account_id', '=', 'customer_accounts.id')
            ->where('customer_accounts.id', $accountId)
            ->first(['customer_accounts.member_since', 'customer_accounts.disabled_at', 'customer_accounts.merged_into_id', 'member_ranks_members.rank']);

        if ($row === null || $row->member_since === null || $row->disabled_at !== null || $row->merged_into_id !== null) {
            return -1;
        }

        return min((int) ($row->rank ?? 0), count($this->settings->ranks()));
    }

    /**
     * 今のランク（ランクなしは null）
     */
    public function rankOf(string $accountId): ?Rank
    {
        $position = $this->current($accountId);

        return $position === 0 ? null : ($this->settings->ranks()[$position] ?? null);
    }

    /**
     * 毎日の決め直し（App を使う店で 1 日 1 回。member-ranks:run）
     *
     * @return array{up: int, down: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $ranks = $this->settings->ranks();
        // 最初の決め直し（App を使い始めた夜）は、今までのお買い物でランクを付けるだけで知らせない（全員へ一斉にメールを出さない）
        $first = ! RankChange::query()->where('cause', RankChange::AUTO)->exists();
        $amounts = $this->amounts->of(null, $now);
        $stored = RankMember::query()->get(['customer_account_id', 'rank', 'pinned_until'])->keyBy('customer_account_id');

        // 会員（ランクを持てる台帳）と、ランクの行のある台帳（会員でなくなったものを、ランクなしに戻す）
        $members = CustomerAccount::query()->whereNotNull('member_since')->whereNull('disabled_at')->whereNull('merged_into_id')->pluck('id')->all();
        $targets = array_fill_keys($members, true);

        foreach ($stored as $id => $row) {
            if (! isset($targets[$id]) && $row->rank > 0) {
                $targets[$id] = false;
            }
        }

        $this->paced = 0;

        try {
            $result = $this->decide($targets, $stored, $amounts, $ranks, $first, $now);
        } finally {
            $this->paced = null;
        }

        return $result;
    }

    /**
     * 決め直しの本体（会員ごとに 1 つのトランザクション）
     *
     * @param  array<string, bool>  $targets  台帳 => ランクを持てるか
     * @param  Collection<string, RankMember>  $stored
     * @param  array<string, int>  $amounts
     * @param  array<int, Rank>  $ranks
     * @return array{up: int, down: int}
     */
    private function decide(array $targets, Collection $stored, array $amounts, array $ranks, bool $first, CarbonImmutable $now): array
    {
        $result = ['up' => 0, 'down' => 0];

        foreach ($targets as $id => $eligible) {
            $id = (string) $id;
            $row = $stored->get($id);
            $to = $eligible ? self::rankFor($amounts[$id] ?? 0, $ranks) : 0;

            // 手で決めた間は変えない（会員でなくなった時は戻す）。今と同じなら書かない
            if (($eligible && $row?->pinned($now)) || ($row->rank ?? 0) === $to) {
                continue;
            }

            $change = $this->change($id, $to, $eligible ? RankChange::AUTO : RankChange::LEFT, self::ACTOR, $now, $first);

            if ($change !== null) {
                $result[$change->to_rank > $change->from_rank ? 'up' : 'down']++;
            }
        }

        return $result;
    }

    /**
     * 店の人が手でランクを決める（App の画面の money の操作。お金の段階・直前の再確認は核が確かめる）。決めた日から 1 年は毎日の決め直しで変えない
     */
    public function setManually(string $accountId, int $to, string $actor, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        if ($to < 0 || $to > count($this->settings->ranks())) {
            throw new AdminActionRefused('そのランクは今は使っていません。設定の「使うランクの数」を確認してください。');
        }

        DB::transaction(function () use ($accountId, $to, $actor, $now): void {
            $this->events->lock();
            $account = CustomerAccount::query()->whereKey($accountId)->first() ?? throw new AdminRecordNotFound;

            if (! self::eligible($account)) {
                throw new AdminActionRefused('会員でないお客さん（退会した・停止した・同じお客さんとしてまとめた記録）のランクは設定できません。');
            }

            $row = RankMember::query()->whereKey($accountId)->lockForUpdate()->first();
            $until = $now->addDays(self::PIN_DAYS);

            if (($row->rank ?? 0) === $to) {
                // 同じランクなら、手で決めた印だけを付け直す（毎日の決め直しで変えないように）
                RankMember::query()->updateOrCreate(['customer_account_id' => $accountId], ['rank' => $to, 'ranked_at' => $row->ranked_at ?? $now, 'pinned_until' => $until]);
                $this->events->append('member_rank.pinned', 'customer_account', $accountId, ['rank' => $to], $actor);

                return;
            }

            $this->write($account->id, $row, $to, RankChange::MANUAL, $actor, $now, $until);
        });
    }

    /**
     * 手で決めた印を外し、購入額で決め直す（次の毎日の決め直しを待たずに、今の額で）
     */
    public function unpin(string $accountId, string $actor, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $to = self::rankFor($this->amounts->forAccount($accountId, $now), $this->settings->ranks());

        DB::transaction(function () use ($accountId, $actor, $now, $to): void {
            $this->events->lock();
            $account = CustomerAccount::query()->whereKey($accountId)->first() ?? throw new AdminRecordNotFound;
            $row = RankMember::query()->whereKey($accountId)->lockForUpdate()->first();

            if ($row === null || $row->pinned_until === null) {
                throw new AdminActionRefused('このお客さんのランクは、すでに購入額による自動の設定です。');
            }

            RankMember::query()->whereKey($accountId)->update(['pinned_until' => null, 'updated_at' => $now]);
            $this->events->append('member_rank.unpinned', 'customer_account', $accountId, [], $actor);
            $row->pinned_until = null;
            $target = self::eligible($account) ? $to : 0;

            if ($row->rank !== $target) {
                $this->write($accountId, $row, $target, self::eligible($account) ? RankChange::AUTO : RankChange::LEFT, $actor, $now, null);
            }
        });
    }

    /**
     * 毎日の決め直しの 1 人分（連鎖のロックの中で、今のランクと手で決めた印を読み直す）
     */
    private function change(string $accountId, int $to, string $cause, string $actor, CarbonImmutable $now, bool $quiet): ?RankChange
    {
        return DB::transaction(function () use ($accountId, $to, $cause, $actor, $now, $quiet): ?RankChange {
            $this->events->lock();
            $row = RankMember::query()->whereKey($accountId)->lockForUpdate()->first();

            if (($row->rank ?? 0) === $to || ($cause === RankChange::AUTO && $row?->pinned($now))) {
                return null;
            }

            return $this->write($accountId, $row, $to, $cause, $actor, $now, $cause === RankChange::LEFT ? null : $row?->pinned_until, $quiet);
        });
    }

    /**
     * ランクを書き、変わった記録とイベントを残す（呼ぶ側が連鎖のロックと行の錠を持つ）。上がって、知らせる店なら、確定の後に知らせのメールを送る
     */
    private function write(string $accountId, ?RankMember $row, int $to, string $cause, string $actor, CarbonImmutable $now, ?CarbonImmutable $pinnedUntil, bool $quiet = false): RankChange
    {
        $from = $row->rank ?? 0;
        RankMember::query()->updateOrCreate(['customer_account_id' => $accountId], ['rank' => $to, 'ranked_at' => $now, 'pinned_until' => $pinnedUntil]);
        $notify = ! $quiet && $to > $from && $cause !== RankChange::LEFT && $this->settings->notify();
        $change = RankChange::create(['customer_account_id' => $accountId, 'from_rank' => $from, 'to_rank' => $to, 'cause' => $cause, 'notice' => $notify ? 'queued' : 'none', 'created_at' => $now]);
        $this->events->append('member_rank.changed', 'customer_account', $accountId, ['from' => $from, 'to' => $to, 'cause' => $cause], $actor);

        if ($notify) {
            $id = $change->id;
            // 毎日の決め直しでは、店ごとに 1 分に NOTICES_PER_MINUTE 通までに並べる（キューの遅らせ）。手の操作はすぐ
            $delay = $this->paced === null ? 0 : intdiv($this->paced++, self::NOTICES_PER_MINUTE) * 60;
            DB::afterCommit(static fn () => SendRankNotice::dispatch($id)->delay($now->addSeconds($delay)));
        }

        return $change;
    }
}
