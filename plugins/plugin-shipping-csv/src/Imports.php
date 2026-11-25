<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use Carbon\CarbonImmutable;
use EcSugar\PluginShippingCsv\Models\ShippingImport;
use EcSugar\PluginShippingCsv\Models\ShippingImportRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 送り状番号の CSV の取り込み。書くのはここだけ。
 *
 * 1. create: ファイルを読んで、行ごとに「発送にする」か「飛ばす（理由）」を決め、確かめ待ちの記録を作る。注文はまだ変えない
 * 2. apply: 店の人が確かめの画面で押すと、発送にする行を核の発送のサービス（SaleOrderService::ship）で 1 つずつ発送にする
 *    （送り状番号と運送会社を付け、発送の知らせのメールも今の仕組みで出る）。押す前に状態が変わった行は飛ばす（理由つき）
 * 3. discard: 取りやめる（注文は変えない）
 *
 * どれも連鎖のロック（EventStore::lock()）の後に記録の行のロックを取り、同じトランザクションでイベントを残す
 * （shipping_csv.imported・shipping_csv.applied・shipping_csv.discarded。中身は件数と運送会社だけで、送り状番号は入れない）。
 * 二重にしない: 1 つの取り込みは 1 回だけ発送にできる（確かめ待ちの時だけ）。同じ箱はファイルの中でも、ほかの取り込みでも、発送済みなら飛ばす
 */
final class Imports
{
    public function __construct(
        private readonly EventStore $events,
        private readonly SaleOrderService $orders,
    ) {}

    /**
     * @throws ValidationException 読めない・行が無い・多すぎる（file）、運送会社が違う
     */
    public function create(string $content, string $carrier, string $actor): string
    {
        if (! array_key_exists($carrier, SaleOrderService::CARRIERS)) {
            throw ValidationException::withMessages(['carrier' => '運送会社を選んでください。']);
        }

        $lines = ImportReader::read($content);

        return DB::transaction(function () use ($content, $carrier, $lines, $actor): string {
            $this->events->lock();
            $rows = [];
            $seen = [];

            foreach ($lines as $line) {
                $rows[] = $this->evaluate($line, $seen);
            }

            $ready = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'ready'));
            $import = ShippingImport::query()->create([
                'carrier' => $carrier,
                'status' => 'pending',
                'file_hash' => hash('sha256', $content),
                'rows_count' => count($rows),
                'ready_count' => $ready,
                'skipped_count' => count($rows) - $ready,
                'created_by' => $actor,
            ]);

            foreach ($rows as $row) {
                ShippingImportRow::query()->create(['import_id' => $import->id] + $row);
            }

            $this->events->append('shipping_csv.imported', 'shipping_csv_import', $import->id, [
                'carrier' => $carrier,
                'rows' => count($rows),
                'ready' => $ready,
            ], $actor);

            return $import->id;
        });
    }

    /**
     * 発送にする行を発送にする。確かめ待ちでなければ断る
     *
     * @throws AdminActionRefused
     * @throws AdminRecordNotFound
     */
    public function apply(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $import = $this->pending($id);
            $shipped = 0;

            foreach ($import->rows()->where('status', 'ready')->orderBy('line')->lockForUpdate()->get() as $row) {
                $reason = $this->ship($row, $import->carrier, $actor);
                $row->forceFill($reason === null ? ['status' => 'shipped'] : ['status' => 'skipped', 'reason' => $reason->value])->save();
                $shipped += $reason === null ? 1 : 0;
            }

            $import->forceFill([
                'status' => 'applied',
                'ready_count' => $shipped,
                'skipped_count' => $import->rows_count - $shipped,
                'shipped_count' => $shipped,
                'decided_by' => $actor,
                'decided_at' => CarbonImmutable::now(),
            ])->save();

            $this->events->append('shipping_csv.applied', 'shipping_csv_import', $import->id, [
                'carrier' => $import->carrier,
                'shipped' => $shipped,
                'skipped' => $import->rows_count - $shipped,
            ], $actor);
        });
    }

    /**
     * 取りやめる（注文は変えない）。確かめ待ちでなければ断る
     */
    public function discard(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $import = $this->pending($id);
            $import->forceFill(['status' => 'discarded', 'decided_by' => $actor, 'decided_at' => CarbonImmutable::now()])->save();
            $this->events->append('shipping_csv.discarded', 'shipping_csv_import', $import->id, ['rows' => $import->rows_count], $actor);
        });
    }

    private function pending(string $id): ShippingImport
    {
        $import = ShippingImport::query()->whereKey(strtolower($id))->lockForUpdate()->first() ?? throw new AdminRecordNotFound;

        if ($import->status !== 'pending') {
            throw new AdminActionRefused('この取り込みは、すでに発送済みにしたか、取りやめています。');
        }

        return $import;
    }

    /**
     * 1 行を核の発送のサービスで発送にする。発送にできなければ理由（押す前に状態が変わった）
     */
    private function ship(ShippingImportRow $row, string $carrier, string $actor): ?RowReason
    {
        $fulfillment = Fulfillment::query()->find((string) $row->fulfillment_id);
        $transaction = $fulfillment === null ? null : Transaction::query()->find($fulfillment->transaction_id);

        if ($fulfillment === null || $transaction === null || $row->tracking_number === null) {
            return RowReason::NotFound;
        }

        // 押す前に変わった状態（ほかの人の発送・取消など）は、核に渡す前に理由にする（お届け先の無い受渡も、核が断る前にここで）
        $before = self::reasonOf($transaction, $fulfillment);

        if ($before !== null) {
            return $before;
        }

        try {
            $this->orders->ship($transaction, $fulfillment->id, $carrier, $row->tracking_number, $actor);

            return null;
        } catch (TransactionException) {
            // 断られた理由を、今の状態から店の人の言葉にする（核の文はそのまま残さない）
            return self::reasonOf(Transaction::query()->findOrFail($transaction->id), Fulfillment::query()->findOrFail($fulfillment->id)) ?? RowReason::NotShippable;
        }
    }

    /**
     * 1 行を読んで、発送にするか飛ばすかを決める（$seen はこのファイルで発送にする箱）
     *
     * @param  array{line: int, reference: ?string, tracking: ?string}  $line
     * @param  array<string, true>  $seen
     * @return array{line: int, reference: ?string, transaction_id: ?string, fulfillment_id: ?string, tracking_number: ?string, status: string, reason: ?string}
     */
    private function evaluate(array $line, array &$seen): array
    {
        $reference = References::normalize($line['reference']);
        $tracking = self::tracking($line['tracking']);
        $skip = static fn (RowReason $reason, ?Transaction $t = null, ?Fulfillment $f = null): array => [
            'line' => $line['line'], 'reference' => $reference, 'transaction_id' => $t?->id, 'fulfillment_id' => $f?->id,
            'tracking_number' => null, 'status' => 'skipped', 'reason' => $reason->value,
        ];

        if ($reference === null) {
            return $skip(RowReason::BadReference);
        }

        if ($line['tracking'] === null) {
            return $skip(RowReason::NoTracking);
        }

        if ($tracking === null) {
            return $skip(RowReason::BadTracking);
        }

        [$transaction, $fulfillment, $problem] = References::resolve($reference);

        if ($transaction === null || $fulfillment === null) {
            return $skip($problem ?? RowReason::NotFound, $transaction);
        }

        $reason = self::reasonOf($transaction, $fulfillment) ?? (isset($seen[$fulfillment->id]) ? RowReason::Duplicate : null);

        if ($reason !== null) {
            return $skip($reason, $transaction, $fulfillment);
        }

        $seen[$fulfillment->id] = true;

        return [
            'line' => $line['line'], 'reference' => $reference, 'transaction_id' => $transaction->id, 'fulfillment_id' => $fulfillment->id,
            'tracking_number' => $tracking, 'status' => 'ready', 'reason' => null,
        ];
    }

    /**
     * 今の状態で発送にできない理由（できるなら null）。注文の画面の発送と同じ決まり（入金の後・取り消していない・受渡が発送の前・お届け先が決まっている・発売日の前でない）
     */
    private static function reasonOf(Transaction $transaction, Fulfillment $fulfillment): ?RowReason
    {
        return match (true) {
            in_array($transaction->status, [TransactionStatus::Cancelled, TransactionStatus::Refunded], true) => RowReason::Cancelled,
            $fulfillment->status === FulfillmentStatus::Cancelled => RowReason::Cancelled,
            in_array($fulfillment->status, [FulfillmentStatus::Shipped, FulfillmentStatus::Completed], true) => RowReason::AlreadyShipped,
            $transaction->phase === Phase::Agreement => RowReason::Unpaid,
            // 代金引換でまだ払われていない注文は、この取り込みでは発送にしない（ADR 0074。送り状が発払いの CSV とまぎれない）
            SaleOrderService::codUnpaid($transaction->id) => RowReason::CashOnDelivery,
            ! in_array($transaction->phase, [Phase::Payment, Phase::Fulfillment], true) => RowReason::NotShippable,
            $fulfillment->awaitingAddress() => RowReason::AwaitingAddress,
            // 予約の品の発売日の前（ADR 0073。核も断るが、下見で理由を見せる）
            $fulfillment->heldForRelease() => RowReason::BeforeRelease,
            default => null,
        };
    }

    /**
     * 送り状番号の形（核の発送と同じ: 英数字とハイフンで 64 文字まで。全角は半角に、空白は除く）。形が違えば null
     */
    private static function tracking(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = (string) preg_replace('/\s+/u', '', mb_convert_kana($raw, 'as'));
        $value = str_replace(['－', 'ー', '‐', '−'], '-', $value);

        return preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $value) === 1 ? $value : null;
    }
}
