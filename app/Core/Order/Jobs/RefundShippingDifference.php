<?php

declare(strict_types=1);

namespace App\Core\Order\Jobs;

use App\Core\Order\Services\ShippingDifferenceRefunds;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * 贈り物の送料の差額を返金する仕事（ADR 0064 の 6）。受け取りのトランザクションの確定の後に出す。キューに載せるのは注文の ID だけ（店の文脈は stancl/tenancy がつなぐ）。
 * 落ちて送り直しても返金は 1 回（同じ記録・同じ冪等キーで続きから）。答えが分からない返金は、記録が残るので返金の見回り（RefundReconciler）も頼み直す。
 * 記録を作る前に止まった（支払いの準備で止まった・この仕事が届かなかった）差額も、返金の見回りが拾い直す（ShippingDifferenceRefunds::stranded）
 */
final class RefundShippingDifference implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(
        public readonly string $transactionId,
        public readonly string $actor,
    ) {}

    public function handle(ShippingDifferenceRefunds $refunds): void
    {
        // 今は頼めない・答えが分からない: 落としてキューにやり直させる（記録の無いまま止まった差額は、やり直しの後も返金の見回りが拾う）
        if ($refunds->refund($this->transactionId, $this->actor) === 'waiting') {
            throw new RuntimeException('送料の差額の返金を今は頼めない（キューのやり直しか返金の見回りが続ける）');
        }
    }
}
