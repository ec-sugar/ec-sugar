<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\CheckoutSession;
use App\Core\Payment\Contracts\PaymentCredentials;
use App\Core\Payment\Contracts\PaymentCredentialStore;
use App\Core\Payment\Contracts\PaymentDriver;
use App\Core\Payment\Contracts\StoredPaymentDriver;
use App\Core\Payment\Models\CheckoutPlan;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\PaymentState;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 取引の支払いの画面（ADR 0019）: 用意する・閉じる・払われないまま期限が過ぎた注文を取り消す。
 * 決済代行からの知らせの処理は PaymentNotices、返金は PaymentRefunds。
 *
 * 決済代行への問い合わせは DB のロックの外で行う（外の遅さでロックを握り続けない）。
 * 同時に押されても、冪等キー（取引と何回目か）で代行の画面は 1 つ、こちらの行も代行の ID の一意で 1 つ。
 * 頼む中身は頼む前に記録し、答えが届かなかったやり直しも同じ中身で頼む。
 */
final class PaymentService
{
    public const string UNAVAILABLE = 'このショップは、まだお支払いを受け付ける準備ができていません。ショップにお問い合わせください。';

    public const string NOT_PAYABLE = 'このご注文は、現在お支払いに進めません。';

    /** 支払いの画面の期限を切った時に、決済代行が受ける最短（Stripe の Checkout は作ってから 30 分より後。予約） */
    public const int PAY_BEFORE_MIN_MINUTES = 31;

    public const string TOO_LATE = 'ご予約の日時が近く、お支払いの手続きの時間が取れないため、この日時ではお支払いに進めません。別の日時をお選びください。';

    public const string ORDER_EXPIRED = 'お支払いの期限が過ぎました。お手数ですが、もう一度ご注文ください。';

    /** 準備中のショップで、本番の鍵のとき */
    public const string NOT_OPEN = 'このショップは公開の準備中です。公開後にご注文いただけます。';

    /** コンビニ払いの入金を待っている注文（新しい支払いの画面は作らない。二重に払われないように。ADR 0058） */
    public const string AWAITING = 'このご注文は、コンビニでのお支払いをお待ちしています。お支払い方法は、ご注文確認メールをご覧ください。';

    /** 銀行振込・代金引換の注文（Stripe を通さない。ADR 0074） */
    public const string DIRECT = 'このご注文は、銀行振込か代金引換でお支払いいただきます。お支払い方法は、ご注文確認メールとご注文の状況のページをご覧ください。';

    /** Stripe は有効期限が 30 分より先の画面しか作らない。記録した中身はそれより余裕があるときだけ使い回す */
    private const int PLAN_REUSE_MINUTES = 31;

    public function __construct(
        private readonly EventStore $events,
        private readonly PaymentCredentialStore $gateways,
        private readonly PaymentDriver $driver,
        private readonly PaymentNotices $notices,
        private readonly StorefrontAccessService $access,
        private readonly PaymentMethodSettings $methods,
    ) {}

    /**
     * 準備中のショップで、本物のお金が動く鍵か（運営の判断）。準備中に入れた人が試せるのは、Stripe のテストの鍵
     * （sk_test_、つないだ店ならテストのモード）のときだけ。テストのモードと分からない鍵は本番として扱う（閉じる側に倒す）。鍵が無ければ偽
     */
    public function liveBeforeOpening(): bool
    {
        $credentials = $this->gateways->credentials();

        return $credentials !== null && $credentials->mode() !== 'test' && $this->access->status() !== ShopStatus::Open;
    }

    public function available(): bool
    {
        return $this->gateways->credentials() !== null;
    }

    public function isPaid(Transaction $transaction): bool
    {
        return PaymentAttempt::paid($transaction->id);
    }

    /**
     * 支払いの画面へ送る先。まだ使える画面があればそれを、無ければ新しく作る
     */
    /**
     * @param  ?CarbonImmutable  $payBefore  支払いの画面の期限をこの時刻より前に切る（予約の枠の始まり）。いつもの期限と短い方。
     *                                       決済代行が受ける最短（PAY_BEFORE_MIN_MINUTES 分）より短くなるなら断る
     */
    public function startCheckout(Transaction $transaction, string $successUrl, string $cancelUrl, ?string $actor = null, ?CustomerAccount $saveCardFor = null, ?CarbonImmutable $payBefore = null): CheckoutSession
    {
        $credentials = $this->gateways->credentials() ?? throw new PaymentUnavailableException(self::UNAVAILABLE);

        // カードを預ける支払い（定期便の 1 回目。ADR 0045 の 3）は、口を持つ決済代行の店だけ。預ける先は先に用意する（ロックの外で）
        $storing = null;

        if ($saveCardFor !== null) {
            $storing = $this->driver instanceof StoredPaymentDriver ? $this->driver : throw new PaymentUnavailableException(StoredPayments::UNAVAILABLE);
            // 預けるのは、この注文のお客さんの台帳で、会員で、止めておらず、まとめられていないときだけ（取り違えて他人の定期便にカードを付けない）
            $owner = CustomerAccount::query()->find($saveCardFor->id);

            if ($owner === null || $transaction->customer_account_id !== $owner->id || $owner->member_since === null || $owner->disabled_at !== null || $owner->merged_into_id !== null) {
                throw new PaymentUnavailableException(StoredPayments::UNAVAILABLE);
            }
        }

        // 準備中のショップでは、本番の鍵で支払いの画面を作らない（入れた人が試すのはテストの鍵だけ）
        if ($credentials->mode() !== 'test' && $this->access->status() !== ShopStatus::Open) {
            throw new PaymentUnavailableException(self::NOT_OPEN);
        }

        // 店が受け取る支払い（銀行振込・代金引換）の注文には、Stripe の画面を作らない（二重に払われないように。ADR 0074）
        if (PaymentAttempt::direct($transaction->id) !== null) {
            throw new PaymentUnavailableException(self::DIRECT);
        }

        if (! self::payable($transaction)) {
            throw new PaymentUnavailableException(self::NOT_PAYABLE);
        }

        if ($this->isPaid($transaction)) {
            throw new PaymentUnavailableException('このご注文のお支払いは済んでいます。');
        }

        // コンビニ払いの入金を待っている注文は、新しい画面を作らず、払込票の画面へ（ADR 0058）
        $awaiting = PaymentAttempt::awaiting($transaction->id);

        if ($awaiting !== null) {
            return self::instructionsOf($awaiting);
        }

        // 期限まで 5 分以上ある画面は使い回す（押すたびに画面を増やさない）。ロックの中でもう一度見る（plan）。預ける画面とふつうの画面は混ぜない
        $open = $this->usableAttempt($transaction, $credentials, $saveCardFor?->id);

        if ($open !== null) {
            return self::sessionOf($open);
        }

        // 預ける先は、店の公開・払える注文の確かめの後に用意する（ロックの外で）
        $customerRef = $saveCardFor === null ? null : app(StoredPayments::class)->customerFor($saveCardFor);
        $plan = $this->plan($transaction, $credentials, $actor, $saveCardFor?->id, $payBefore);

        // ロックを待つ間に、同時に押された方が画面を作り終えていた・入金待ちになった
        if ($plan instanceof PaymentAttempt) {
            return $plan->status === 'awaiting' ? self::instructionsOf($plan) : self::sessionOf($plan);
        }

        // 出す手段は予約に記録したもの（答えが届かなかったやり直しも、同じ中身で頼む）
        $session = $storing !== null && $customerRef !== null
            ? $storing->openCheckoutSavingCard($credentials, $transaction, $successUrl, $cancelUrl, $plan->idempotency_key, $plan->expires_at, $customerRef)
            : $this->driver->openCheckout($credentials, $transaction, $successUrl, $cancelUrl, $plan->idempotency_key, $plan->expires_at, CheckoutMethods::fromString($plan->methods));

        try {
            $recorded = DB::transaction(function () use ($transaction, $session, $credentials, $actor, $plan, $saveCardFor): bool {
                $this->events->lock();
                // 代行に頼んでいる間に、注文が取り消された・ほかの画面で払われた: 作った画面は払える形で渡さない
                $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
                $payable = self::payable($locked) && ! $this->isPaid($locked);
                $row = PaymentAttempt::create([
                    'transaction_id' => $transaction->id,
                    'provider' => 'stripe',
                    'provider_ref' => $session->ref,
                    'checkout_url' => $session->url,
                    'amount' => $session->amount,
                    'currency' => $transaction->currency,
                    // 払えない画面も記録は残す（後から支払いが届けば、遅れて届いた支払いとして返金の道に乗る）
                    'status' => $payable ? 'open' : 'expired',
                    'expires_at' => $session->expiresAt,
                    'stripe_account' => $credentials->account(),
                    // 返金・問い合わせ・通知の突き合わせは、このモードの鍵で（ADR 0096）
                    'test_mode' => $credentials->mode() === 'test',
                    'saves_card_for' => $saveCardFor?->id,
                    'methods' => $saveCardFor === null ? $plan->methods : 'card',
                ]);
                CheckoutPlan::query()->whereKey($plan->id)->update(['attempt_id' => $row->id, 'updated_at' => CarbonImmutable::now()]);
                $this->events->append($payable ? 'payment.checkout_started' : 'payment.checkout_discarded', 'transaction', $transaction->id, ['attempt' => $row->id, 'provider' => 'stripe', 'amount' => $session->amount], $actor);

                return $payable;
            });
        } catch (QueryException $e) {
            // 同時に押された方が先に同じ画面（同じ冪等キー）を記録した
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            $status = PaymentAttempt::query()->where('provider', 'stripe')->where('provider_ref', $session->ref)->value('status');

            // 先に記録した方で、もう払われている（閉じる操作は送らない）
            if ($status === 'paid') {
                throw new PaymentUnavailableException('このご注文のお支払いは済んでいます。');
            }

            $recorded = $status === 'open';
        }

        if (! $recorded) {
            // 払えない画面は代行の側でもすぐ閉じる（DB のロックの外）。閉じられなくても期限で閉じ、届いた支払いは返金が要る支払いになる
            try {
                $this->driver->expireCheckout($credentials, $session->ref);
            } catch (PaymentUnavailableException) {
                Log::warning('払えない支払いの画面を閉じられなかった（期限で閉じる）', ['transaction' => $transaction->id, 'provider_ref' => $session->ref]);
            }

            throw new PaymentUnavailableException(self::NOT_PAYABLE);
        }

        return $session;
    }

    /**
     * 支払いの画面を作っている途中か（取消の前にロックの中で呼ぶ）: 見た後にできた払える画面か、記録の前の作りかけの予約がある。
     * 運営の取消と期限切れの片付けは、この間は取り消さない（取り消すと、払える画面が取り消した注文に残る）
     *
     * @param  list<string>  $seen  ロックの外で見た（閉じた）画面
     */
    public function checkoutInFlight(Transaction $locked, array $seen): bool
    {
        // 入金待ち（コンビニ払い。ADR 0058）も、見た後にできたものは払われうる
        return PaymentAttempt::query()->where('transaction_id', $locked->id)->where('kind', 'checkout')->whereIn('status', ['open', 'awaiting'])->whereNotIn('id', $seen)->exists()
            || $this->checkoutBeingMade($locked);
    }

    /**
     * 今まさに Stripe に支払いの画面を頼んでいる途中か（記録の前の予約が、ここ数分に作られた・使い回された）。
     * 答えの届かなかった古い予約は数えない（その画面は誰にも渡っていない）
     */
    public function checkoutBeingMade(Transaction $transaction): bool
    {
        return CheckoutPlan::inFlight($transaction->id);
    }

    /**
     * お客さんと店の人に見せる支払いの様子（払われた手段・入金待ちと払いの期限。ADR 0058）。支払いの記録が無ければ null
     */
    public function state(Transaction $transaction): ?PaymentState
    {
        $attempt = PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('status', 'paid')->first()
            ?? PaymentAttempt::awaiting($transaction->id);

        if ($attempt === null) {
            return null;
        }

        $awaiting = $attempt->status === 'awaiting';
        $method = $attempt->paymentMethod();

        // 店が受け取る支払い（ADR 0074）: 銀行振込の入金待ちは振込先と払う額と期限、代引き・当日払いの払う前は「受け渡しの時に払う」
        if ($attempt->kind === 'direct') {
            $transfer = $awaiting && $attempt->method === 'bank_transfer';

            return new PaymentState($method, CheckoutMethods::label($method), $transfer, $transfer ? $attempt->pay_by : null, null,
                $transfer ? $attempt->bank_account : null, $awaiting && in_array($attempt->method, ['cod', 'on_site'], true), $attempt->amount);
        }

        return new PaymentState($method, CheckoutMethods::label($method), $awaiting, $awaiting ? $attempt->pay_by : null, $awaiting ? $attempt->instructions_url : null);
    }

    /**
     * 入金待ちの支払いの、払込票の画面へ送る形。画面が分からなければ断る（ご注文の確認のメールで案内する）
     */
    private static function instructionsOf(PaymentAttempt $attempt): CheckoutSession
    {
        if ($attempt->instructions_url === null || $attempt->pay_by === null) {
            throw new PaymentUnavailableException(self::AWAITING);
        }

        return new CheckoutSession($attempt->provider_ref, $attempt->instructions_url, $attempt->amount, $attempt->pay_by);
    }

    /**
     * 期限まで 5 分以上ある払える画面（同じ額。ギフトカードで払う分を引いた、払ってもらう額。ADR 0076）
     */
    private function usableAttempt(Transaction $transaction, PaymentCredentials $credentials, ?string $savesCardFor = null): ?PaymentAttempt
    {
        // 今の鍵と同じアカウント・同じモードで作った画面だけ（準備中にテスト用の鍵で作った画面を、公開した後に本番の支払いとして渡さない。ADR 0096）
        return PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('kind', 'checkout')->where('status', 'open')
            ->where('stripe_account', $credentials->account())->where('test_mode', $credentials->mode() === 'test')
            ->where('amount', OrderGiftCard::dueOf($transaction))->where('saves_card_for', $savesCardFor)
            ->where('expires_at', '>', CarbonImmutable::now()->addMinutes(5))->latest('created_at')->first();
    }

    private static function sessionOf(PaymentAttempt $attempt): CheckoutSession
    {
        return new CheckoutSession($attempt->provider_ref, $attempt->checkout_url, $attempt->amount, $attempt->expires_at);
    }

    /**
     * 支払いに進める注文か（物販・合意の段・開いている・払ってもらう額が 1 円以上。全額をギフトカードで払う注文は確定で済んでいる。ADR 0076）
     */
    private static function payable(Transaction $transaction): bool
    {
        return $transaction->kind === 'sale' && $transaction->phase === Phase::Agreement && $transaction->status === TransactionStatus::Open && OrderGiftCard::dueOf($transaction) > 0;
    }

    /**
     * 新しい支払いの画面を作れる時間を過ぎた注文か（注文から決めた時間）。上限が無いと、押し続けて在庫を押さえ続けられる。
     * まだ払える画面への使い回しは、この後でも行う
     */
    private static function pastNewPageDeadline(Transaction $transaction): bool
    {
        return $transaction->created_at->isBefore(CarbonImmutable::now()->subMinutes((int) config('payment.order_payable_minutes')));
    }

    /**
     * 支払いの画面を頼む中身（何回目・冪等キー・有効期限）を、頼む前に記録する。
     * 前に頼んで画面を記録できなかった中身が、まだ Stripe に受けてもらえる有効期限なら、それを使い回す（同じキーは同じ中身で）。
     * ロックの中で確かめ直す: 注文が払えなくなっていれば断り、同時に押された方が画面を作り終えていればその画面を返す（別の窓のレビュー 1・2）
     */
    private function plan(Transaction $transaction, PaymentCredentials $credentials, ?string $actor, ?string $savesCardFor = null, ?CarbonImmutable $payBefore = null): CheckoutPlan|PaymentAttempt
    {
        return DB::transaction(function () use ($transaction, $credentials, $actor, $savesCardFor, $payBefore): CheckoutPlan|PaymentAttempt {
            $this->events->lock();
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if (! self::payable($locked)) {
                throw new PaymentUnavailableException(self::NOT_PAYABLE);
            }

            if ($this->isPaid($locked)) {
                throw new PaymentUnavailableException('このご注文のお支払いは済んでいます。');
            }

            // 店が受け取る支払いの注文（ロックを待つ間に作られることは無いが、念のためロックの中でも断る。ADR 0074）
            if (PaymentAttempt::direct($locked->id) !== null) {
                throw new PaymentUnavailableException(self::DIRECT);
            }

            $awaiting = PaymentAttempt::awaiting($locked->id);

            if ($awaiting !== null) {
                return $awaiting;
            }

            $open = $this->usableAttempt($locked, $credentials, $savesCardFor);

            if ($open !== null) {
                return $open;
            }

            if (self::pastNewPageDeadline($locked)) {
                throw new PaymentUnavailableException(self::ORDER_EXPIRED);
            }

            $pending = CheckoutPlan::query()->where('transaction_id', $transaction->id)->whereNull('attempt_id')->where('saves_card_for', $savesCardFor)
                ->where('expires_at', '>', CarbonImmutable::now()->addMinutes(self::PLAN_REUSE_MINUTES))
                ->orderByDesc('attempt')->first();

            if ($pending !== null) {
                // 使い回すたびに「作っている途中」の時刻を新しくする（取消と期限切れの片付けが待つ目安）
                $pending->touch();

                return $pending;
            }

            // 記録の仕組みより前に作った画面も、回数を使ったものとして数える
            $attempt = max(
                (int) CheckoutPlan::query()->where('transaction_id', $transaction->id)->max('attempt'),
                PaymentAttempt::query()->where('transaction_id', $transaction->id)->count(),
            ) + 1;

            // 期限はいつもの長さか、渡された時刻（予約の枠の始まり）の短い方。決済代行が受ける最短より短いなら作らない
            $expiresAt = CarbonImmutable::now()->addMinutes((int) config('payment.checkout_minutes'))->startOfSecond();

            if ($payBefore !== null) {
                $expiresAt = $expiresAt->min($payBefore->startOfSecond());

                if ($expiresAt->lessThan(CarbonImmutable::now()->addMinutes(self::PAY_BEFORE_MIN_MINUTES))) {
                    throw new PaymentUnavailableException(self::TOO_LATE);
                }
            }

            $plan = CheckoutPlan::create([
                'transaction_id' => $transaction->id,
                'attempt' => $attempt,
                'idempotency_key' => "checkout-{$transaction->id}-{$attempt}",
                'expires_at' => $expiresAt,
                'saves_card_for' => $savesCardFor,
                // 出す手段（店の設定と、この注文の払ってもらう額〔合計 − ギフトカードの額〕で使える手段。カードを預ける支払いとギフトカードを売る注文はカードだけ。
                // お届け日を選んだ注文・来店で受け渡す品〔予約〕・送らない品〔チケット〕の注文にコンビニ払いは出さない。ADR 0058・0059・0069・0076）
                'methods' => $this->methods->forCheckout(OrderGiftCard::dueOf($locked), $savesCardFor !== null || self::giftCardSale($locked->id), self::noDeferredPayment($locked->id))->toString(),
            ]);
            $this->events->append('payment.checkout_planned', 'transaction', $transaction->id, ['plan' => $plan->id, 'attempt' => $attempt], $actor);

            return $plan;
        });
    }

    /**
     * ギフトカードを売る注文か（明細の種類 gift_card。払いが確かになった時にすぐ渡すので、カードだけにする。ADR 0076）。
     * チケットなどほかの送らない品は、後から払いが届く手段だけを出さない（noDeferredPayment）
     */
    private static function giftCardSale(string $transactionId): bool
    {
        return TransactionLine::query()->where('transaction_id', $transactionId)->where('kind', 'gift_card')->exists();
    }

    /**
     * 後から払いが届く手段（コンビニ払い）を出さない注文か: お届け日を選んだ注文（受渡のどれかに希望日がある。時間帯だけの指定は数えない）と、
     * 来店で受け渡す品だけの注文（受渡が役務だけ。予約。予約の日時の後に払いが届いて予約が確定する、を作らない）、
     * 送らない品（受渡が digital。チケット。ADR 0069。終演の後に払いが届いてチケットが出る、を作らない）
     */
    private static function noDeferredPayment(string $transactionId): bool
    {
        $rows = Fulfillment::query()->where('transaction_id', $transactionId)->get(['method', 'delivery_date']);

        return $rows->contains(static fn (Fulfillment $f): bool => $f->delivery_date !== null || $f->method === FulfillmentMethod::Digital)
            || ($rows->isNotEmpty() && $rows->every(static fn (Fulfillment $f): bool => $f->method === FulfillmentMethod::InPerson));
    }

    /**
     * まだ払える支払いの画面を閉じる（取り消す注文が後から払われないように。DB のロックの外）。
     * 払われていた（通知がまだ届いていない）なら取り消さない。見た画面の ID を返す（呼ぶ側がロックの中で、その後にできた画面が無いかを確かめる）
     *
     * @return list<string>
     */
    public function closeOpenCheckouts(Transaction $transaction): array
    {
        $seen = [];

        // 支払いの画面だけ（預けたカードの請求〔stored〕は画面が無い。取り消した後に届いた支払いは、閉じた注文への支払いとして返金の道に乗る）。
        // 入金待ち（コンビニ払い。ADR 0058）も、払込票を決済代行で取り消してから
        foreach (PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('kind', 'checkout')->whereIn('status', ['open', 'awaiting'])->get() as $attempt) {
            $seen[] = $attempt->id;
            // 作ったアカウントの設定が今は無い画面は、こちらからは閉じられない（期限で閉じる。届いた支払いは返金が要る支払いとして残る）
            $credentials = $this->gateways->credentialsForAttempt($attempt);

            if ($credentials === null) {
                continue;
            }

            $snapshot = $this->driver->retrieveCheckout($credentials, $attempt->provider_ref)
                ?? throw new PaymentUnavailableException('Stripe に接続できないため、お客さんの支払いの画面を閉じられませんでした。時間をおいて、もう一度お試しください。');

            if ($snapshot->paid) {
                throw new PaymentUnavailableException('この注文には支払いが届いています（確認中です）。少し待ってから、画面を読み込み直してください。');
            }

            if ($snapshot->isOpen()) {
                $this->driver->expireCheckout($credentials, $attempt->provider_ref);
            } elseif (($snapshot->awaiting || $attempt->status === 'awaiting') && $snapshot->paymentRef !== null) {
                $this->cancelAwaiting($credentials, $snapshot->paymentRef);
            }
        }

        return $seen;
    }

    /**
     * 払われた注文に残っている、ほかの支払いの画面と入金待ちの払込票を決済代行で閉じる（二重に払われないように。DB のロックの外）。
     * 払込票が出た知らせを先に受けた後に、別の画面（入金待ちになる前に作った画面）でカードで払われると、払込票が生きたまま残るため（PR 89 の見直し）。
     * 払われた知らせの後（StripeWebhook）と、閉じられなかった分を見回り（storefront:expire-unpaid-orders）から呼ぶ。
     * 閉じたものは記録も閉じる（画面は expired、払込票は failed）。もう払われていた・お店で払っている途中のものは触らない
     * （届いたら二重の支払いとして返金の道に乗る）。決済代行に届かないものは残し、次の見回りでもう一度
     *
     * @return int 閉じた数
     */
    public function closeLeftoversOfPaid(string $transactionId): int
    {
        if (! PaymentAttempt::paid($transactionId)) {
            return 0;
        }

        $closed = [];

        foreach (PaymentAttempt::query()->where('transaction_id', $transactionId)->where('kind', 'checkout')->whereIn('status', ['open', 'awaiting'])->get() as $attempt) {
            $credentials = $this->gateways->credentialsForAttempt($attempt);

            if ($credentials === null) {
                continue;
            }

            try {
                $snapshot = $this->driver->retrieveCheckout($credentials, $attempt->provider_ref);

                if ($snapshot === null || $snapshot->paid) {
                    continue;
                }

                if ($snapshot->isOpen()) {
                    $this->driver->expireCheckout($credentials, $attempt->provider_ref);
                    $closed[$attempt->id] = 'expired';
                } elseif (($snapshot->awaiting || $attempt->status === 'awaiting') && $snapshot->paymentRef !== null) {
                    if ($this->driver->cancelPayment($credentials, $snapshot->paymentRef) === 'cancelled') {
                        $closed[$attempt->id] = 'failed';
                    }
                } elseif (! $snapshot->awaiting) {
                    // 決済代行の側でもう終わっている（期限切れ・払込票の期限切れ）。記録だけ閉じる
                    $closed[$attempt->id] = $attempt->status === 'awaiting' ? 'failed' : 'expired';
                }
            } catch (\Throwable $e) {
                Log::warning('払われた注文に残った支払いの画面を閉じられなかった（次の見回りでもう一度）', ['transaction' => $transactionId, 'attempt' => $attempt->id, 'error' => $e::class]);
            }
        }

        if ($closed === []) {
            return 0;
        }

        return DB::transaction(function () use ($closed, $transactionId): int {
            $this->events->lock();
            $count = 0;

            foreach (PaymentAttempt::query()->whereIn('id', array_keys($closed))->lockForUpdate()->get() as $attempt) {
                // ロックの間に払われた（知らせが届いた）なら変えない
                if (! in_array($attempt->status, ['open', 'awaiting'], true)) {
                    continue;
                }

                $attempt->forceFill(['status' => $closed[$attempt->id]])->save();
                $this->events->append('payment.leftover_closed', 'transaction', $transactionId, ['attempt' => $attempt->id, 'status' => $closed[$attempt->id]], 'system:payment');
                $count++;
            }

            return $count;
        });
    }

    /**
     * 入金待ちの支払い（コンビニ払いの払込票）を決済代行で取り消す。払われていた・お店で払っている途中なら断る
     */
    private function cancelAwaiting(PaymentCredentials $credentials, string $paymentRef): void
    {
        $outcome = $this->driver->cancelPayment($credentials, $paymentRef);

        if ($outcome === 'succeeded') {
            throw new PaymentUnavailableException('この注文には支払いが届いています（確認中です）。少し待ってから、画面を読み込み直してください。');
        }

        if ($outcome !== 'cancelled') {
            throw new PaymentUnavailableException('お客さんがコンビニで支払い中の可能性があるため、取り消せませんでした。時間をおいて、もう一度お試しください。');
        }
    }

    /**
     * 払われないまま支払いの画面の期限（と猶予）が過ぎた注文を取り消し、押さえた在庫を戻す（区切り 4。定期の片付けから）。
     * 取り消す前に Stripe に今の状態を問い合わせる。払われていれば（通知が届かなかった）支払いを確定する。問い合わせが届かなければ次の回へ回す。
     * 入金待ち（コンビニ払い。ADR 0058）は、払いの期限と猶予が過ぎるまで待ち、過ぎたら払込票を Stripe で取り消してから取り消す（クーポンの数も戻す）
     *
     * @return string 結果（cancelled・confirmed・waiting・skipped・closed）
     */
    public function expireIfUnpaid(Transaction $transaction, CarbonImmutable $cutoff, string $actor = 'system:expiry'): string
    {
        if ($transaction->status !== TransactionStatus::Open || $transaction->phase !== Phase::Agreement || $this->isPaid($transaction)) {
            return 'closed';
        }

        $attempts = PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('kind', 'checkout')->whereIn('status', ['open', 'expired', 'awaiting'])->get();
        $awaitingUntil = CarbonImmutable::now()->subHours((int) config('payment.awaiting_grace_hours'));

        if ($attempts->contains(static fn (PaymentAttempt $attempt): bool => ($attempt->status === 'open' && $attempt->expires_at->isAfter($cutoff))
            || ($attempt->status === 'awaiting' && $attempt->pay_by?->isAfter($awaitingUntil) !== false))) {
            return 'waiting';
        }

        $lapsed = $attempts->contains(static fn (PaymentAttempt $attempt): bool => $attempt->status === 'awaiting');

        // Stripe への問い合わせは DB のロックの外で、支払いの画面を作ったアカウントの鍵で（今の設定に無いアカウントの画面は問い合わせられない）
        foreach ($attempts as $attempt) {
            $credentials = $this->gateways->credentialsForAttempt($attempt);

            if ($credentials === null) {
                continue;
            }

            $snapshot = $this->driver->retrieveCheckout($credentials, $attempt->provider_ref);

            if ($snapshot === null) {
                return 'skipped';
            }

            if ($snapshot->paid) {
                $outcome = $this->notices->confirmPaid('stripe', 'retrieve:'.$attempt->provider_ref, 'checkout.session.retrieved', $attempt->provider_ref, $snapshot->transactionRef, $snapshot->amount ?? -1, $snapshot->currency ?? '', $snapshot->paymentRef, $snapshot->method);

                return $outcome === 'paid' ? 'confirmed' : 'skipped';
            }

            // Stripe ではまだ払える（こちらの期限の記録と食い違う）。払える間は取り消さない
            if ($snapshot->isOpen()) {
                return 'waiting';
            }

            if ($snapshot->awaiting) {
                // 入金待ちの知らせが届いていなかった: 入金待ちにして、払いの期限まで待つ
                if ($attempt->status !== 'awaiting') {
                    $this->notices->awaitPayment('stripe', 'retrieve-awaiting:'.$attempt->provider_ref, 'checkout.session.retrieved', $attempt->provider_ref, $snapshot->transactionRef, $snapshot->amount ?? -1, $snapshot->currency ?? '', $snapshot->method, $snapshot->payBy, $snapshot->instructionsUrl);

                    return 'waiting';
                }

                // 払いの期限と猶予を過ぎても Stripe ではまだ払える: 払込票を取り消してから（お店で払っている途中なら次の回へ）
                if ($snapshot->paymentRef === null) {
                    return 'skipped';
                }

                try {
                    $cancelled = $this->driver->cancelPayment($credentials, $snapshot->paymentRef);
                } catch (PaymentUnavailableException) {
                    return 'skipped';
                }

                if ($cancelled !== 'cancelled') {
                    return $cancelled === 'succeeded' ? 'skipped' : 'waiting';
                }
            }
        }

        $asked = $attempts->pluck('id')->all();
        $seenAwaiting = $attempts->where('status', 'awaiting')->pluck('id')->all();

        return DB::transaction(function () use ($transaction, $actor, $cutoff, $asked, $seenAwaiting, $lapsed): string {
            $this->events->lock();
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            // ロックを待つ間に通知で払われた・ほかで動いた
            if ($locked->status !== TransactionStatus::Open || $locked->phase !== Phase::Agreement || $this->isPaid($locked)) {
                return 'closed';
            }

            // Stripe に問い合わせている間に、お客さんが新しい画面を作った・作っている途中・入金待ちになった（別の窓のレビュー 2）。払える間は取り消さない
            $newPage = PaymentAttempt::query()->where('transaction_id', $locked->id)->whereIn('status', ['open', 'awaiting'])
                ->where(static fn ($query) => $query->where(static fn ($open) => $open->where('status', 'open')->where('expires_at', '>', $cutoff))->orWhereNotIn('id', $asked))->exists();
            $pagePending = $this->checkoutBeingMade($locked);
            // 問い合わせている間に、入金待ちの知らせが届いた（払込票が出た）
            $nowAwaiting = PaymentAttempt::query()->where('transaction_id', $locked->id)->where('status', 'awaiting')->whereNotIn('id', $seenAwaiting)->exists();

            if ($newPage || $pagePending || $nowAwaiting) {
                return 'waiting';
            }

            $this->notices->cancelUnpaidOrder($locked, $actor, $lapsed);

            return 'cancelled';
        });
    }
}
