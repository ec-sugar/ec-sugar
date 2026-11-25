<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Event\EventStore;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Payment\Contracts\PaymentCredentials;
use App\Core\Payment\Contracts\PaymentCredentialStore;
use App\Core\Payment\Contracts\PaymentDriver;
use App\Core\Payment\Contracts\StoredCardHolder;
use App\Core\Payment\Contracts\StoredPaymentDriver;
use App\Core\Payment\Jobs\DetachStoredCards;
use App\Core\Payment\Models\CardSetup;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\StoredPaymentCustomer;
use App\Core\Payment\Models\StoredPaymentMethod;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\SavedCard;
use App\Core\Payment\StoredCharge;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 預けたカード（ADR 0045）。カードを預ける先を用意する・払われた支払いで預けたカードを記録する・お客さんのいない請求・外す。
 *
 * - 預ける先（Stripe の Customer）はお客さん × 決済代行 × アカウントで 1 つ。アカウントが替われば別の行（前のアカウントのカードは使わない）
 * - 決済代行に頼むのはロックの外（通信の間に店の書き込みを止めない）。記録は連鎖のロックとイベントの中（個人情報・カードの値はイベントに入れない）
 * - 口（StoredPaymentDriver）を持たない決済代行の店では、定期便を使えない（available が偽）
 */
final class StoredPayments
{
    public const string UNAVAILABLE = 'このショップでは、カードを登録してのお支払いはご利用いただけません。';

    public function __construct(
        private readonly EventStore $events,
        private readonly PaymentCredentialStore $gateways,
        private readonly PaymentDriver $driver,
    ) {}

    public function available(): bool
    {
        return $this->driver instanceof StoredPaymentDriver && $this->gateways->credentials() !== null;
    }

    /**
     * そのお客さんの、今のアカウントの預ける先（無ければ作る）
     */
    public function customerFor(CustomerAccount $account): string
    {
        [$driver, $credentials] = $this->stored();
        $key = self::accountKey($credentials);
        $existing = StoredPaymentCustomer::query()->where('customer_account_id', $account->id)->where('provider', 'stripe')->where('account', $key)->value('customer_ref');

        if (is_string($existing)) {
            return $existing;
        }

        // 冪等キーはお客さん × アカウント（同時に 2 つ開いても、決済代行の側も 1 つ）。頼むのはロックの外、記録は連鎖のロックとイベントの中
        $ref = $driver->createCustomer($credentials, $account->id, 'customer:'.$account->id.':'.$key);

        return DB::transaction(function () use ($account, $key, $ref): string {
            $this->events->lock();
            $existing = StoredPaymentCustomer::query()->where('customer_account_id', $account->id)->where('provider', 'stripe')->where('account', $key)->lockForUpdate()->value('customer_ref');

            if (is_string($existing)) {
                return $existing;
            }

            $row = StoredPaymentCustomer::create(['customer_account_id' => $account->id, 'provider' => 'stripe', 'account' => $key, 'customer_ref' => $ref]);
            $this->events->append('payment.customer_prepared', 'customer_account', $account->id, ['stored_customer' => $row->id, 'provider' => 'stripe'], 'storefront');

            return $ref;
        });
    }

    /**
     * 払われた知らせの時にカードを記録できなかった（Stripe の一時の失敗など）注文から、もう一度記録する（定期便の請求の前。ADR 0045 の残り）。
     * その注文の、払われた・カードを預ける支払いの画面と、台帳に残した支払いの ID で、知らせの時と同じ決まり（rememberFrom）を通す
     */
    public function recover(string $transactionId): ?StoredPaymentMethod
    {
        $attempt = PaymentAttempt::query()->where('transaction_id', $transactionId)->where('kind', 'checkout')->where('status', 'paid')->whereNotNull('saves_card_for')->first();
        $paymentRef = LedgerJournal::query()->where('transaction_id', $transactionId)->where('type', JournalType::Payment)->orderBy('occurred_at')->value('reference');

        if ($attempt === null || ! is_string($paymentRef) || ! str_starts_with($paymentRef, 'pi_')) {
            return null;
        }

        return $this->rememberFrom($attempt->provider_ref, $paymentRef);
    }

    /**
     * 払われた支払いの画面がカードを預けるものなら、預けたカードを記録する（払われた知らせの後。失敗しても支払いの確定は変えない）
     */
    public function rememberFrom(string $sessionRef, ?string $paymentRef): ?StoredPaymentMethod
    {
        $attempt = PaymentAttempt::query()->where('provider', 'stripe')->where('provider_ref', $sessionRef)->first();

        if ($attempt === null || $attempt->saves_card_for === null || $attempt->status !== 'paid' || $paymentRef === null || ! $this->available()) {
            return null;
        }

        [$driver, $credentials] = $this->stored();
        $key = self::accountKey($credentials);

        // 画面を作った時と今で、つないでいる Stripe のアカウントが違えば記録しない（切り替えの途中の知らせ）
        if ((string) $attempt->stripe_account !== (string) $credentials->account()) {
            Log::warning('預けたカードの通知が今のアカウントと違う', ['attempt' => $attempt->id]);

            return null;
        }

        $card = $driver->savedCardOf($credentials, $paymentRef);
        $expected = StoredPaymentCustomer::query()->where('customer_account_id', $attempt->saves_card_for)->where('provider', 'stripe')->where('account', $key)->value('customer_ref');

        // 預ける先がこのお客さんのものでなければ記録しない（取り違えを作らない）
        if ($card === null || ! is_string($expected) || ! hash_equals($expected, $card->customerRef)) {
            Log::warning('預けたカードを記録できなかった', ['attempt' => $attempt->id]);

            return null;
        }

        return $this->storeCard((string) $attempt->saves_card_for, $key, $card, 'webhook:stripe');
    }

    /**
     * カードを替える画面を開く（ADR 0045 の 1 の後半）。会員で、止めておらず、まとめられていないお客さんだけ。
     * 記録（連鎖のロックとイベント）を先に作り、画面はロックの外で。冪等キーは記録の ID。返すのはお客さんを送る先（決済代行の画面）
     */
    public function openCardSetup(CustomerAccount $account, string $successUrl, string $cancelUrl, string $actor): string
    {
        [$driver, $credentials] = $this->stored();
        $owner = CustomerAccount::query()->find($account->id);

        if ($owner === null || $owner->member_since === null || $owner->disabled_at !== null || $owner->merged_into_id !== null) {
            throw new PaymentUnavailableException(self::UNAVAILABLE);
        }

        $customerRef = $this->customerFor($owner);
        $expiresAt = CarbonImmutable::now()->addMinutes((int) config('payment.checkout_minutes'));
        $setup = DB::transaction(function () use ($owner, $credentials, $expiresAt, $actor): CardSetup {
            $this->events->lock();
            $row = CardSetup::create(['customer_account_id' => $owner->id, 'provider' => 'stripe', 'account' => self::accountKey($credentials), 'status' => 'open', 'expires_at' => $expiresAt]);
            $this->events->append('payment.card_setup_opened', 'customer_account', $owner->id, ['card_setup' => $row->id], $actor);

            return $row;
        });

        $session = $driver->openCardSetup($credentials, $customerRef, $successUrl, $cancelUrl, 'card-setup:'.$setup->id, $expiresAt, $setup->id);
        DB::transaction(function () use ($setup, $session): void {
            $this->events->lock();
            CardSetup::query()->whereKey($setup->id)->whereNull('session_ref')->update(['session_ref' => $session->ref, 'updated_at' => CarbonImmutable::now()]);
        });

        return $session->url;
    }

    /**
     * カードを替える画面が済んだ知らせ（checkout.session.completed の setup のモード）。開いた記録があり、作った時と同じアカウントで、
     * Stripe の答えの預ける先がそのお客さんのものの時だけカードを記録する。何度届いても 1 つ
     */
    public function rememberSetup(string $sessionRef, string $setupIntentRef): ?StoredPaymentMethod
    {
        $setup = CardSetup::query()->where('provider', 'stripe')->where('session_ref', $sessionRef)->first();

        if ($setup === null || ! $this->available()) {
            return null;
        }

        [$driver, $credentials] = $this->stored();
        $key = self::accountKey($credentials);

        if (! hash_equals($setup->account, $key)) {
            Log::warning('カードの登録の通知が今のアカウントと違う', ['card_setup' => $setup->id]);

            return null;
        }

        $card = $driver->savedCardOfSetup($credentials, $setupIntentRef);
        $expected = StoredPaymentCustomer::query()->where('customer_account_id', $setup->customer_account_id)->where('provider', 'stripe')->where('account', $key)->value('customer_ref');

        if ($card === null || ! is_string($expected) || ! hash_equals($expected, $card->customerRef)) {
            Log::warning('登録したカードを記録できなかった', ['card_setup' => $setup->id]);

            return null;
        }

        $method = $this->storeCard($setup->customer_account_id, $key, $card, 'webhook:stripe');

        if ($method !== null) {
            DB::transaction(function () use ($setup, $method): void {
                $this->events->lock();

                if (CardSetup::query()->whereKey($setup->id)->where('status', 'open')->update(['status' => 'completed', 'completed_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]) === 1) {
                    $this->events->append('payment.card_setup_completed', 'customer_account', $setup->customer_account_id, ['card_setup' => $setup->id, 'stored_method' => $method->id], 'webhook:stripe');
                }
            });
        }

        return $method;
    }

    /**
     * 預けたカードを記録する（連鎖のロック → 行のロック → イベント）。外したカード・ほかのお客さんの行は上書きしない（外したカードを生き返らせない・取り違えない）。
     * 記録する時に会員でない（画面を開いた後に退会した）・止めたお客さんのカードは記録しない
     */
    private function storeCard(string $accountId, string $key, SavedCard $card, string $actor): ?StoredPaymentMethod
    {
        return DB::transaction(function () use ($accountId, $key, $card, $actor): ?StoredPaymentMethod {
            $this->events->lock();
            $owner = CustomerAccount::query()->whereKey($accountId)->lockForUpdate()->first();

            if ($owner === null || $owner->member_since === null || $owner->disabled_at !== null) {
                return null;
            }

            $method = StoredPaymentMethod::query()->where('provider', 'stripe')->where('account', $key)->where('method_ref', $card->methodRef)->lockForUpdate()->first()
                ?? new StoredPaymentMethod(['customer_account_id' => $accountId, 'provider' => 'stripe', 'account' => $key, 'method_ref' => $card->methodRef]);

            if ($method->exists && ($method->status === 'detached' || $method->customer_account_id !== $accountId)) {
                return $method;
            }
            $method->fill([
                'customer_ref' => $card->customerRef,
                'status' => 'usable',
                'brand' => $card->brand,
                'last4' => $card->last4,
                'exp_month' => $card->expMonth,
                'exp_year' => $card->expYear,
                'fingerprint' => $card->fingerprint,
            ]);
            $created = ! $method->exists;
            $method->save();

            if ($created) {
                $this->events->append('payment.card_stored', 'customer_account', $accountId, ['stored_method' => $method->id, 'provider' => 'stripe'], $actor);
                // 前に預けたカードは外す（新しいカードだけで請求する。ADR 0045 の 1）
                DetachStoredCards::forAccount($accountId, $method->id, $actor);
            }

            return $method;
        });
    }

    /**
     * そのお客さんの、今のアカウントで使えるカード（新しいもの）
     */
    public function usableFor(CustomerAccount $account): ?StoredPaymentMethod
    {
        $key = $this->gateways->credentials() === null ? null : self::accountKey($this->gateways->credentials());

        return $key === null ? null : StoredPaymentMethod::query()->where('customer_account_id', $account->id)->where('provider', 'stripe')->where('account', $key)
            ->where('status', 'usable')->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /**
     * 何人ものお客さんの預けたカードの指紋（Stripe の card.fingerprint。番号ではない）を 1 回の問い合わせで（App「抽選販売」が同じカードの申し込みをまとめる。ADR 0080）。
     * $usableOnly なら usableFor と同じカード（今のアカウントの使える、いちばん新しい 1 枚）だけ、そうでなければ外した・使えなくなったカードも含めた全部。
     * 指紋の分からないカード（前に預けたもの）は入れない
     *
     * @param  list<string>  $accountIds
     * @return array<string, list<string>> お客さんの ID => 指紋
     */
    public function fingerprints(array $accountIds, bool $usableOnly = true): array
    {
        $key = $this->gateways->credentials() === null ? null : self::accountKey($this->gateways->credentials());

        if ($accountIds === [] || ($usableOnly && $key === null)) {
            return [];
        }

        $rows = StoredPaymentMethod::query()->whereIn('customer_account_id', $accountIds)->where('provider', 'stripe')
            ->when($usableOnly, static fn ($q) => $q->where('account', $key)->where('status', 'usable'))
            ->orderByDesc('created_at')->orderByDesc('id')->get(['customer_account_id', 'fingerprint']);
        $prints = [];

        foreach ($rows as $row) {
            if ($usableOnly && array_key_exists($row->customer_account_id, $prints)) {
                continue;
            }

            $prints[$row->customer_account_id] ??= [];

            if ($row->fingerprint !== null && ! in_array($row->fingerprint, $prints[$row->customer_account_id], true)) {
                $prints[$row->customer_account_id][] = $row->fingerprint;
            }
        }

        return $prints;
    }

    /**
     * お客さんのいない請求（冪等キーは回の ID と何回目か。Stripe の冪等キーは 24 時間ほどで消えるので、やり直しは 24 時間のうちに。区切り 3）。
     * 渡す契約のお客さんとカードの持ち主が同じ・使える・今のアカウント・1 円以上のときだけ。カードか預け先が無いと分かったら、登録し直しが要る印を付ける。
     * 記録と確定は呼ぶ側と Webhook（区切り 3）
     */
    public function charge(StoredPaymentMethod $method, string $forAccountId, int $amount, string $currency, string $idempotencyKey, string $transactionRef): StoredCharge
    {
        [$driver, $credentials] = $this->stored();

        if ($method->customer_account_id !== $forAccountId || $method->status !== 'usable' || $method->account !== self::accountKey($credentials) || $amount <= 0) {
            throw new PaymentUnavailableException('このカードでは請求できません。');
        }

        $charge = $driver->chargeStored($credentials, $method->customer_ref, $method->method_ref, $amount, $currency, $idempotencyKey, $transactionRef);

        if ($charge->status === 'failed' && $charge->failureCode === 'resource_missing') {
            $this->mark($method, 'needs_update', 'system:subscriptions');
        }

        return $charge;
    }

    /**
     * 取引の代金を預けたカードで請求する（定期便の回。ADR 0045・0049）。請求の前に stored の支払いの記録を仮の参照で作り（連鎖のロックとイベント）、
     * 請求の答えの支払いの ID に置き換える。確定と台帳は Webhook（payment_intent.succeeded → PaymentNotices::confirmStored）。
     * 冪等キーは「取引 × 何回目」（試し直しは回数ごとに別のキー。unknown のやり直しは同じ回数で 24 時間のうちに）
     */
    public function chargeTransaction(Transaction $transaction, StoredPaymentMethod $method, string $forAccountId, int $attemptNo, string $actor): StoredCharge
    {
        if ($attemptNo < 1 || $attemptNo > 9 || $transaction->customer_account_id !== $forAccountId) {
            throw new PaymentUnavailableException('この注文は、このカードで請求できません。');
        }

        $placeholder = 'stored:'.$transaction->id.':'.$attemptNo;
        $credentials = $this->gateways->credentials();

        // 前の番号の請求が開いたまま（本人確認待ち・断りの知らせが届いていない）なら、先に決済代行で取り消す。取り消せなければ今は請求しない
        // （カードを替えた後の請求し直しで、前の本人確認の画面から後で払われて二重にならないように。お客さんの断りには数えない）
        if (! $this->releaseOpenCharges($transaction, $actor, $placeholder)) {
            return new StoredCharge(null, 'rejected', 'open_charge');
        }

        // 請求してよいかは連鎖のロックの中で決める: 開いていて合意の段の、払われていない注文だけ。同じ回数の記録が開いていれば、同じ冪等キーで聞き直す
        $allowed = DB::transaction(function () use ($transaction, $placeholder, $credentials, $actor, $attemptNo): bool {
            $this->events->lock();
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($locked->status !== TransactionStatus::Open || $locked->phase !== Phase::Agreement || PaymentAttempt::paid($locked->id)) {
                return false;
            }

            $existing = PaymentAttempt::query()->where('charge_key', $placeholder)->lockForUpdate()->first();

            if ($existing !== null) {
                // 決済代行に支払いが作られずにはっきり断られた記録（仮の参照のまま失敗）は、同じ番号の頼み直しで開き直す（同じ冪等キーで支払いは作られていない）
                if ($existing->status === 'failed' && $existing->provider_ref === $placeholder) {
                    $existing->forceFill(['status' => 'open'])->save();
                    $this->events->append('payment.stored_charge_restarted', 'transaction', $locked->id, ['attempt' => $existing->id, 'try' => $attemptNo], $actor);

                    return true;
                }

                return $existing->status === 'open';
            }

            $row = PaymentAttempt::create([
                'transaction_id' => $locked->id,
                'provider' => 'stripe',
                'kind' => 'stored',
                'provider_ref' => $placeholder,
                'charge_key' => $placeholder,
                'checkout_url' => '',
                'amount' => $locked->total_amount,
                'currency' => $locked->currency,
                'status' => 'open',
                'expires_at' => CarbonImmutable::now()->addDays(7),
                'stripe_account' => $credentials?->account(),
                'test_mode' => $credentials?->mode() === 'test',
            ]);
            $this->events->append('payment.stored_charge_started', 'transaction', $locked->id, ['attempt' => $row->id, 'try' => $attemptNo, 'amount' => $locked->total_amount], $actor);

            return true;
        });

        if (! $allowed) {
            return new StoredCharge(null, 'rejected', 'order_closed');
        }

        $charge = $this->charge($method, $forAccountId, (int) $transaction->total_amount, $transaction->currency, $placeholder, $transaction->id);

        // 支払いの ID の無い、はっきりした断り（失敗・受け付けなかった）: 記録を失敗にする（開いたまま残すと、次の番号の請求と注文の取消が、
        // 取り消せない請求として止まり続ける）。答えの分からない請求（unknown）は開いたまま（同じ冪等キーで聞き直す。過ぎたら人が確かめる）
        if ($charge->ref === null && in_array($charge->status, ['failed', 'rejected'], true)) {
            DB::transaction(function () use ($placeholder, $charge, $actor): void {
                $this->events->lock();
                $row = PaymentAttempt::query()->where('charge_key', $placeholder)->where('provider_ref', $placeholder)->where('status', 'open')->lockForUpdate()->first();

                if ($row !== null) {
                    $row->forceFill(['status' => 'failed'])->save();
                    $this->events->append('payment.stored_charge_refused', 'transaction', $row->transaction_id, ['attempt' => $row->id, 'status' => $charge->status, 'code' => $charge->failureCode], $actor);
                }
            });
        }

        // 答えの支払いの ID に置き換える（連鎖のロックの中で。知らせが先に届いて置き換え済みなら何もしない）
        if ($charge->ref !== null) {
            DB::transaction(function () use ($placeholder, $charge, $actor): void {
                $this->events->lock();
                $row = PaymentAttempt::query()->where('charge_key', $placeholder)->where('provider_ref', $placeholder)->lockForUpdate()->first();

                if ($row !== null) {
                    $row->forceFill(['provider_ref' => $charge->ref])->save();
                    $this->events->append('payment.stored_charge_answered', 'transaction', $row->transaction_id, ['attempt' => $row->id, 'status' => $charge->status], $actor);
                }
            });
        }

        return $charge;
    }

    /**
     * 預けたカードの開いたままの請求を、注文を取り消す前に決済代行でも取り消す（ADR 0045・0049。取り消した回の本人確認の画面から、後で払われないように）。
     * 取り消せた（開いた請求が無かった）ら true。払われていた・処理中・答えの分からない請求（仮の参照のまま）・作った時と違うアカウント・
     * 問い合わせられない時は false（注文を取り消さない。後でもう一度。答えの分からない請求は人が確かめる）
     */
    public function releaseOpenCharges(Transaction $transaction, string $actor, ?string $except = null): bool
    {
        $open = PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('kind', 'stored')->where('status', 'open')
            ->when($except !== null, static fn ($q) => $q->where('charge_key', '!=', $except))->orderBy('created_at')->get();

        if ($open->isEmpty()) {
            return true;
        }

        if (! $this->available()) {
            return false;
        }

        [$driver] = $this->stored();

        foreach ($open as $attempt) {
            // 取り消しは、その請求を作ったアカウントの鍵で（今の設定がそのアカウントでなければ取り消せない。人が確かめる）
            $credentials = $this->gateways->credentialsForAttempt($attempt);

            if (str_starts_with($attempt->provider_ref, 'stored:') || $credentials === null) {
                return false;
            }

            try {
                $result = $driver->cancelStoredCharge($credentials, $attempt->provider_ref);
            } catch (PaymentUnavailableException) {
                return false;
            }

            if ($result !== 'cancelled') {
                return false;
            }

            DB::transaction(function () use ($attempt, $actor): void {
                $this->events->lock();

                if (PaymentAttempt::query()->whereKey($attempt->id)->where('status', 'open')->update(['status' => 'failed', 'updated_at' => CarbonImmutable::now()]) === 1) {
                    $this->events->append('payment.stored_charge_cancelled', 'transaction', $attempt->transaction_id, ['attempt' => $attempt->id], $actor);
                }
            });
        }

        return true;
    }

    /**
     * 答えの分からないまま 23 時間を過ぎた請求（仮の参照のまま開いた記録。冪等キーが消え、同じキーで聞き直せない）を、店の人が Stripe の画面で
     * 払われていないと確かめた後に閉じる（ADR 0080。お金の段階の人の操作から呼ぶ）。閉じたら（閉じる記録が無かったら）true。
     * 支払いの ID の分かった開いた請求は、ここでは閉じない（releaseOpenCharges が Stripe に聞いて取り消す）
     */
    public function abandonUnknownCharges(Transaction $transaction, string $actor): bool
    {
        return DB::transaction(function () use ($transaction, $actor): bool {
            $this->events->lock();
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if (PaymentAttempt::paid($locked->id)) {
                return false;
            }

            $unknown = PaymentAttempt::query()->where('transaction_id', $locked->id)->where('kind', 'stored')->where('status', 'open')
                ->where('provider_ref', 'like', 'stored:%')->lockForUpdate()->get();

            foreach ($unknown as $attempt) {
                if ($attempt->created_at === null || $attempt->created_at->greaterThan(CarbonImmutable::now()->subHours(23))) {
                    return false;
                }
            }

            foreach ($unknown as $attempt) {
                $attempt->forceFill(['status' => 'failed'])->save();
                $this->events->append('payment.stored_charge_abandoned', 'transaction', $locked->id, ['attempt' => $attempt->id], $actor);
            }

            return true;
        });
    }

    /**
     * そのお客さんの預けたカードを、まだ請求に使う App があるか（ADR 0080。使い終わったカードを外す前に聞く。退会では聞かない）。
     * 答えられない App があれば、使うとして扱う（外し損ねは次の回にまた聞く。使うカードを外すほうが困る）
     */
    public static function heldByApps(string $accountId): bool
    {
        foreach (app()->tagged(StoredCardHolder::TAG) as $holder) {
            try {
                if ($holder instanceof StoredCardHolder && $holder->holds($accountId)) {
                    return true;
                }
            } catch (Throwable $e) {
                report($e);

                return true;
            }
        }

        return false;
    }

    /**
     * そのお客さんの、外せるカード（使える・登録し直しが要る）の ID（$keep のほか）。外す仕事を出す時に決める
     *
     * @return list<string>
     */
    public static function detachable(string $accountId, ?string $keep): array
    {
        return StoredPaymentMethod::query()->where('customer_account_id', $accountId)->whereIn('status', ['usable', 'needs_update'])
            ->when($keep !== null, static fn ($q) => $q->whereKeyNot($keep))->orderBy('id')->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all();
    }

    /**
     * 決めたカードを外す（DetachStoredCards から。そのお客さんのカードで、まだ外していないものだけ）。決済代行の設定が無い店では何もしない（外せない。
     * 請求の道も無い）。作った時と違うアカウントのカードは今の鍵で外せないので、記録だけ「外した」にする（もう請求に使わない）
     *
     * @param  list<string>  $cardIds
     */
    public function detachCards(string $accountId, array $cardIds, string $actor): void
    {
        if ($cardIds === [] || ! $this->available()) {
            return;
        }

        [, $credentials] = $this->stored();
        $key = self::accountKey($credentials);

        foreach (StoredPaymentMethod::query()->where('customer_account_id', $accountId)->whereIn('id', $cardIds)->whereIn('status', ['usable', 'needs_update'])->get() as $method) {
            $method->account === $key ? $this->detach($method, $actor) : $this->mark($method, 'detached', $actor);
        }
    }

    public function detach(StoredPaymentMethod $method, string $actor): void
    {
        [$driver, $credentials] = $this->stored();

        if ($method->account === self::accountKey($credentials) && $method->status !== 'detached') {
            $driver->detachStored($credentials, $method->method_ref);
        }

        $this->mark($method, 'detached', $actor);
    }

    /**
     * カードの状態を変える（連鎖のロック → 行のロック、イベントに残す）。外したカードは戻さない
     */
    private function mark(StoredPaymentMethod $method, string $status, string $actor): void
    {
        DB::transaction(function () use ($method, $status, $actor): void {
            $this->events->lock();
            $locked = StoredPaymentMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'detached' || $locked->status === $status) {
                return;
            }

            $locked->status = $status;
            $locked->save();
            $this->events->append($status === 'detached' ? 'payment.card_detached' : 'payment.card_needs_update', 'customer_account', $locked->customer_account_id, ['stored_method' => $locked->id], $actor);
        });
    }

    /**
     * @return array{0: StoredPaymentDriver, 1: PaymentCredentials}
     */
    private function stored(): array
    {
        $credentials = $this->gateways->credentials();

        if (! $this->driver instanceof StoredPaymentDriver || $credentials === null) {
            throw new PaymentUnavailableException(self::UNAVAILABLE);
        }

        return [$this->driver, $credentials];
    }

    /**
     * アカウントの見分け（見直し 2026-11-01 の中 1）: テストと本番を必ず分け、つないだ店は連結アカウントで。
     * 店の鍵の店は「own」（同じモードで別の Stripe アカウントの鍵へ貼り替えたときは、預け先やカードが見つからない答えで登録し直しの印が付く）
     */
    private static function accountKey(PaymentCredentials $credentials): string
    {
        $mode = $credentials->mode();

        return $mode.':'.($credentials->account() ?? 'own');
    }
}
