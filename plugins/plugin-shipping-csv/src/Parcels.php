<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Models\FulfillmentItem;
use App\Core\Order\AgeChecks;
use App\Core\Order\OrderCharges;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 発送を待つ箱（書き出しの対象）。物販の注文で、入金の後（入金済み・確定。一部を発送した注文の残りの箱も）、
 * 配送の受渡がまだ発送の前で、届け先のあるもの。古い順に MAX 個まで。
 * 代金引換でまだ払われていない注文（ADR 0074）は入れない（この CSV の送り状は発払いで、代金を集められない。代引きの送り状は運送会社の画面で作る）。
 * 発売日の前の予約の品の受渡（発送を始める日が今日より後。ADR 0073）も入れない
 */
final class Parcels
{
    public const int MAX = 1000;

    /**
     * 発送を待つ箱の数（ホームのやること・一覧の案内に）
     */
    public static function waiting(bool $onlyNew): int
    {
        return self::query($onlyNew)->count();
    }

    /**
     * 書き出しから外した、代金引換でまだ払われていない発送待ちの注文の数（画面で「運送会社の画面で代引きの送り状を」と案内する）
     */
    public static function cashOnDelivery(): int
    {
        return self::base()->whereExists(self::codUnpaid(...))->distinct()->count('fulfillments.transaction_id');
    }

    /**
     * @param  'new'|'all'|'numbers'  $target  まだ書き出していない箱・全部・$references の箱だけ
     * @param  list<string>  $references  注文番号（References::normalize の後）
     * @return array{0: list<Parcel>, 1: list<string>} 箱と、発送を待つ箱に見つからなかった注文番号
     */
    public static function pick(string $target, array $references): array
    {
        $query = self::query($target === 'new');
        // 注文番号ごとの、書き出してよい箱の候補（末尾の数があればその箱、番号だけならその注文の配送の箱を全部）
        $candidates = [];

        if ($target === 'numbers') {
            foreach ($references as $reference) {
                [$transaction, $fulfillment] = References::resolve($reference);
                $candidates[$reference] = match (true) {
                    $fulfillment !== null => [$fulfillment->id],
                    $transaction !== null && ! str_contains($reference, '-') => References::shippingIds($transaction->id),
                    default => [],
                };
            }

            $query->whereKey(array_values(array_unique(array_merge([], ...array_values($candidates)))));
        }

        /** @var list<Fulfillment> $fulfillments */
        $fulfillments = $query->orderBy('fulfillments.created_at')->orderBy('fulfillments.id')->limit(self::MAX)->get()->all();

        // お届け先がまだ無い受渡（贈り物の受け取り待ち。ADR 0055）と、届け先の名前のない受渡は書き出さない（送り状を作れない）
        $fulfillments = array_values(array_filter($fulfillments, static fn (Fulfillment $f): bool => ! $f->awaitingAddress() && ($f->address['name'] ?? '') !== ''));
        $kept = array_map(static fn (Fulfillment $f): string => $f->id, $fulfillments);
        $unknown = array_keys(array_filter($candidates, static fn (array $ids): bool => array_intersect($ids, $kept) === []));

        return [self::parcels($fulfillments), array_map('strval', $unknown)];
    }

    /**
     * @return Builder<Fulfillment>
     */
    private static function query(bool $onlyNew): Builder
    {
        return self::base()
            ->whereNotExists(self::codUnpaid(...))
            ->when($onlyNew, static fn (Builder $q) => $q->whereNotExists(
                static fn ($marks) => $marks->from('shipping_csv_exported')->whereColumn('shipping_csv_exported.fulfillment_id', 'fulfillments.id'),
            ));
    }

    /**
     * 代金引換でまだ払われていない注文（SaleOrderService::codUnpaid と同じ決まり。whereExists・whereNotExists に渡す）
     */
    private static function codUnpaid(\Illuminate\Database\Query\Builder $attempts): void
    {
        $attempts->from('payment_attempts')->whereColumn('payment_attempts.transaction_id', 'fulfillments.transaction_id')
            ->where('payment_attempts.kind', 'direct')->where('payment_attempts.method', 'cod')->where('payment_attempts.status', 'awaiting');
    }

    /**
     * @return Builder<Fulfillment>
     */
    private static function base(): Builder
    {
        return Fulfillment::query()
            ->select('fulfillments.*')
            ->join('transactions', 'transactions.id', '=', 'fulfillments.transaction_id')
            ->where('transactions.kind', 'sale')
            ->where('transactions.status', TransactionStatus::Open->value)
            ->whereIn('transactions.phase', [Phase::Payment->value, Phase::Fulfillment->value])
            ->where('fulfillments.method', FulfillmentMethod::Shipping->value)
            ->where('fulfillments.status', FulfillmentStatus::Pending->value)
            ->whereNotNull('fulfillments.address')
            // 発送を始める日（予約の品の発売日。ADR 0073）の前の受渡は、まだ送れないので送り状を作らない
            ->where(static fn (Builder $q) => $q->whereNull('fulfillments.ships_on')->orWhere('fulfillments.ships_on', '<=', PreorderTerms::today()));
    }

    /**
     * @param  list<Fulfillment>  $fulfillments
     * @return list<Parcel>
     */
    private static function parcels(array $fulfillments): array
    {
        if ($fulfillments === []) {
            return [];
        }

        $transactionIds = array_values(array_unique(array_map(static fn (Fulfillment $f): string => $f->transaction_id, $fulfillments)));
        $shipping = [];

        foreach (Fulfillment::query()->whereIn('transaction_id', $transactionIds)->where('method', FulfillmentMethod::Shipping->value)
            ->orderBy('created_at')->orderBy('id')->get(['id', 'transaction_id']) as $row) {
            $shipping[$row->transaction_id][] = $row->id;
        }

        $items = [];

        foreach (FulfillmentItem::query()
            ->join('transaction_lines', 'transaction_lines.id', '=', 'fulfillment_items.transaction_line_id')
            ->whereIn('fulfillment_items.fulfillment_id', array_map(static fn (Fulfillment $f): string => $f->id, $fulfillments))
            ->orderBy('transaction_lines.position')
            ->get([DB::raw('fulfillment_items.fulfillment_id AS fulfillment_id'), 'transaction_lines.description', 'fulfillment_items.quantity']) as $item) {
            $items[(string) $item->getAttribute('fulfillment_id')][] = ['name' => (string) $item->getAttribute('description'), 'quantity' => (int) $item->getAttribute('quantity')];
        }

        // 年齢の確かめをした注文（核の記録。ADR 0077）
        $ages = app(AgeChecks::class)->verifiedFor($transactionIds);

        // 備考: App が注文に足した料金の内容（ラッピング・のしと名入れ。ADR 0078。注文ごとに 1 回だけ読む）
        $charges = app(OrderCharges::class);
        $notes = [];
        foreach ($transactionIds as $transactionId) {
            $notes[$transactionId] = OrderCharges::line($charges->notes($transactionId, private: true));
        }

        return array_map(static function (Fulfillment $f) use ($shipping, $items, $ages, $notes): Parcel {
            /** @var array<string, mixed> $raw */
            $raw = $f->address ?? [];
            $address = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $raw);

            return new Parcel(References::of($f->transaction_id, $shipping[$f->transaction_id] ?? [], $f->id), $f->transaction_id, $f->id, $address, $items[$f->id] ?? [], $f->delivery(), $ages[$f->transaction_id]['age'] ?? null, $notes[$f->transaction_id] ?? '');
        }, $fulfillments);
    }
}
