<?php

declare(strict_types=1);

namespace App\Core\Payment\Jobs;

use App\Core\Payment\Services\StoredPayments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * 預けたカードを決済代行から外す（ADR 0045 の 1。退会・最後の定期便の回が終わった後・カードを替えた時）。キューに載せるのは ID だけ（店の文脈は stancl/tenancy がつなぐ）。
 * 外すカードは、仕事を出した時に決めた ID だけ（送り直しを待つ間に預けた新しいカードを外さない）。
 * 決済代行に届かなければ間を空けて送り直す（使い切った失敗は壊れたことの記録に残る）
 */
final class DetachStoredCards implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 600, 3600, 21600];

    /**
     * @param  list<string>  $cardIds  外すカード（出した時に決めたもの）
     */
    public function __construct(
        public readonly string $accountId,
        public readonly array $cardIds,
        public readonly string $actor,
    ) {}

    /**
     * そのお客さんの今の使えるカード（$keep のほか）を外す仕事を、確定の後に出す（外すものが無ければ出さない）
     */
    public static function forAccount(string $accountId, ?string $keep, string $actor): void
    {
        $ids = StoredPayments::detachable($accountId, $keep);

        if ($ids !== []) {
            DB::afterCommit(static fn () => self::dispatch($accountId, $ids, $actor));
        }
    }

    public function handle(StoredPayments $stored): void
    {
        $stored->detachCards($this->accountId, $this->cardIds, $this->actor);
    }
}
