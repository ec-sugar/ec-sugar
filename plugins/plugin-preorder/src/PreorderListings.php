<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder;

use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginPreorder\Models\PreorderItem;
use EcSugar\PluginPreorder\Models\PreorderListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 商品ごとの予約販売の決まりを変える（ADR 0073）。変えるのはここだけ: 連鎖のロック → 商品の決まりの行のロック → イベント（同じトランザクション）。
 *
 * - 始める: 発売日は明日から 2 年先まで。受付の終わりは発売日の前の日まで。上限は 1〜100,000 点（空なら上限なし）
 * - 発売日を変える（同じ回の続き）: 発売日の前か、発売日を過ぎても発送の前の予約の注文が残っている間（品が届かず延ばす）。新しい日は今日から
 *   （今日にすると、もう予約でなくなり発送できる）。発送の前の予約の注文は新しい日に直し、受渡の発送を始める日も直す（核の FulfillmentService）。
 *   まとめて送る日が変わった払った注文のお客さんへ、どの商品の日が変わったかをメールで知らせる（トランザクションの後にキューで）
 * - 上限は今の予約の数より下げられない
 * - やめる: 行は残して止める（前の注文の予約と、受渡の発送を始める日はそのまま。発売日に発送できる）。始め直すと同じ回の続き（受けた予約を数え直す）
 * - 次の回: 発売日を過ぎ、発送の前の予約の注文が無く、この回の予約があるときは、欄から始め直せない（前の回の予約を数えてしまう）。
 *   App の画面の「次の予約を始める」（nextRound）で回を進めてから、新しい発売日を入れる。この回に予約が無ければ、そのまま新しい発売日を入れられる
 */
final class PreorderListings
{
    public const int MAX_CAPACITY = 100000;

    public const int MAX_DAYS_AHEAD = 730;

    public const string NEXT_ROUND = 'この商品の前回の予約は、発売日を過ぎて発送も終わっています。次の予約を受け付けるときは、App の「予約販売」の画面でこの商品を開き、「次の予約を始める」を押してください。';

    public function __construct(
        private readonly EventStore $events,
        private readonly FulfillmentService $fulfillments,
    ) {}

    /**
     * @param  array{enabled: bool, release_date: ?string, ends_on: ?string, capacity: ?string}  $values
     *
     * @throws ValidationException
     */
    public function save(Listing $listing, array $values, string $actor): void
    {
        $today = PreorderTerms::today();

        $moved = DB::transaction(function () use ($listing, $values, $actor, $today): ?array {
            $this->events->lock();
            $row = PreorderListing::query()->where('listing_id', $listing->id)->lockForUpdate()->first();

            if (! $values['enabled']) {
                if ($row !== null && $row->active) {
                    $row->active = false;
                    $row->save();
                    $this->events->append('preorder.stopped', 'preorder_listing', $row->id, ['listing_id' => $listing->id, 'round' => $row->round], $actor);
                }

                return null;
            }

            $taken = $row === null ? 0 : (PreorderCounts::taken([$listing->id])[$listing->id] ?? 0);
            // 同じ回の続きか: 発売日の前か、発送の前の予約の注文が残っている（発売日を過ぎても、延ばすのは同じ回）
            $continuing = $row !== null && ($row->releaseDate() > $today || self::pendingOrders($row) > 0);

            // 発売日を過ぎて発送も済んだ回に予約がある: 欄からは始め直さない（App の画面の「次の予約を始める」で）
            if ($row !== null && ! $continuing && $taken > 0) {
                throw ValidationException::withMessages(['release_date' => self::NEXT_ROUND]);
            }

            [$release, $ends, $capacity] = self::check($values, $today, $continuing, $row?->releaseDate());

            if (! $continuing) {
                // 初めて・この回にまだ予約が無い（次の予約を始めた後・受けないまま発売日を過ぎた）: 新しい発売日で受け始める（回はそのまま）
                $round = $row === null ? 1 : $row->round;
                $row ??= new PreorderListing(['listing_id' => $listing->id]);
                $row->fill(['active' => true, 'round' => $round, 'release_date' => $release, 'ends_on' => $ends, 'capacity' => $capacity])->save();
                $this->events->append('preorder.started', 'preorder_listing', $row->id, ['listing_id' => $listing->id, 'round' => $round, 'release_date' => $release, 'ends_on' => $ends, 'capacity' => $capacity], $actor);

                return null;
            }

            if ($capacity !== null && $capacity < $taken) {
                throw ValidationException::withMessages(['capacity' => "今の予約の数（{$taken} 点）より少なくはできません。"]);
            }

            $before = ['active' => $row->active, 'release_date' => $row->releaseDate(), 'ends_on' => $row->endsOn(), 'capacity' => $row->capacity];
            $after = ['active' => true, 'release_date' => $release, 'ends_on' => $ends, 'capacity' => $capacity];
            $changed = array_keys(array_filter($after, static fn (mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                return null;
            }

            $row->fill($after)->save();
            $this->events->append('preorder.updated', 'preorder_listing', $row->id, ['listing_id' => $listing->id, 'round' => $row->round, 'changed' => $changed, ...$after], $actor);

            return in_array('release_date', $changed, true)
                ? ['from' => $before['release_date'], 'to' => $release, 'listing' => $listing->id, 'orders' => $this->moveOrders($row, $before['release_date'], $release, $actor)]
                : null;
        });

        // 発売日が変わった知らせ（まとめて送る日が変わった払った注文のお客さんへ。書き込みが確かになった後に、キューで 1 通ずつ）
        if ($moved !== null) {
            DB::afterCommit(static function () use ($moved): void {
                foreach ($moved['orders'] as $transactionId) {
                    SendPreorderMail::dispatch($transactionId, $moved['from'], $moved['to'], $moved['listing']);
                }
            });
        }
    }

    /**
     * 次の予約を始める（App の画面の操作）: 発売日を過ぎ、発送の前の予約の注文が無い商品の回を 1 つ進める。前の回の予約はもう数えない。
     * 発売日は過ぎたままなので、店の人が新しい発売日を入れるまで予約にならない
     *
     * @throws PreorderRefused
     */
    public function nextRound(string $rowId, string $actor): void
    {
        DB::transaction(function () use ($rowId, $actor): void {
            $this->events->lock();
            $row = PreorderListing::query()->whereKey($rowId)->lockForUpdate()->first() ?? throw new PreorderRefused('この予約販売は見つかりません。');

            if (! self::canStartNext($row)) {
                throw new PreorderRefused('発売日の前や、まだ発送していない予約の注文があるときは、次の予約を始められません。');
            }

            $row->round += 1;
            $row->save();
            $this->events->append('preorder.next_round', 'preorder_listing', $row->id, ['listing_id' => $row->listing_id, 'round' => $row->round], $actor);
        });
    }

    /**
     * 「次の予約を始める」を出すか（発売日を過ぎ、発送の前の予約の注文が無く、この回に予約がある）
     */
    public static function canStartNext(PreorderListing $row): bool
    {
        return $row->releaseDate() <= PreorderTerms::today() && self::pendingOrders($row) === 0
            && (PreorderCounts::taken([$row->listing_id])[$row->listing_id] ?? 0) > 0;
    }

    /**
     * 今の回の、発送の前の予約の注文の数（取引が開いていて、発送を始める日のある発送の前の受渡がある）
     */
    public static function pendingOrders(PreorderListing $row): int
    {
        return PreorderItem::query()
            ->where('listing_id', $row->listing_id)->where('round', $row->round)
            ->whereIn('transaction_id', Transaction::query()->where('status', TransactionStatus::Open)->select('id'))
            ->whereIn('transaction_id', Fulfillment::query()->where('status', FulfillmentStatus::Pending)->whereNotNull('ships_on')->select('transaction_id'))
            ->count();
    }

    /**
     * 発売日が変わった: 今の回の、発送の前の予約の注文に約束した日を直し、受渡の発送を始める日を注文の中のいちばん遅い発売日に直す。
     * 知らせる注文（払った注文で、まとめて送る日が変わったもの）の ID を返す
     *
     * @return list<string>
     */
    private function moveOrders(PreorderListing $row, string $from, string $to, string $actor): array
    {
        $items = PreorderItem::query()
            ->where('listing_id', $row->listing_id)->where('round', $row->round)
            ->whereIn('transaction_id', Transaction::query()->where('status', TransactionStatus::Open)->select('id'))
            ->whereIn('transaction_id', Fulfillment::query()->where('status', FulfillmentStatus::Pending)->whereNotNull('ships_on')->select('transaction_id'))
            ->orderBy('transaction_id')
            ->lockForUpdate()
            ->get();
        $notify = [];

        foreach ($items as $item) {
            $item->release_date = $to;
            $item->save();
            // 注文の中のいちばん遅い発売日（ほかの予約の品の日も見る）
            $shipsOn = substr((string) PreorderItem::query()->where('transaction_id', $item->transaction_id)->max('release_date'), 0, 10);
            $moved = false;

            foreach (Fulfillment::query()->where('transaction_id', $item->transaction_id)->where('status', FulfillmentStatus::Pending)->whereNotNull('ships_on')->orderBy('id')->get() as $fulfillment) {
                $moved = $moved || $fulfillment->shipsOn() !== $shipsOn;
                $this->fulfillments->reschedule($fulfillment, $shipsOn, $actor);
            }

            // 知らせるのは、まとめて送る日が変わった払った注文だけ（お支払いの前の注文は、払われずに消えることがある。払った時のメールに今の日が載る）
            $phase = Transaction::query()->whereKey($item->transaction_id)->first(['phase'])?->phase;

            if ($moved && in_array($phase, [Phase::Payment, Phase::Fulfillment], true)) {
                $notify[] = $item->transaction_id;
            }
        }

        $this->events->append('preorder.release_date_changed', 'preorder_listing', $row->id, ['listing_id' => $row->listing_id, 'from' => $from, 'to' => $to, 'orders' => count($items)], $actor);

        return $notify;
    }

    /**
     * 値を確かめる。誤りは欄の名前で。$current は今の発売日（続けて直す時に、同じ日のままなら過ぎていても通す）
     *
     * @param  array{enabled: bool, release_date: ?string, ends_on: ?string, capacity: ?string}  $values
     * @return array{0: string, 1: ?string, 2: ?int}
     *
     * @throws ValidationException
     */
    public static function check(array $values, string $today, bool $continuing, ?string $current = null): array
    {
        $release = self::date($values['release_date'] ?? null);

        if ($release === null) {
            throw ValidationException::withMessages(['release_date' => '発売日を「2026-11-20」の形で入力してください。']);
        }

        $latest = CarbonImmutable::createFromFormat('!Y-m-d', $today, PreorderTerms::ZONE)?->addDays(self::MAX_DAYS_AHEAD)->format('Y-m-d');

        // 続けて直す時は今日から（同じ日のまま上限だけ直すのは、発売日を過ぎていても通す）
        if ($continuing ? ($release < $today && $release !== $current) : $release <= $today) {
            throw ValidationException::withMessages(['release_date' => $continuing ? '発売日は今日より前にできません。' : '発売日は明日以降の日にしてください。']);
        }

        if ($latest !== null && $release > $latest) {
            throw ValidationException::withMessages(['release_date' => '発売日は 2 年先までです。']);
        }

        $endsRaw = trim((string) ($values['ends_on'] ?? ''));
        $ends = $endsRaw === '' ? null : self::date($endsRaw);

        if ($endsRaw !== '' && $ends === null) {
            throw ValidationException::withMessages(['ends_on' => '予約の締切日を「2026-11-15」の形で入力してください（空なら発売日の前日まで受け付けます）。']);
        }

        if ($ends !== null && $ends >= $release) {
            throw ValidationException::withMessages(['ends_on' => '予約の締切日は発売日より前の日にしてください。']);
        }

        $capacityRaw = trim((string) ($values['capacity'] ?? ''));
        $capacity = null;

        if ($capacityRaw !== '') {
            if (preg_match('/\A[1-9][0-9]{0,5}\z/', $capacityRaw) !== 1 || (int) $capacityRaw > self::MAX_CAPACITY) {
                throw ValidationException::withMessages(['capacity' => '予約の数の上限は 1〜100,000 の数で入力してください（空なら上限なし）。']);
            }

            $capacity = (int) $capacityRaw;
        }

        return [$release, $ends, $capacity];
    }

    private static function date(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, PreorderTerms::ZONE);

        return $day !== null && $day->format('Y-m-d') === $value ? $value : null;
    }
}
