<?php

declare(strict_types=1);

namespace App\Storefront\Console;

use App\Core\Order\Services\RefundReconciler;
use App\Core\Order\Services\ShippingDifferenceRefunds;
use App\Core\Payment\Models\Refund;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * 途中の返金を確かめ直す（ADR 0027）。店ごとに、10 分ごと（routes/console.php）。中身は RefundReconciler。
 * 返金の記録を作る前に止まった送料の差額（ADR 0064 の 6）も拾い直す
 */
final class ReconcileRefundsCommand extends Command
{
    protected $signature = 'storefront:reconcile-refunds';

    protected $description = '途中の返金を確認し直す（応答が分からなかった返金を再依頼、保留の返金を問い合わせる）';

    public function handle(): int
    {
        $counts = [];
        $now = CarbonImmutable::now();

        // 店の DB を作り直している最中の店には入らない（Tenant::serving）
        tenancy()->runForMultiple(Tenant::serving(), function () use (&$counts, $now): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                // 止めている返金（held）は自動で進めない。数だけ数えて人に知らせる
                $counts['held'] = ($counts['held'] ?? 0) + Refund::query()->where('status', 'held')->count();

                // 確かめた時刻が古い順（まだのものが先）。決まらなかった返金は後ろへ回り、溜まっても後ろの返金を塞がない
                $refunds = Refund::query()->whereIn('status', ['requested', 'pending'])
                    ->orderByRaw('checked_at ASC NULLS FIRST')->orderBy('created_at')->limit((int) config('payment.refund_reconcile_batch'))->get();

                foreach ($refunds as $refund) {
                    // 1 件が落ちても、ほかの返金とほかの店は続ける。落ちた返金も印を付けて後ろへ回す
                    try {
                        $outcome = app(RefundReconciler::class)->reconcile($refund, $now);
                    } catch (Throwable $e) {
                        report($e);
                        $outcome = 'error';
                    } finally {
                        // 見回りの順番のためだけの印（返金の状態は変えない。conventions の「書き込みの決まり」の外）
                        Refund::query()->whereKey($refund->id)->toBase()->update(['checked_at' => CarbonImmutable::now()]);
                    }
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                }

                // 返金の記録を作る前に止まった送料の差額（ADR 0064 の 6。キューの仕事が届かなかった・支払いの準備で止まった）を拾い直す。古い順に決まった数まで
                foreach (ShippingDifferenceRefunds::stranded($now, (int) config('payment.refund_reconcile_batch')) as $transactionId) {
                    try {
                        $outcome = app(RefundReconciler::class)->recoverShipping($transactionId);
                    } catch (Throwable $e) {
                        report($e);
                        $outcome = 'error';
                    }
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                }
            } catch (Throwable $e) {
                report($e);
                $counts['error'] = ($counts['error'] ?? 0) + 1;
            }
        });

        $this->info('再依頼 '.($counts['resumed'] ?? 0).' 件、確定 '.($counts['settled'] ?? 0).' 件、断られた '.($counts['declined'] ?? 0).' 件、次の回へ '.(($counts['waiting'] ?? 0) + ($counts['not_yet'] ?? 0)).' 件、人が見る '.(($counts['no_account'] ?? 0) + ($counts['closed'] ?? 0)).' 件、額が違って止めている '.($counts['held'] ?? 0).' 件、失敗 '.($counts['error'] ?? 0).' 件');

        return self::SUCCESS;
    }
}
