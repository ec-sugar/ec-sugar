<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\Events\PaymentAwaiting;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Events\PaymentLapsed;
use App\Core\Payment\Models\CheckoutPlan;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Payment\Models\WebhookEvent;
use App\Core\Payment\RefundResult;
use App\Core\Points\Services\PointService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 決済代行からの知らせ（Webhook・問い合わせで確かめた支払い）を、取引の支払いにする。署名は受け口で確かめた後。
 * 同じ知らせは 1 回だけ（台帳のロックの下で数える）。金額・取引・通貨が支払いの画面と違う・二重に払われた・閉じた注文に届いたは、
 * 支払いにせず記録し、返金が要る支払いとして残す（返金は PaymentRefunds）
 */
final class PaymentNotices
{
    public function __construct(
        private readonly EventStore $events,
        private readonly LedgerService $ledger,
        private readonly TransactionService $transactions,
        private readonly PaymentRefunds $refunds,
    ) {}

    /**
     * 決済代行が「払われた」と知らせた支払いの画面を、取引の支払いにする（Webhook。署名は確かめた後）。
     * 同じ通知の二度目は何もしない。金額・取引・通貨が支払いの画面と違えば支払いにせず、記録と警告だけ残す（人が確かめる）。
     * 同じ取引が別の画面でもう払われていた・取引が取消などで閉じていたときも、支払いにせず記録する（返金は人が判断）
     *
     * 払われた手段（method）は、いくつかの手段を出した画面で、ロックの外で問い合わせて分かったときだけ渡す（ADR 0058）
     *
     * @return string 処理の結果（paid・already_paid・duplicate・late・mismatch・unknown_session・seen）
     */
    public function confirmPaid(string $provider, string $eventId, string $type, string $sessionRef, ?string $transactionRef, int $amount, string $currency, ?string $paymentRef, ?string $method = null): string
    {
        return $this->once($provider, $eventId, $type, function () use ($sessionRef, $transactionRef, $amount, $currency, $paymentRef, $provider, $method): string {
            $attempt = PaymentAttempt::query()->where('provider', $provider)->where('provider_ref', $sessionRef)->lockForUpdate()->first();

            if ($attempt === null) {
                Log::warning('知らない支払いの画面の通知', ['provider' => $provider, 'session' => $sessionRef]);

                return 'unknown_session';
            }

            return $this->confirmAttempt($attempt, $provider, $transactionRef, $amount, $currency, $paymentRef ?? $sessionRef, $paymentRef, $method);
        });
    }

    /**
     * 支払いの画面は終わったが、お客さんが後から払う（コンビニ払いの払込票を出した。ADR 0058）: 入金待ちにする。
     * 注文はそのまま（在庫は押さえたまま・クーポンの数も押さえたまま）で、払われた知らせ（async_payment_succeeded）で確定し、
     * 払われなかった知らせ（async_payment_failed）か、払いの期限と猶予の後の見回りで取り消す。
     * 後から払う手段を出していない画面の「払われていない終わり」は扱わない（起きないはず）
     *
     * @return string 処理の結果（awaiting・already_paid・closed・not_open・ignored・mismatch・unknown_session・seen）
     */
    public function awaitPayment(string $provider, string $eventId, string $type, string $sessionRef, ?string $transactionRef, int $amount, string $currency, ?string $method, ?CarbonImmutable $payBy, ?string $instructionsUrl): string
    {
        return $this->once($provider, $eventId, $type, function () use ($provider, $sessionRef, $transactionRef, $amount, $currency, $method, $payBy, $instructionsUrl): string {
            $attempt = PaymentAttempt::query()->where('provider', $provider)->where('provider_ref', $sessionRef)->lockForUpdate()->first();

            if ($attempt === null) {
                return 'unknown_session';
            }

            $methods = CheckoutMethods::fromString($attempt->methods);

            if (! $methods->includesAsync()) {
                return 'ignored';
            }

            $transaction = Transaction::query()->lockForUpdate()->findOrFail($attempt->transaction_id);
            $facts = ['attempt' => $attempt->id, 'provider' => $provider, 'amount' => $amount];

            if ($this->mismatched($attempt, $transaction, $transactionRef, $amount, $currency)) {
                Log::error('入金待ちの通知が支払いの画面と合わない', $facts + ['transaction' => $attempt->transaction_id]);
                $this->events->append('payment.mismatch', 'transaction', $transaction->id, $facts, 'webhook:'.$provider);

                return 'mismatch';
            }

            if ($attempt->status === 'paid') {
                return 'already_paid';
            }

            // 取り消した注文の払込票（呼ぶ側が決済代行でも取り消す。払われたら、閉じた注文への支払いとして返金の道に乗る）
            if ($transaction->status !== TransactionStatus::Open || $transaction->phase !== Phase::Agreement) {
                return 'closed';
            }

            if ($attempt->status !== 'open') {
                return 'not_open';
            }

            // 後から払う手段は今はコンビニ払いだけ。期限が分からなければ、Stripe と同じ数え方で決める
            $method = in_array($method, CheckoutMethods::ASYNC, true) ? $method : 'konbini';
            $payBy ??= PaymentMethodSettings::konbiniDue(CarbonImmutable::now(), $methods->konbiniDays);
            $attempt->forceFill(['status' => 'awaiting', 'method' => $method, 'pay_by' => $payBy, 'instructions_url' => $instructionsUrl])->save();
            $this->events->append('payment.awaiting', 'transaction', $transaction->id, $facts + ['method' => $method, 'pay_by' => $payBy->toIso8601String()], 'webhook:'.$provider);
            $this->events->notice(new PaymentAwaiting($transaction->id), 'webhook:'.$provider);

            return 'awaiting';
        });
    }

    /**
     * 後から払う手段が払われなかった（コンビニ払いの期限が過ぎた・断られた。checkout.session.async_payment_failed。ADR 0058）。
     * 支払いを failed にし、ほかに払える画面・入金待ちが無ければ、注文を取り消して在庫とクーポンの数を戻す（期限切れの取り消しと同じ道）
     *
     * @return string 処理の結果（cancelled・failed・ignored・unknown_session・seen）
     */
    public function asyncFailed(string $provider, string $eventId, string $type, string $sessionRef): string
    {
        return $this->once($provider, $eventId, $type, function () use ($provider, $sessionRef): string {
            $attempt = PaymentAttempt::query()->where('provider', $provider)->where('provider_ref', $sessionRef)->lockForUpdate()->first();

            if ($attempt === null) {
                return 'unknown_session';
            }

            // 払われた・もう閉じた支払いは変えない（知らせの順が入れ替わっても、払われた支払いを戻さない）
            if (! in_array($attempt->status, ['open', 'awaiting'], true)) {
                return 'ignored';
            }

            $actor = 'webhook:'.$provider;
            $attempt->forceFill(['status' => 'failed'])->save();
            $this->events->append('payment.async_failed', 'transaction', $attempt->transaction_id, ['attempt' => $attempt->id, 'provider' => $provider], $actor);
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($attempt->transaction_id);

            if ($transaction->status !== TransactionStatus::Open || $transaction->phase !== Phase::Agreement || PaymentAttempt::paid($transaction->id)) {
                return 'failed';
            }

            // ほかに払える画面・入金待ち・作りかけの画面があれば、注文は残す（それぞれの期限で片付く）
            $others = PaymentAttempt::query()->where('transaction_id', $transaction->id)->whereKeyNot($attempt->id)->whereIn('status', ['open', 'awaiting'])->exists();

            if ($others || CheckoutPlan::inFlight($transaction->id)) {
                return 'failed';
            }

            $this->cancelUnpaidOrder($transaction, $actor, lapsed: true);

            return 'cancelled';
        });
    }

    /**
     * 払われないまま期限が過ぎた注文を取り消す（呼ぶ側が連鎖のロックと取引の行のロックを持ち、払われていないことを確かめた後）。
     * まだ開いている・入金待ちの支払いを期限切れにし、払われずに消えた注文のクーポンの数と使ったポイントとギフトカードの額を戻し（ADR 0054 の 6・0068 の 3・0076）、取引を取消にする（押さえた在庫が戻る）。
     * lapsed は後から払う手段の期限が過ぎた（お客さんへ「お支払いの期限が過ぎました」を送る）
     */
    public function cancelUnpaidOrder(Transaction $locked, string $actor, bool $lapsed = false): void
    {
        $expired = PaymentAttempt::query()->where('transaction_id', $locked->id)->whereIn('status', ['open', 'awaiting'])->update(['status' => 'expired', 'updated_at' => CarbonImmutable::now()]);
        $this->events->append('payment.order_expired', 'transaction', $locked->id, ['attempts_expired' => $expired] + ($lapsed ? ['reason' => 'payment_lapsed'] : []), $actor);
        app(CouponService::class)->release($locked->id, $actor);
        // 使ったポイントも戻す（ADR 0068 の 3）
        app(PointService::class)->release($locked->id, $actor);
        // 使ったギフトカードの額もカードへ戻す（ADR 0076。払われていないので台帳には受け取りが無い）
        app(GiftCardService::class)->release($locked->id, $actor);
        $this->transactions->transition($locked, Phase::Cancel, $actor);

        if ($lapsed) {
            $this->events->notice(new PaymentLapsed($locked->id), $actor);
        }
    }

    /**
     * 預けたカードの請求が払われた（ADR 0045 の 3。payment_intent.succeeded）。請求の前に作った stored の記録に当てて、支払いの画面と同じ決まりで確定する。
     * 請求の答えより先に知らせが届いた（記録がまだ仮の参照）ときは、取引の ID で開いている stored の記録を探して、支払いの ID に置き換えてから当てる。
     * 支払いの画面から生まれた支払い（checkout の記録）の知らせは、ここでは扱わない（checkout.session.completed で確定する）
     */
    public function confirmStored(string $provider, string $eventId, string $type, string $paymentRef, ?string $transactionRef, int $amount, string $currency): string
    {
        return $this->once($provider, $eventId, $type, function () use ($provider, $paymentRef, $transactionRef, $amount, $currency): string {
            $attempt = PaymentAttempt::query()->where('provider', $provider)->where('kind', 'stored')->where('provider_ref', $paymentRef)->lockForUpdate()->first();

            if ($attempt === null && $transactionRef !== null) {
                $attempt = PaymentAttempt::query()->where('provider', $provider)->where('kind', 'stored')->where('transaction_id', $transactionRef)
                    ->where('status', 'open')->where('provider_ref', 'like', 'stored:%')->orderByDesc('created_at')->lockForUpdate()->first();
                $attempt?->forceFill(['provider_ref' => $paymentRef])->save();
            }

            if ($attempt === null) {
                return 'ignored';
            }

            return $this->confirmAttempt($attempt, $provider, $transactionRef, $amount, $currency, $paymentRef, $paymentRef);
        });
    }

    /**
     * 預けたカードの請求が断られた（payment_intent.payment_failed。処理中だった請求や本人確認の後の断り）。開いている stored の記録を failed にするだけで、
     * 試し直すかは請求した仕組み（定期便の毎日の処理）が記録を見て決める。払われた記録は変えない
     */
    public function failStored(string $provider, string $eventId, string $type, string $paymentRef, ?string $transactionRef, string $code): string
    {
        return $this->once($provider, $eventId, $type, function () use ($provider, $paymentRef, $transactionRef, $code): string {
            $attempt = PaymentAttempt::query()->where('provider', $provider)->where('kind', 'stored')->where('provider_ref', $paymentRef)->lockForUpdate()->first();

            // 請求の答えより先に届いた: 取引の ID で開いている stored の記録を探して、支払いの ID に置き換えてから当てる（confirmStored と同じ）
            if ($attempt === null && $transactionRef !== null) {
                $attempt = PaymentAttempt::query()->where('provider', $provider)->where('kind', 'stored')->where('transaction_id', $transactionRef)
                    ->where('status', 'open')->where('provider_ref', 'like', 'stored:%')->orderByDesc('created_at')->lockForUpdate()->first();
                $attempt?->forceFill(['provider_ref' => $paymentRef])->save();
            }

            if ($attempt === null || $attempt->status !== 'open' || ($transactionRef !== null && $transactionRef !== $attempt->transaction_id)) {
                return 'ignored';
            }

            $attempt->forceFill(['status' => 'failed'])->save();
            $this->events->append('payment.stored_charge_failed', 'transaction', $attempt->transaction_id, ['attempt' => $attempt->id, 'provider' => $provider, 'code' => $code], 'webhook:'.$provider);

            return 'failed';
        });
    }

    /**
     * 支払いの記録に、払われた知らせを当てる（支払いの画面と預けたカードで同じ決まり。once の中、連鎖のロックの後で呼ぶ）
     */
    private function confirmAttempt(PaymentAttempt $attempt, string $provider, ?string $transactionRef, int $amount, string $currency, string $ledgerRef, ?string $paymentRef, ?string $method = null): string
    {

        $transaction = Transaction::query()->findOrFail($attempt->transaction_id);
        $actor = 'webhook:'.$provider;
        // 手段は分かったときだけ記録に足す（カードだけの画面の記録の形は今のまま）
        $method = $method !== null && array_key_exists($method, CheckoutMethods::LABELS) ? $method : null;
        $facts = ['attempt' => $attempt->id, 'provider' => $provider, 'amount' => $amount] + ($method === null ? [] : ['method' => $method]);

        if ($this->mismatched($attempt, $transaction, $transactionRef, $amount, $currency)) {
            Log::error('支払いの通知が支払いの画面と合わない', $facts + ['transaction' => $attempt->transaction_id]);
            $this->events->append('payment.mismatch', 'transaction', $transaction->id, $facts, $actor);

            return 'mismatch';
        }

        if ($attempt->status === 'paid') {
            return 'already_paid';
        }

        if (PaymentAttempt::paid($transaction->id)) {
            Log::warning('同じ注文が二度払われた（返金が要る）', $facts + ['transaction' => $transaction->id]);
            $this->events->append('payment.duplicate_received', 'transaction', $transaction->id, $facts, $actor);
            $this->stray($attempt, 'duplicate', $amount, $paymentRef);

            return 'duplicate';
        }

        if ($transaction->status !== TransactionStatus::Open || $transaction->phase !== Phase::Agreement) {
            Log::warning('閉じた注文に支払いが届いた（返金が要る）', $facts + ['transaction' => $transaction->id, 'phase' => $transaction->phase->value]);
            $this->events->append('payment.received_late', 'transaction', $transaction->id, $facts, $actor);
            $this->stray($attempt, 'late', $amount, $paymentRef);

            return 'late';
        }

        $this->ledger->recordPayment($transaction, $amount, PaymentRoute::Platform, $ledgerRef, $actor);
        // ギフトカードで払った分（ADR 0076）も、同じトランザクションで「ギフトカードでの受け取り」として台帳に
        app(GiftCardService::class)->recordPayment($transaction, $actor);
        $attempt->forceFill(['status' => 'paid', 'paid_at' => CarbonImmutable::now(), 'method' => $method ?? $attempt->method])->save();
        $this->events->append('payment.received', 'transaction', $transaction->id, $facts, $actor);
        $this->transactions->transition($transaction, Phase::Payment, $actor);
        $this->events->notice(new PaymentConfirmed($transaction->id), $actor);

        return 'paid';
    }

    /**
     * 決済代行が「支払いの画面の期限が切れた」と知らせた（Webhook）。在庫を戻すのは区切り 4 の片付け
     */
    public function markExpired(string $provider, string $eventId, string $type, string $sessionRef): string
    {
        return $this->once($provider, $eventId, $type, function () use ($provider, $sessionRef): string {
            $attempt = PaymentAttempt::query()->where('provider', $provider)->where('provider_ref', $sessionRef)->lockForUpdate()->first();

            if ($attempt === null) {
                return 'unknown_session';
            }

            if ($attempt->status === 'open') {
                $attempt->forceFill(['status' => 'expired'])->save();
                $this->events->append('payment.checkout_expired', 'transaction', $attempt->transaction_id, ['attempt' => $attempt->id, 'provider' => $provider], 'webhook:'.$provider);
            }

            return 'expired';
        });
    }

    /**
     * 扱わない種類の通知（受け取ったことだけ残す）
     */
    /**
     * 返金の状態の知らせ（ADR 0027）。保留の確定・失敗・成功の後の戻しを、返金の記録に当てる（1 回だけ）
     */
    public function refundUpdated(string $provider, string $eventId, string $type, RefundResult $result): string
    {
        return $this->once($provider, $eventId, $type, fn (): string => $this->refunds->applyNotice($result, 'webhook:'.$provider));
    }

    /**
     * 受け取ったことと、ほかの仕組みが決めた結果だけを残す（カードの登録など。1 回だけ）
     */
    public function note(string $provider, string $eventId, string $type, string $outcome): string
    {
        return $this->once($provider, $eventId, $type, static fn (): string => $outcome);
    }

    public function ignore(string $provider, string $eventId, string $type): string
    {
        return $this->once($provider, $eventId, $type, static fn (): string => 'ignored');
    }

    /**
     * 知らせの金額・取引・通貨が、支払いの画面と取引に合わないか（合わなければ支払いにしない）。取引の額は払ってもらう額（合計 − ギフトカードの額。ADR 0076）
     */
    private function mismatched(PaymentAttempt $attempt, Transaction $transaction, ?string $transactionRef, int $amount, string $currency): bool
    {
        return $transactionRef !== $attempt->transaction_id || $amount !== $attempt->amount || $amount !== OrderGiftCard::dueOf($transaction) || strtoupper($currency) !== $attempt->currency;
    }

    private function stray(PaymentAttempt $attempt, string $reason, int $amount, ?string $paymentRef): void
    {
        StrayPayment::query()->firstOrCreate(['attempt_id' => $attempt->id], [
            'transaction_id' => $attempt->transaction_id,
            'reason' => $reason,
            'amount' => $amount,
            'payment_ref' => $paymentRef,
            'status' => 'pending',
        ]);
    }

    /**
     * 通知を 1 回だけ処理する。台帳のロックの下で、もう処理した通知なら何もしない（同時に 2 回届いても順番になる）
     *
     * @param  callable(): string  $handle
     */
    private function once(string $provider, string $eventId, string $type, callable $handle): string
    {
        return DB::transaction(function () use ($provider, $eventId, $type, $handle): string {
            $this->events->lock();

            if (WebhookEvent::query()->where('provider', $provider)->where('event_id', $eventId)->exists()) {
                return 'seen';
            }

            $outcome = $handle();
            WebhookEvent::create(['provider' => $provider, 'event_id' => $eventId, 'type' => mb_substr($type, 0, 100), 'outcome' => $outcome]);

            return $outcome;
        });
    }
}
