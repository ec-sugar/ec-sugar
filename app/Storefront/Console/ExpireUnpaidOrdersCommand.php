<?php

declare(strict_types=1);

namespace App\Storefront\Console;

use App\Core\Order\Services\DirectPayments;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Checkout\UnpaidOrderLimits;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 店頭の注文で、払われないまま支払いの画面の期限と猶予が過ぎたものを取り消し、在庫を戻す。店ごとに、5 分ごと（routes/console.php）。
 * 対象は店頭で確定した注文だけ（管理画面などで作った取引には触らない）。取り消す前に Stripe へ今の状態を問い合わせる（PaymentService）。
 * コンビニ払いの入金待ち（ADR 0058）は、払いの期限と猶予が過ぎるまで問い合わせずに待ち、過ぎたら払込票を Stripe で取り消してから取り消す。
 * 銀行振込の入金待ち（ADR 0074）は、払う期限と同じ猶予が過ぎたら取り消す（在庫・クーポン・ポイントを戻し、お客さんへ知らせる）。
 * 注文の接続元・メールの印（ADR 0085）は、入金待ちとして数える日数（払える期限のいちばん長いもの ＋ 1 日 ＋ 猶予）を過ぎたら消す
 */
final class ExpireUnpaidOrdersCommand extends Command
{
    /** 預けたカードの注文を見回りで取り消すまでの日数（回の処理の本人確認 7 日・試し直し 5 日より長く） */
    private const int STORED_STALE_DAYS = 14;

    /** 払われた注文に残った払込票を見回る日数（コンビニ払いの払う期限のいちばん長い 60 日） */
    private const int LEFTOVER_DAYS = 61;

    protected $signature = 'storefront:expire-unpaid-orders';

    protected $description = '払われないまま期限の過ぎたショップの注文を取り消し、在庫を戻す';

    public function handle(): int
    {
        $counts = [];
        $now = CarbonImmutable::now();
        $cutoff = $now->subMinutes((int) config('payment.expiry_grace_minutes'));
        // 支払いの画面を作れなかった注文（Stripe に届かず、お客さんが離れた）も、画面の期限と同じだけ待ってから
        $placedBefore = $cutoff->subMinutes((int) config('payment.checkout_minutes'));

        // 店の DB を作り直している最中の店には入らない（Tenant::serving）
        tenancy()->runForMultiple(Tenant::serving(), function () use (&$counts, $cutoff, $placedBefore, $now): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                // 確かめた時刻が古い順（まだのものが先）。決まらなかった注文は後ろへ回り、溜まっても後ろの注文を塞がない
                $orders = Transaction::query()
                    ->join('checkout_orders', 'checkout_orders.transaction_id', '=', 'transactions.id')
                    ->where('transactions.status', TransactionStatus::Open)->where('transactions.phase', Phase::Agreement)
                    ->where('transactions.created_at', '<', $placedBefore)
                    // 預けたカードで請求する注文（定期便の回）は、その仕組みが待つ・取り消す（本人確認は 7 日。ADR 0045 の 4）。
                    // 請求の記録の有無では見ない（カードが無くて請求の前に止まった回の注文を、ここで取り消してしまう）
                    ->where('checkout_orders.pays_with', 'checkout')
                    ->orderByRaw('checkout_orders.expiry_checked_at ASC NULLS FIRST')->orderBy('transactions.created_at')
                    ->limit((int) config('payment.expiry_batch'))->select('transactions.*')->get();

                foreach ($orders as $order) {
                    // 1 件が落ちても、ほかの注文とほかの店は続ける。落ちた注文も印を付けて後ろへ回す（先頭で毎回落ちて後ろを塞がない）
                    try {
                        $outcome = app(PaymentService::class)->expireIfUnpaid($order, $cutoff);
                    } catch (Throwable $e) {
                        report($e);
                        $outcome = 'error';
                    } finally {
                        // 見回りの順番のためだけの印（取引の状態は変えない。conventions の「書き込みの決まり」の外）
                        CheckoutOrder::query()->where('transaction_id', $order->id)->update(['expiry_checked_at' => CarbonImmutable::now()]);
                    }
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                }

                $this->expireStaleStored($counts);
                $this->expireBankTransfers($counts, $now);
                $this->closeLeftoversOfPaid($counts, $now);
                // 注文の接続元・メールの印（ADR 0085）は、入金待ちとして数える日数を過ぎたら消す（持ち続けない。状態でない印を 1 文で）
                CheckoutOrder::query()->where('created_at', '<', $now->subSeconds(UnpaidOrderLimits::markSeconds()))
                    ->where(static fn ($q) => $q->whereNotNull('source_index')->orWhereNotNull('source_wide_index')->orWhereNotNull('email_mark'))
                    ->update(['source_index' => null, 'source_wide_index' => null, 'email_mark' => null]);
            } catch (Throwable $e) {
                report($e);
                $counts['error'] = ($counts['error'] ?? 0) + 1;
            }
        });

        $this->info('払われた注文に残った支払いの画面を閉じた '.($counts['leftover_closed'] ?? 0).' 件');
        $this->info('銀行振込の期限切れの取り消し '.($counts['cancelled_transfer'] ?? 0).' 件');
        $this->info('預けたカードの注文の取り消し '.($counts['cancelled_stored'] ?? 0).' 件、確認が要る '.($counts['stored_waiting'] ?? 0).' 件');
        $this->info('取り消し '.($counts['cancelled'] ?? 0).' 件、支払いを確定 '.($counts['confirmed'] ?? 0).' 件、次の回へ '.(($counts['skipped'] ?? 0) + ($counts['waiting'] ?? 0)).' 件、失敗 '.($counts['error'] ?? 0).' 件');

        return self::SUCCESS;
    }

    /**
     * 払われた注文に残った、ほかの支払いの画面と入金待ちの払込票を閉じる（払われた知らせの後に決済代行へ届かなかった分。PaymentService::closeLeftoversOfPaid）。
     * 払込票の期限（いちばん長い 60 日）の間の注文だけを見る。
     * 確かめた時刻が古い順（まだのものが先）に見て、閉じられなかったもの（店の Stripe の鍵が無い・レジで処理中のまま・落ちた）も印を付けて後ろへ回す
     * （1 回に見る数に上限があるので、並びが無いと閉じられないものが先頭を塞ぎ続ける）
     *
     * @param  array<string, int>  $counts
     */
    private function closeLeftoversOfPaid(array &$counts, CarbonImmutable $now): void
    {
        $transactions = PaymentAttempt::query()->where('kind', 'checkout')->whereIn('status', ['open', 'awaiting'])
            ->where('created_at', '>', $now->subDays(self::LEFTOVER_DAYS))
            ->whereExists(static fn ($q) => $q->from('payment_attempts as paid')->whereColumn('paid.transaction_id', 'payment_attempts.transaction_id')->where('paid.status', 'paid'))
            ->groupBy('transaction_id')
            ->orderByRaw("MIN(COALESCE(leftover_checked_at, '-infinity'::timestamptz)) ASC")->orderByRaw('MIN(created_at) ASC')
            ->limit((int) config('payment.expiry_batch'))->pluck('transaction_id');

        foreach ($transactions as $transactionId) {
            try {
                $counts['leftover_closed'] = ($counts['leftover_closed'] ?? 0) + app(PaymentService::class)->closeLeftoversOfPaid((string) $transactionId);
            } catch (Throwable $e) {
                report($e);
                $counts['error'] = ($counts['error'] ?? 0) + 1;
            } finally {
                // 見回りの順番のためだけの印（支払いの状態は変えない。updated_at も動かさない。conventions の「書き込みの決まり」の例外）
                PaymentAttempt::query()->where('transaction_id', (string) $transactionId)->where('kind', 'checkout')->whereIn('status', ['open', 'awaiting'])
                    ->toBase()->update(['leftover_checked_at' => CarbonImmutable::now()]);
            }
        }
    }

    /**
     * 銀行振込の入金待ちで、払う期限と猶予が過ぎた注文を取り消す（ADR 0074）。ロックの中で確かめ直すので、店の人の入金の確認と同時でも片方だけ
     *
     * @param  array<string, int>  $counts
     */
    private function expireBankTransfers(array &$counts, CarbonImmutable $now): void
    {
        $orders = Transaction::query()
            ->join('checkout_orders', 'checkout_orders.transaction_id', '=', 'transactions.id')
            ->where('transactions.status', TransactionStatus::Open)->where('transactions.phase', Phase::Agreement)
            ->where('checkout_orders.pays_with', 'bank_transfer')
            ->whereExists(static fn ($q) => $q->selectRaw('1')->from('payment_attempts')->whereColumn('payment_attempts.transaction_id', 'transactions.id')
                ->where('payment_attempts.kind', 'direct')->where('payment_attempts.status', 'awaiting')
                ->where('payment_attempts.pay_by', '<=', $now->subHours((int) config('payment.awaiting_grace_hours'))))
            ->orderByRaw('checkout_orders.expiry_checked_at ASC NULLS FIRST')->orderBy('transactions.created_at')
            ->limit((int) config('payment.expiry_batch'))->select('transactions.*')->get();

        foreach ($orders as $order) {
            try {
                $outcome = app(DirectPayments::class)->expireIfLapsed($order, $now);
                $key = $outcome === 'cancelled' ? 'cancelled_transfer' : $outcome;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            } catch (Throwable $e) {
                report($e);
                $counts['error'] = ($counts['error'] ?? 0) + 1;
            } finally {
                CheckoutOrder::query()->where('transaction_id', $order->id)->update(['expiry_checked_at' => CarbonImmutable::now()]);
            }
        }
    }

    /**
     * 預けたカードで払う注文（定期便の回）で、14 日を過ぎても払われないまま開いているものを取り消す（ADR 0049。定期便の App を止めた店などで、
     * 回の処理が片付けない分。回の処理は本人確認を 7 日、試し直しを 5 日で終えるので、ふだんはここまで残らない）。
     * 先に開いたままの請求を決済代行でも取り消し、取り消せなければ注文は残す（SaleOrderService::cancelIfUnpaid の中の取り消し）
     *
     * @param  array<string, int>  $counts
     */
    private function expireStaleStored(array &$counts): void
    {
        $orders = Transaction::query()
            ->join('checkout_orders', 'checkout_orders.transaction_id', '=', 'transactions.id')
            ->where('transactions.status', TransactionStatus::Open)->where('transactions.phase', Phase::Agreement)
            ->where('checkout_orders.pays_with', 'stored')
            ->where('transactions.created_at', '<', CarbonImmutable::now()->subDays(self::STORED_STALE_DAYS))
            ->orderByRaw('checkout_orders.expiry_checked_at ASC NULLS FIRST')->orderBy('transactions.created_at')
            ->limit((int) config('payment.expiry_batch'))->select('transactions.*')->get();

        foreach ($orders as $order) {
            try {
                $cancelled = app(SaleOrderService::class)->cancelIfUnpaid($order, 'system:expiry');
                $counts[$cancelled ? 'cancelled_stored' : 'stored_waiting'] = ($counts[$cancelled ? 'cancelled_stored' : 'stored_waiting'] ?? 0) + 1;

                // 取り消せない（払われていた・処理中・答えが分からない・作ったアカウントの鍵が無い）: 人が Stripe で確かめる。ログに注文の ID だけを残す
                if (! $cancelled) {
                    Log::warning('預けたカードの注文を取り消せない（人が Stripe で確認する）', ['transaction' => $order->id]);
                }
            } catch (Throwable $e) {
                report($e);
                $counts['error'] = ($counts['error'] ?? 0) + 1;
            } finally {
                CheckoutOrder::query()->where('transaction_id', $order->id)->update(['expiry_checked_at' => CarbonImmutable::now()]);
            }
        }
    }
}
