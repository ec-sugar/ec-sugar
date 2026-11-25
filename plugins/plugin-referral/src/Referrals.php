<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral;

use App\Core\Crypto\BlindIndex;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerMerges;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginReferral\Models\Referral;
use Illuminate\Support\Facades\DB;

/**
 * 友だち紹介の流れ（ADR 0075）。書くのはここだけで、どの書き込みも連鎖のロック（EventStore::lock）→ 行のロックの順に取り、同じトランザクションでイベントを残す。
 * イベントの中身は台帳と注文の ID・数・決まった理由だけ（名前・メール・住所・電話・接続元は入れない）。
 *
 * 1. 結ぶ（link）: 紹介のリンクの印を持つ人が会員になった時（customer_account.joined）。紹介した人が会員で、自分でなく、紹介された人がまだ紹介されていない・払われた注文が無い時だけ
 * 2. 最初の注文を決める（paid）: 紹介された人の注文が初めて払われた時（PaymentConfirmed）。いちばん先に払われた注文 1 つだけ（2 回目からは付かない）
 * 3. 付ける（shipped → decide）: その注文の受渡が全部発送された時。取消・返金で閉じた注文・App を止めていた・ポイントを使わない・会員でなくなった・
 *    自分の紹介・今月の上限を超えたは付けず、同じ接続元から何人も来たものは確かめ待ちにして店の人が選ぶ
 */
final class Referrals
{
    /** 自動で付ける時の actor（定期の処理と同じ形） */
    public const string ACTOR = 'system:referral';

    /** 同じ紹介した人で、同じ接続元から会員になった人がこの数に届いたら確かめ待ちにする（30 日のうち） */
    public const int SAME_SOURCE = 3;

    /** 会員になってからこの時間のうちだけ結ぶ（会員になった時の知らせで結ぶので、ふつうはすぐ） */
    private const int JOIN_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly EventStore $events,
        private readonly ReferralCodes $codes,
        private readonly ReferralSettings $settings,
        private readonly PointService $points,
    ) {}

    /**
     * 紹介のリンクから来た人が会員になった: 紹介を結ぶ（結ばなければ null）。$from は会員になった要求の接続元（IPv6 は /64 にまとめた形。ClientSource）
     */
    public function link(string $friendId, string $code, ?string $from, CarbonImmutable $now): ?Referral
    {
        if (! $this->settings->active()) {
            return null;
        }

        return DB::transaction(function () use ($friendId, $code, $from, $now): ?Referral {
            $this->events->lock();
            $referrerId = $this->codes->owner($code);

            if ($referrerId === null || $referrerId === $friendId) {
                return null;
            }

            // 2 つの台帳の行は ID の順にロックする（同時に逆向きで結んでも行き詰まらない）
            $accounts = CustomerAccount::query()->whereKey([$referrerId, $friendId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $friend = $accounts->get($friendId);
            $referrer = $accounts->get($referrerId);

            if (! $friend instanceof CustomerAccount || ! $referrer instanceof CustomerAccount || ! PointService::isMember($friend) || ! PointService::isMember($referrer)) {
                return null;
            }

            // 会員になったばかりの人だけ（会員になった時の知らせで呼ぶ）。紹介されるのは 1 回だけ（一意の索引も守る）
            if ($friend->member_since === null || $friend->member_since->lessThan($now->subMinutes(self::JOIN_WINDOW_MINUTES))
                || Referral::query()->where('friend_account_id', $friend->id)->exists()) {
                return null;
            }

            // 同じお客さんとしてまとめた記録どうしは結ばない。会員になる前に払われた注文がある人は新しいお客さんではない
            if (in_array($referrer->id, CustomerMerges::family($friend), true) || in_array($friend->id, CustomerMerges::family($referrer), true)
                || self::firstPaid(CustomerMerges::family($friend)) !== null) {
                return null;
            }

            $referral = Referral::create([
                'referrer_account_id' => $referrer->id,
                'friend_account_id' => $friend->id,
                'status' => Referral::LINKED,
                'source_index' => $from === null || $from === '' || $from === 'unknown' ? null : BlindIndex::of('ip', $from),
                'linked_at' => $now,
            ]);
            $this->events->append('referral.linked', 'referral', $referral->id, ['referrer' => $referrer->id, 'friend' => $friend->id], 'storefront');

            return $referral;
        });
    }

    /**
     * 注文が払われた: 紹介された人の最初に払われた注文なら、その注文を紹介に結ぶ
     */
    public function paid(string $transactionId): void
    {
        if (! $this->mayConcern($transactionId)) {
            return;
        }

        DB::transaction(function () use ($transactionId): void {
            $this->events->lock();
            $referral = $this->forOrder($transactionId);

            if ($referral !== null && $referral->status === Referral::LINKED) {
                $this->pin($referral);
            }
        });
    }

    /**
     * 受渡を発送した: その注文の受渡が全部発送され、紹介に結んだ最初の注文なら、付けるかを決める。
     * App を使っていない時（$enabled = false）に発送したものは付けない
     */
    public function shipped(string $transactionId, bool $enabled): void
    {
        // 紹介された人の注文でなければ（ほとんど全部）、連鎖のロックを取らない
        if (! $this->mayConcern($transactionId)) {
            return;
        }

        DB::transaction(function () use ($transactionId, $enabled): void {
            $this->events->lock();
            $referral = $this->forOrder($transactionId);

            if ($referral === null) {
                return;
            }

            if ($referral->status === Referral::LINKED) {
                $this->pin($referral);
            }

            if ($referral->status !== Referral::ORDERED || $referral->transaction_id !== $transactionId || ! self::allShipped($transactionId)) {
                return;
            }

            $this->decide($referral, $enabled);
        });
    }

    /**
     * 注文が取り消された・返金で閉じた: まだ付けていない紹介は付けない（発送の前の取消は、発送が来ないのでもともと付かない）
     */
    public function closed(string $transactionId): void
    {
        // 紹介に結んでいない注文（ほとんど全部）では、連鎖のロックを取らない
        if (! Referral::query()->where('transaction_id', $transactionId)->whereIn('status', [Referral::ORDERED, Referral::REVIEW])->exists()) {
            return;
        }

        DB::transaction(function () use ($transactionId): void {
            $this->events->lock();
            $referral = Referral::query()->where('transaction_id', $transactionId)->lockForUpdate()->first();

            if ($referral !== null && in_array($referral->status, [Referral::ORDERED, Referral::REVIEW], true)) {
                $this->refuse($referral, 'cancelled', self::ACTOR);
            }
        });
    }

    /**
     * 取りこぼしを拾う（毎時の referral:run。App を使う店だけ）: 払われたのに結んでいない・発送したのに決めていない・閉じたのに残っている紹介
     *
     * @return array<string, int>
     */
    public function sweep(): array
    {
        $count = ['pinned' => 0, 'decided' => 0];

        foreach (Referral::query()->where('status', Referral::LINKED)->orderBy('linked_at')->limit(1000)->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $this->events->lock();
                $referral = Referral::query()->whereKey($id)->lockForUpdate()->first();

                if ($referral !== null && $referral->status === Referral::LINKED && $this->pin($referral)) {
                    $count['pinned']++;
                }
            });
        }

        foreach (Referral::query()->where('status', Referral::ORDERED)->orderBy('ordered_at')->limit(1000)->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $this->events->lock();
                $referral = Referral::query()->whereKey($id)->lockForUpdate()->first();

                if ($referral === null || $referral->status !== Referral::ORDERED || $referral->transaction_id === null) {
                    return;
                }

                $order = Transaction::query()->find($referral->transaction_id);

                if ($order?->status->isClosed() === true) {
                    $this->refuse($referral, 'cancelled', self::ACTOR);
                    $count['decided']++;
                } elseif ($order !== null && in_array($order->phase, [Phase::Fulfillment, Phase::Acceptance, Phase::Settlement], true) && self::allShipped($order->id)) {
                    $this->decide($referral, $this->settings->enabled());
                    $count['decided']++;
                }
            });
        }

        return $count;
    }

    /**
     * 確かめ待ちを、店の人が付ける（運用の段階。記録に actor を残す）。決まりに合わなくなっていれば断る（変えない）
     */
    public function approve(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $referral = $this->reviewing($id);
            $order = Transaction::query()->findOrFail($referral->transaction_id);

            if ($order->status->isClosed()) {
                throw new AdminActionRefused('最初の注文が取り消されたか返金されたので、ポイントは付けられません。「付けない」を選んでください。');
            }

            if (! app(PointSettings::class)->enabled()) {
                throw new AdminActionRefused('ポイントを使わない設定になっているため、付けられません。設定の「ポイント」を「使う」にしてから、もう一度お試しください。');
            }

            if (! $this->bothMembers($referral)) {
                throw new AdminActionRefused('紹介した人か紹介された人が会員でなくなったので、付けられません。「付けない」を選んでください。');
            }

            if ($this->rewardedThisMonth($referral->referrer_account_id) >= $this->settings->monthlyLimit()) {
                throw new AdminActionRefused('紹介した人は今月の上限まで受け取っています。来月にもう一度お試しください。');
            }

            $this->reward($referral, $actor, 'referral.approved');
        });
    }

    /**
     * 確かめ待ちを、店の人が付けないにする（取り消せない）
     */
    public function decline(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $referral = $this->reviewing($id);
            $referral->status = Referral::DECLINED;
            $referral->decided_at = CarbonImmutable::now();
            $referral->save();
            $this->events->append('referral.declined', 'referral', $referral->id, ['transaction' => $referral->transaction_id], $actor);
        });
    }

    /**
     * 紹介した人が今月（日本の時間の暦の月）に受け取った紹介の数
     */
    public function rewardedThisMonth(string $referrerId): int
    {
        // 日本の時間の月の初めを UTC に直して比べる（時刻の列は UTC の文字で渡るため。直さないと 9 時間ずれる）
        $since = CarbonImmutable::now('Asia/Tokyo')->startOfMonth()->utc();

        return Referral::query()->where('referrer_account_id', $referrerId)->where('status', Referral::REWARDED)->where('decided_at', '>=', $since)->count();
    }

    /**
     * その台帳の人が払った最初の注文（物販。いちばん先に「支払い」の段へ進んだもの）と、その時刻
     *
     * @param  list<string>  $family
     * @return array{id: string, at: CarbonImmutable}|null
     */
    public static function firstPaid(array $family): ?array
    {
        $event = DomainEvent::query()
            ->where('aggregate_type', 'transaction')
            ->whereIn('aggregate_id', Transaction::query()->select('id')->where('kind', 'sale')->whereIn('customer_account_id', $family))
            ->where('type', 'transaction.phase_changed')
            ->whereRaw("payload->>'to' = ?", [Phase::Payment->value])
            ->orderBy('sequence')
            ->first(['aggregate_id', 'occurred_at']);

        return $event === null ? null : ['id' => $event->aggregate_id, 'at' => $event->occurred_at];
    }

    /**
     * 注文から、その人が紹介された紹介（行のロックつき。呼ぶ側が連鎖のロックを持つ）。結んだ注文か、紹介された人（まとめた記録なら、まとめた先）で探す
     */
    private function forOrder(string $transactionId): ?Referral
    {
        $pinned = Referral::query()->where('transaction_id', $transactionId)->lockForUpdate()->first();

        if ($pinned !== null) {
            return $pinned;
        }

        $friend = self::friendOf($transactionId);

        return $friend === null ? null : Referral::query()->where('friend_account_id', $friend)->lockForUpdate()->first();
    }

    /**
     * まだ決めていない紹介に関わりうる注文か（ロックの外の下見。決めるのはロックの中で読み直してから）
     */
    private function mayConcern(string $transactionId): bool
    {
        $friend = self::friendOf($transactionId);

        return Referral::query()->whereIn('status', [Referral::LINKED, Referral::ORDERED])
            ->where(static fn ($q) => $q->where('transaction_id', $transactionId)->when($friend !== null, static fn ($w) => $w->orWhere('friend_account_id', $friend)))
            ->exists();
    }

    /**
     * 注文の買う人の台帳（まとめた記録なら、まとめた先）。物販でない・台帳の無い注文は null
     */
    private static function friendOf(string $transactionId): ?string
    {
        $order = Transaction::query()->where('kind', 'sale')->find($transactionId);
        $account = $order?->customer_account_id === null ? null : CustomerAccount::query()->find($order->customer_account_id);

        return $account === null ? null : ($account->merged_into_id ?? $account->id);
    }

    /**
     * 結んだ紹介に、最初に払われた注文を結ぶ。会員になる前に払われていたら付けない（後からまとめた記録の注文など）
     *
     * @return bool 結んだ・決めた
     */
    private function pin(Referral $referral): bool
    {
        $friend = CustomerAccount::query()->find($referral->friend_account_id);
        $first = $friend === null ? null : self::firstPaid(CustomerMerges::family($friend));

        if ($first === null) {
            return false;
        }

        // イベントの時刻は秒で切り捨ててあるので、結んだ時刻も秒でそろえて比べる
        if ($first['at']->lessThan($referral->linked_at->startOfSecond())) {
            $this->refuse($referral, 'not_new', self::ACTOR);

            return true;
        }

        $referral->status = Referral::ORDERED;
        $referral->transaction_id = $first['id'];
        $referral->ordered_at = CarbonImmutable::now();
        $referral->save();
        $this->events->append('referral.ordered', 'referral', $referral->id, ['transaction' => $first['id']], self::ACTOR);

        return true;
    }

    /**
     * 最初の注文が全部発送された紹介に、付けるか・付けないか・確かめ待ちにするかを決める
     */
    private function decide(Referral $referral, bool $enabled): void
    {
        $order = Transaction::query()->find($referral->transaction_id);

        $reason = match (true) {
            $order === null || $order->status->isClosed() => 'cancelled',
            ! $enabled => 'disabled',
            ! app(PointSettings::class)->enabled() => 'points_off',
            ! $this->bothMembers($referral) => 'not_member',
            SamePerson::matches($referral->referrer_account_id, $referral->friend_account_id, $referral->transaction_id) => 'self',
            $this->rewardedThisMonth($referral->referrer_account_id) >= $this->settings->monthlyLimit() => 'limit',
            default => null,
        };

        if ($reason !== null) {
            $this->refuse($referral, $reason, self::ACTOR);

            return;
        }

        if ($this->fromSameSource($referral)) {
            $referral->status = Referral::REVIEW;
            $referral->reason = 'same_source';
            $referral->save();
            $this->events->append('referral.held', 'referral', $referral->id, ['reason' => 'same_source', 'transaction' => $referral->transaction_id], self::ACTOR);

            return;
        }

        $this->reward($referral, self::ACTOR, 'referral.rewarded');
    }

    /**
     * 両方にポイントを付ける（核の帳簿へ。理由は referral）。0 の側は付けない
     */
    private function reward(Referral $referral, string $actor, string $event): void
    {
        $referrerPoints = $this->settings->referrerPoints();
        $friendPoints = $this->settings->friendPoints();
        $given = [
            $referrerPoints > 0 ? $this->points->grant($referral->referrer_account_id, $referrerPoints, 'referral', $actor) : null,
            $friendPoints > 0 ? $this->points->grant($referral->friend_account_id, $friendPoints, 'referral', $actor) : null,
        ];

        $referral->status = Referral::REWARDED;
        $referral->reason = null;
        $referral->referrer_points = $given[0] === null ? 0 : $given[0]->points;
        $referral->friend_points = $given[1] === null ? 0 : $given[1]->points;
        $referral->decided_at = CarbonImmutable::now();
        $referral->save();
        $this->events->append($event, 'referral', $referral->id, [
            'transaction' => $referral->transaction_id,
            'referrer_points' => $referral->referrer_points,
            'friend_points' => $referral->friend_points,
        ], $actor);
    }

    private function refuse(Referral $referral, string $reason, string $actor): void
    {
        $referral->status = Referral::REFUSED;
        $referral->reason = $reason;
        $referral->decided_at = CarbonImmutable::now();
        $referral->save();
        $this->events->append('referral.refused', 'referral', $referral->id, ['reason' => $reason, 'transaction' => $referral->transaction_id], $actor);
    }

    private function bothMembers(Referral $referral): bool
    {
        $accounts = CustomerAccount::query()->whereKey([$referral->referrer_account_id, $referral->friend_account_id])->get();

        return $accounts->count() === 2 && $accounts->every(static fn (CustomerAccount $a): bool => PointService::isMember($a));
    }

    /**
     * 同じ紹介した人で、同じ接続元から会員になった人が、この人を入れて SAME_SOURCE 人以上（30 日のうち）
     */
    private function fromSameSource(Referral $referral): bool
    {
        if ($referral->source_index === null) {
            return false;
        }

        $others = Referral::query()->where('referrer_account_id', $referral->referrer_account_id)->where('source_index', $referral->source_index)
            ->whereKeyNot($referral->id)->where('linked_at', '>=', $referral->linked_at->subDays(30))->where('linked_at', '<=', $referral->linked_at->addDays(30))->count();

        return $others + 1 >= self::SAME_SOURCE;
    }

    private function reviewing(string $id): Referral
    {
        $referral = Referral::query()->whereKey(strtolower($id))->lockForUpdate()->first();

        if ($referral === null || $referral->status !== Referral::REVIEW || $referral->transaction_id === null) {
            throw new AdminActionRefused('この紹介は、すでに確認待ちではありません。画面を再読み込みしてください。');
        }

        return $referral;
    }

    private static function allShipped(string $transactionId): bool
    {
        return Fulfillment::query()->where('transaction_id', $transactionId)->exists()
            && ! Fulfillment::query()->where('transaction_id', $transactionId)->where('status', FulfillmentStatus::Pending)->exists();
    }
}
