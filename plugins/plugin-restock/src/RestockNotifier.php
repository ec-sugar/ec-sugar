<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock;

use App\Core\Plugin\Services\PluginStates;
use Carbon\CarbonImmutable;
use EcSugar\PluginRestock\Jobs\SendRestockMail;
use EcSugar\PluginRestock\Models\RestockRequest;
use Illuminate\Support\Facades\DB;

/**
 * 入荷したら知らせる（使っている店だけ）。種類がいまカートに入れられるなら、入荷を待っている登録を古い順に「知らせた」にしてから、1 通ずつキューで送る。
 *
 * - 一度に送る数は絞らない（待っている人の全部に送る）。在庫の数で絞ると、残りの人は次に売り切れから戻るまで知らせが来ないため
 *   （在庫が残っている間は「0 から戻る」が起きない）。知らせは 1 回だけで、先に買えるかは早い者勝ち（メールにもそう書く）
 * - 2 回送らない: 「待っている → 知らせた」は 1 文の条件つきの更新で（FOR UPDATE SKIP LOCKED）。同じ時に 2 つの処理が動いても、
 *   1 つの登録を取れるのはどちらか 1 つだけ。取った登録にだけメールの仕事を積む
 */
final class RestockNotifier
{
    /** 1 回の更新で取る数（多い時はくり返す） */
    public const int BATCH = 200;

    /** 定期の見直しで 1 店あたりに見る種類の数 */
    public const int SWEEP_VARIANTS = 1000;

    public function __construct(
        private readonly RestockTargets $targets,
        private readonly PluginStates $states,
    ) {}

    /**
     * 種類が入荷していれば、待っている登録に知らせる。知らせた数を返す
     */
    public function notify(string $variantId): int
    {
        if (! $this->states->enabled('restock') || ! $this->targets->available($variantId)) {
            return 0;
        }

        $total = 0;

        do {
            $ids = $this->claim($variantId, self::BATCH, CarbonImmutable::now());

            foreach ($ids as $id) {
                SendRestockMail::dispatch($id, 'restocked');
            }

            $total += count($ids);
        } while (count($ids) === self::BATCH);

        return $total;
    }

    /**
     * 定期の見直し: 入荷を待っている登録のある種類を見て、入荷していれば知らせる（在庫の操作の知らせを取りこぼした時・商品を公開し直した時の分）
     */
    public function sweep(): int
    {
        $variants = RestockRequest::query()
            ->where('status', RestockStatus::Waiting->value)
            ->distinct()
            ->orderBy('variant_id')
            ->limit(self::SWEEP_VARIANTS)
            ->pluck('variant_id');
        $total = 0;

        foreach ($variants as $variantId) {
            $total += $this->notify((string) $variantId);
        }

        return $total;
    }

    /**
     * 待っている登録を古い順に $limit 件まで「知らせた」にして、その ID を返す。ほかの処理が押さえている行は飛ばす（その処理が送る）
     *
     * @return list<string>
     */
    public function claim(string $variantId, int $limit, CarbonImmutable $now): array
    {
        $rows = DB::select(
            "UPDATE restock_requests SET status = 'notified', notified_at = ?, updated_at = ?
             WHERE status = 'waiting' AND id IN (
                 SELECT id FROM restock_requests WHERE variant_id = ? AND status = 'waiting'
                 ORDER BY confirmed_at, id LIMIT ? FOR UPDATE SKIP LOCKED
             )
             RETURNING id",
            [$now, $now, $variantId, $limit],
        );

        return array_values(array_map(static fn (object $row): string => (string) $row->id, $rows));
    }
}
