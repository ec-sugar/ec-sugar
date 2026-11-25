<?php

declare(strict_types=1);

namespace EcSugar\PluginRecommendations;

use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 売れ筋ランキングを数える（1 時間ごとの recommendations:rank の中身。ADR 0071）。ショップの画面は数えた結果（recommendations_ranks）を読むだけ。
 *
 * 数えるのは、直近 30 日に受けた物販の注文（kind = sale）のうち、いまお支払いの済んだ段（支払い・受渡・受取確認・精算）にあるものの商品の明細の数。
 * 払われずに消えた注文（合意のまま期限で取り消し）・取消・返金・紛争の注文は数えない。オプションの行（名入れなど）は数えない。上位 50 件だけを持つ。
 * 何度流しても同じ結果。同じ店で 2 つ同時に流れても、店ごとの錠で 1 つずつ入れ替える
 */
final class Rankings
{
    public const int DAYS = 30;

    public const int KEEP = 50;

    /** お支払いの済んだ段 */
    public const array PAID = [Phase::Payment, Phase::Fulfillment, Phase::Acceptance, Phase::Settlement];

    /**
     * 数え直して入れ替える。持った件数を返す
     */
    public function count(CarbonImmutable $now): int
    {
        $rows = DB::table('transaction_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('t.kind', 'sale')
            ->whereIn('t.phase', array_map(static fn (Phase $p): string => $p->value, self::PAID))
            ->whereNotIn('t.status', [TransactionStatus::Cancelled->value, TransactionStatus::Refunded->value])
            ->where('t.created_at', '>=', $now->subDays(self::DAYS))
            ->where('l.line_type', LineType::Item->value)
            ->whereNotNull('l.listing_id')
            ->whereNull('l.parent_position')
            ->groupBy('l.listing_id')
            ->havingRaw('sum(l.quantity) > 0')
            ->orderByRaw('sum(l.quantity) DESC')
            ->orderBy('l.listing_id')
            ->limit(self::KEEP)
            ->get(['l.listing_id', DB::raw('sum(l.quantity) AS units')]);

        DB::transaction(static function () use ($rows, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['recommendations-rank']);
            DB::table('recommendations_ranks')->delete();
            DB::table('recommendations_ranks')->insert($rows->values()->map(static fn (object $row, int $i): array => [
                'listing_id' => (string) $row->listing_id,
                'position' => $i + 1,
                'units' => (int) $row->units,
                'counted_at' => $now,
            ])->all());
        });

        return $rows->count();
    }
}
