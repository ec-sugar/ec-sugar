<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Listing\Models\Listing;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ダウンロードのリンク（注文 × ファイル。ADR 0084）を作る・数える・店の人が直す。
 *
 * - 作る: 払いが確かになった知らせ（PaymentConfirmed。確定の書き込みの後に届く）から。注文の「ダウンロード」の商品の、その時に置いてあるファイルごとに 1 つ
 *   （注文 × ファイルで一意。何回知らせが来ても 1 回）。期限と回数は商品の値か App の設定を写す。同じトランザクションで digital の受渡を渡し終える
 *   （核の SaleOrderService::deliverDigitalItems。物と一緒の注文は digital の受渡だけ。発送の知らせは送らない）。払う前・取り消した・取消の返金が途中の注文には作らない
 * - 数える: 押すたびに連鎖のロック → リンクの行のロックの中で、止めていない・期限の前・回数が残る・注文が閉じていない・ファイルを取り下げていないを確かめてから 1 足す
 *   （同時に押しても上限を越えない。回数は DB の制約でも 0〜上限）
 * - 直す（店の人）: 回数を戻す・期限を延ばす・止める・再開する。どれも記録に残す（ID と数だけ）
 * - 止める（仕組み）: 注文の取消・返金（取引の段が cancel・refund に変わった記録）で、その注文のリンクを全部止める
 *
 * 記録（download.*）に名前・メール・ファイルの名前・接続元は入れない
 */
final class DownloadGrants
{
    public const string ACTOR = 'system:downloads';

    /** 店の人が延ばす日数 */
    public const int EXTEND_DAYS = 30;

    public function __construct(
        private readonly EventStore $events,
        private readonly SaleOrderService $orders,
        private readonly PaymentRefunds $refunds,
        private readonly DownloadSettings $settings,
    ) {}

    /**
     * 払われた注文に「ダウンロード」の商品があれば、リンクを作って digital の受渡を渡し終える。新しくリンクを作ったら true（メールを送る）
     */
    public function paid(string $transactionId): bool
    {
        $lines = self::downloadLines($transactionId);

        if ($lines === []) {
            return false;
        }

        return DB::transaction(function () use ($transactionId, $lines): bool {
            $this->events->lock();
            $transaction = Transaction::query()->whereKey($transactionId)->lockForUpdate()->first();

            // 払われた・開いている注文だけ（払う前・取り消した・返金した・取消の返金が途中の注文・代引きのお金が届く前には作らない）
            if ($transaction === null || $transaction->kind !== 'sale' || $transaction->status !== TransactionStatus::Open
                || ! in_array($transaction->phase, [Phase::Payment, Phase::Fulfillment], true)
                || $this->refunds->activeCancel($transaction->id) !== null || SaleOrderService::codUnpaid($transaction->id)) {
                return false;
            }

            $created = 0;
            $waiting = 0;
            $now = CarbonImmutable::now();

            // 商品ごとに、まだリンクの無い商品だけ作る（払った時にファイルが無かった商品は、ファイルを置き直した後の見回りで作る）
            foreach ($lines as $listingId) {
                if (self::granted($transaction->id, $listingId)) {
                    continue;
                }

                $terms = $this->settings->forListing($listingId);
                $files = DownloadFile::query()->where('listing_id', $listingId)->whereNull('removed_at')->orderBy('created_at')->orderBy('id')->get();

                foreach ($files as $file) {
                    DownloadGrant::create(['transaction_id' => $transaction->id, 'file_id' => $file->id, 'count' => 0, 'max_count' => $terms['limit'], 'expires_at' => $now->addDays($terms['days'])]);
                    $created++;
                }

                if ($files->isEmpty()) {
                    $waiting++;
                    Log::warning('ファイルの無いダウンロードの商品の注文（ファイルを置くまで渡さない）', ['listing' => $listingId, 'order' => $transaction->id]);
                }
            }

            if ($created > 0) {
                $this->events->append('download.granted', 'transaction', $transaction->id, ['files' => $created], self::ACTOR);
            }

            // 全部の商品のリンクがそろった時だけ渡し終える（ファイルが無くて渡せない商品があれば、受渡は済みにしない。App の画面の「ファイル待ち」に出る）
            if ($waiting === 0) {
                $this->orders->deliverDigitalItems($transaction, self::ACTOR);
            }

            return $created > 0;
        });
    }

    /**
     * 押した: 確かめてから回数を 1 足し、渡すファイルを返す
     *
     * @return array{0: DownloadGrant, 1: DownloadFile}
     *
     * @throws DownloadRefused
     */
    public function fetch(string $grantId, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($grantId, $now): array {
            $this->events->lock();
            $grant = DownloadGrant::query()->whereKey($grantId)->lockForUpdate()->first() ?? throw new DownloadRefused('このファイルは見つかりませんでした。');
            $this->assertServable($grant, $now);
            $file = DownloadFile::query()->findOrFail($grant->file_id);

            // 足すのは 1 文の条件つきの更新で（錠の順番が崩れても、読んだ後にほかの要求が足した分を上書きしない。上限・止めた・期限も文の中で確かめ直す）
            $updated = DownloadGrant::query()->whereKey($grant->id)->whereColumn('count', '<', 'max_count')->whereNull('stopped_at')->where('expires_at', '>', $now)
                ->update(['count' => DB::raw('count + 1'), 'last_downloaded_at' => $now, 'updated_at' => $now]);

            if ($updated !== 1) {
                throw new DownloadRefused('ダウンロードの回数（'.$grant->max_count.' 回）を使い切りました。お店へお問い合わせください。');
            }

            $grant->refresh();
            $this->events->append('download.fetched', 'transaction', $grant->transaction_id, ['grant' => $grant->id, 'file' => $grant->file_id, 'count' => $grant->count], 'storefront');

            return [$grant, $file];
        });
    }

    /**
     * 渡してよいか（数えずに。短い期限の URL でファイルを渡す時にも確かめ直す）
     *
     * @throws DownloadRefused
     */
    public function assertServable(DownloadGrant $grant, CarbonImmutable $now, bool $counting = true): void
    {
        $transaction = Transaction::query()->find($grant->transaction_id);

        if ($transaction === null || $transaction->status->isClosed() || in_array($transaction->phase, [Phase::Cancel, Phase::Refund], true)) {
            throw new DownloadRefused('ご注文が取り消されたため、ダウンロードできません。');
        }

        if (DownloadFile::query()->whereKey($grant->file_id)->whereNotNull('purged_at')->exists()) {
            throw new DownloadRefused('このファイルはお店が取り下げました。お店へお問い合わせください。');
        }

        // 渡す時（数えた後）は、使い切ったちょうどの回も通す（数えた 1 回分を渡す）
        $refusal = $counting ? $grant->refusal($now) : ($grant->stopped_at !== null || $grant->expires_at->lessThanOrEqualTo($now) ? $grant->refusal($now) ?? 'ダウンロードできません。' : null);

        if ($refusal !== null) {
            throw new DownloadRefused($refusal);
        }
    }

    /**
     * 店の人: 注文のリンクの回数を 0 に戻す（止めた・期限の過ぎたリンクも数だけ戻す）。戻したリンクの数
     */
    public function reset(string $transactionId, string $actor): int
    {
        return $this->change($transactionId, 'download.reset', $actor, static function (DownloadGrant $grant): bool {
            if ($grant->count === 0) {
                return false;
            }

            $grant->count = 0;

            return true;
        });
    }

    /**
     * 店の人: 期限を延ばす（今と今の期限の遅い方から EXTEND_DAYS 日）。延ばしたリンクの数
     */
    public function extend(string $transactionId, string $actor, CarbonImmutable $now): int
    {
        return $this->change($transactionId, 'download.extended', $actor, static function (DownloadGrant $grant) use ($now): bool {
            $grant->expires_at = ($grant->expires_at->greaterThan($now) ? $grant->expires_at : $now)->addDays(self::EXTEND_DAYS);

            return true;
        }, ['days' => self::EXTEND_DAYS]);
    }

    /**
     * 店の人: 止める（盗まれたカードでの注文・お客さんの申し出など）
     */
    public function stop(string $transactionId, string $actor, CarbonImmutable $now): int
    {
        return $this->change($transactionId, 'download.stopped', $actor, static function (DownloadGrant $grant) use ($now): bool {
            if ($grant->stopped_at !== null) {
                return false;
            }

            $grant->stopped_at = $now;
            $grant->stop_reason = 'staff';

            return true;
        }, ['reason' => 'staff']);
    }

    /**
     * 店の人: 店の人が止めたリンクを再開する（注文の取消・返金で止めたリンクは戻さない。注文が閉じていれば何もしない）
     */
    public function resume(string $transactionId, string $actor): int
    {
        return $this->change($transactionId, 'download.resumed', $actor, static function (DownloadGrant $grant): bool {
            if ($grant->stop_reason !== 'staff') {
                return false;
            }

            $grant->stopped_at = null;
            $grant->stop_reason = null;

            return true;
        }, [], requireOpen: true);
    }

    /**
     * 仕組み: 注文の取消・返金で、その注文のリンクを全部止める（店の人が止めていたものも理由を取消・返金にする。再開できないように）
     */
    public function stopOrder(string $transactionId, string $reason, CarbonImmutable $now): int
    {
        return $this->change($transactionId, 'download.stopped', self::ACTOR, static function (DownloadGrant $grant) use ($reason, $now): bool {
            if ($grant->stop_reason === $reason || $grant->stop_reason === 'refund') {
                return false;
            }

            $grant->stopped_at ??= $now;
            $grant->stop_reason = $reason;

            return true;
        }, ['reason' => $reason]);
    }

    /**
     * 仕組み: 返金が確かになった（RefundSettled。返品・一部返金・外でした返金の記録・銀行振込と代引きの返金の記録・取消）。
     * 受け取った額を全部返したら注文のリンクを全部止める。一部なら、返した行（商品の行の位置）にダウンロードの商品があれば、その商品のリンクだけ止める。
     * 金額だけの一部返金（行の無い返金）では止めない（店の人が App の画面で止める）
     *
     * @param  list<array{kind: string, position: int|null, quantity: int, amount: int}>  $lines
     */
    public function refunded(string $transactionId, bool $full, string $purpose, array $lines, CarbonImmutable $now): int
    {
        $reason = $purpose === 'cancel' ? 'cancel' : 'refund';

        if ($full) {
            return $this->stopOrder($transactionId, $reason, $now);
        }

        $positions = array_values(array_filter(array_map(static fn (array $l): ?int => $l['kind'] === 'item' ? $l['position'] : null, $lines), static fn (?int $p): bool => $p !== null));
        $listings = $positions === [] ? [] : TransactionLine::query()->where('transaction_id', $transactionId)->where('line_type', LineType::Item)
            ->whereIn('position', $positions)->pluck('listing_id')->filter()->all();
        $files = $listings === [] ? [] : DownloadFile::query()->whereIn('listing_id', $listings)->pluck('id')->all();

        if ($files === []) {
            return 0;
        }

        return $this->change($transactionId, 'download.stopped', self::ACTOR, static function (DownloadGrant $grant) use ($files, $now): bool {
            if (! in_array($grant->file_id, $files, true) || $grant->stop_reason === 'refund' || $grant->stop_reason === 'cancel') {
                return false;
            }

            $grant->stopped_at ??= $now;
            $grant->stop_reason = 'refund';

            return true;
        }, ['reason' => 'refund']);
    }

    /**
     * 見回り（10 分ごと）: 払われたのに渡し終えていない「ダウンロード」の注文（リンク作りが落ちた・ファイルが無くて待っていた）に作り直す
     *
     * @return list<string> 新しくリンクを作った注文
     */
    public function catchUp(): array
    {
        $done = [];

        foreach (self::waiting()->limit(200)->pluck('id') as $transactionId) {
            if ($this->paid((string) $transactionId)) {
                $done[] = (string) $transactionId;
            }
        }

        return $done;
    }

    /**
     * 払われたのに渡し終えていない「ダウンロード」の注文（まだ渡していない digital の受渡がある、開いた・払われた注文。App の画面の「ファイル待ち」）
     *
     * @return Builder<Transaction>
     */
    public static function waiting(): Builder
    {
        return Transaction::query()->where('kind', 'sale')->where('status', TransactionStatus::Open)->whereIn('phase', [Phase::Payment, Phase::Fulfillment])
            ->whereIn('id', TransactionLine::query()->select('transaction_id')->where('line_type', LineType::Item)
                ->whereIn('listing_id', Listing::query()->select('id')->where('kind', DownloadListingPanel::KIND)))
            ->whereIn('id', Fulfillment::query()->select('transaction_id')->where('method', FulfillmentMethod::Digital)->where('status', FulfillmentStatus::Pending))
            ->whereNotIn('id', PaymentAttempt::query()->select('transaction_id')->where('kind', 'direct')->where('method', 'cod')->where('status', 'awaiting'));
    }

    /**
     * その注文に、その商品のリンクがもうあるか
     */
    private static function granted(string $transactionId, string $listingId): bool
    {
        return DownloadGrant::query()->where('transaction_id', $transactionId)
            ->whereIn('file_id', DownloadFile::query()->select('id')->where('listing_id', $listingId))->exists();
    }

    /**
     * 注文の「ダウンロード」の商品（商品の行の商品 ID。重なりなし）
     *
     * @return list<string>
     */
    public static function downloadLines(string $transactionId): array
    {
        return TransactionLine::query()->where('transaction_id', $transactionId)->where('line_type', LineType::Item)
            ->whereIn('listing_id', Listing::query()->select('id')->where('kind', DownloadListingPanel::KIND))
            ->orderBy('position')->pluck('listing_id')->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();
    }

    /**
     * 注文のリンクを錠の中で 1 つずつ直し、変えたものがあれば記録を 1 つ残す
     *
     * @param  callable(DownloadGrant): bool  $apply  変えたら true
     * @param  array<string, int|string>  $extra
     */
    private function change(string $transactionId, string $event, string $actor, callable $apply, array $extra = [], bool $requireOpen = false): int
    {
        return DB::transaction(function () use ($transactionId, $event, $actor, $apply, $extra, $requireOpen): int {
            $this->events->lock();

            if ($requireOpen) {
                $transaction = Transaction::query()->find($transactionId);

                if ($transaction === null || $transaction->status->isClosed() || in_array($transaction->phase, [Phase::Cancel, Phase::Refund], true)) {
                    return 0;
                }
            }

            $changed = 0;

            foreach (DownloadGrant::query()->where('transaction_id', $transactionId)->orderBy('id')->lockForUpdate()->get() as $grant) {
                if ($apply($grant)) {
                    $grant->save();
                    $changed++;
                }
            }

            if ($changed > 0) {
                $this->events->append($event, 'transaction', $transactionId, ['grants' => $changed] + $extra, $actor);
            }

            return $changed;
        });
    }
}
