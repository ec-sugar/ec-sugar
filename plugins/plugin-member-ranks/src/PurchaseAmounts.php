<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ランクを決める購入額（ADR 0079）: 直近 12 か月に発送した注文の、受け取ったお金 − 返金（台帳の支払いと返金の仕訳の、お客さんの口座の額）。
 *
 * - 数える注文: 物販・円・お客さんが払う向きで、受渡が全部終わり（発送・渡した・取り消した）、少なくとも 1 つを発送か渡した注文。
 *   最後に発送した（渡した）時刻が、日本の暦の今日の 0 時から 12 か月前より後（その時刻ちょうども数える）
 * - 額は台帳から（ADR 0060 と同じ符号: 支払いはお客さんの口座が +、返金は −）。返金はいつ返しても引く（発送の後の返金で下がる）。
 *   ギフトカードで払った分も、お客さんが払ったお金として数える。値引き・使ったポイントは払っていないので入らない
 * - 数えない注文: ギフトカードを売った注文（使った時に数えるので、2 回数えない）と、Stripe のテストのモードで払われた注文
 * - お客さんはまとめた記録の親（まとめた記録の注文も親に足す）
 */
final class PurchaseAmounts
{
    /**
     * 数える期間の始まり（日本の暦の今日の 0 時から 12 か月前）
     */
    public static function since(CarbonImmutable $now): CarbonImmutable
    {
        return $now->timezone('Asia/Tokyo')->startOfDay()->subMonthsNoOverflow(12);
    }

    /**
     * 会員の台帳（まとめた記録の親）ごとの額。$accountIds を渡せばその台帳だけ（数える注文の無い台帳は入らない）
     *
     * @param  list<string>|null  $accountIds
     * @return array<string, int>
     */
    public function of(?array $accountIds, CarbonImmutable $now): array
    {
        if ($accountIds === []) {
            return [];
        }

        $bindings = [self::since($now)->utc()->format('Y-m-d H:i:sP')];
        $only = '';

        if ($accountIds !== null) {
            $only = 'AND COALESCE(a.merged_into_id, a.id) IN ('.implode(', ', array_fill(0, count($accountIds), '?')).')';
            $bindings = [...$bindings, ...$accountIds];
        }

        $rows = DB::select(
            "SELECT COALESCE(a.merged_into_id, a.id) AS account_id, SUM(p.amount)::bigint AS amount
               FROM (SELECT f.transaction_id
                       FROM fulfillments f
                      GROUP BY f.transaction_id
                     HAVING bool_and(f.status <> 'pending') AND bool_or(f.status IN ('shipped', 'completed'))
                        AND MAX(COALESCE(f.shipped_at, f.completed_at)) FILTER (WHERE f.status IN ('shipped', 'completed')) >= ?) s
               JOIN transactions t ON t.id = s.transaction_id
               JOIN customer_accounts a ON a.id = t.customer_account_id
               JOIN ledger_journals j ON j.transaction_id = t.id AND j.type IN ('payment', 'refund')
               JOIN ledger_postings p ON p.transaction_id = j.transaction_id AND p.journal_id = j.id AND p.account = 'customer'
              WHERE t.kind = 'sale' AND t.currency = 'JPY' AND t.payer = 'customer'
                AND NOT EXISTS (SELECT 1 FROM transaction_lines l WHERE l.transaction_id = t.id AND l.kind = 'gift_card')
                AND NOT EXISTS (SELECT 1 FROM payment_attempts x WHERE x.transaction_id = t.id AND x.status = 'paid' AND x.test_mode)
                {$only}
              GROUP BY 1",
            $bindings,
        );

        $amounts = [];
        foreach ($rows as $row) {
            $amounts[(string) $row->account_id] = max(0, (int) $row->amount);
        }

        return $amounts;
    }

    public function forAccount(string $accountId, CarbonImmutable $now): int
    {
        return $this->of([$accountId], $now)[$accountId] ?? 0;
    }
}
