<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\StoredPaymentMethod;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentService;
use App\Core\Payment\Services\StoredPayments;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 預けたカード（ADR 0045）。1 回目の支払いの画面で「次回からこのカードで」を付け、払われた知らせで預けたカードを記録する。
 * お客さんのいない請求は冪等キー（回の ID）で 1 回、カードの断り・本人確認は答えの状態で。個人情報は決済代行に渡さず、イベントにも入れない。
 */
final class StoredPaymentTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string BODY = 'FakeForTestsOnly000';

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();
        $this->tenant('spa');
        $this->inside('spa', fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => $this->secret()]));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_first_payment_saves_the_card_and_the_notice_records_it(): void
    {
        [$sale, $account] = $this->inside('spa', fn (): array => $this->order('hanako@example.com'));

        $this->inside('spa', function () use ($sale, $account): void {
            $stored = app(StoredPayments::class);
            $this->assertTrue($stored->available());
            app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($sale), 'https://x/ok', 'https://x/back', saveCardFor: CustomerAccount::query()->findOrFail($account));
        });

        // 預ける先（Customer）には台帳の ID だけ（メールなどは渡さない）。支払いの画面は Customer に付けて「次回から」
        $customer = array_key_first($this->stripe->customers);
        $this->assertNotNull($customer);
        $this->assertSame(['customer_account_id' => $account, 'tenant' => 'spa'], $this->stripe->customers[$customer]['metadata']);
        $checkout = $this->stripe->lastParams();
        $this->assertSame($customer, $checkout['customer']);
        $this->assertSame('off_session', $checkout['payment_intent_data']['setup_future_usage']);
        $this->assertStringNotContainsString('hanako', (string) json_encode($this->stripe->requests));

        // 同じお客さんの 2 回目は同じ Customer（作り直さない）
        $this->inside('spa', fn () => $this->assertSame($customer, app(StoredPayments::class)->customerFor(CustomerAccount::query()->findOrFail($account))));
        $this->assertCount(1, $this->stripe->customers);

        // 払われた知らせ → 預けたカードを記録（ブランド・末尾 4 桁・期限だけ）
        $method = $this->stripe->intentWithSavedCard('pi_test_save', $customer);
        $this->deliver($this->completed('evt_1', $sale, 'pi_test_save'))->assertOk();
        $this->inside('spa', function () use ($account, $method): void {
            $card = StoredPaymentMethod::query()->sole();
            $this->assertSame([$account, $method, 'usable', '4242'], [$card->customer_account_id, $card->method_ref, $card->status, $card->last4]);
            $this->assertSame('Visa •••• 4242（2028 年 12 月まで）', $card->label());
            $this->assertEqualsCanonicalizing(['stored_method' => $card->id, 'provider' => 'stripe'], (array) DomainEvent::query()->where('type', 'payment.card_stored')->sole()->payload);
            $this->assertSame($card->id, app(StoredPayments::class)->usableFor(CustomerAccount::query()->findOrFail($account))?->id);
        });

        // 同じ知らせの送り直しでも 1 枚のまま
        $this->deliver($this->completed('evt_1', $sale, 'pi_test_save'))->assertOk();
        $this->inside('spa', fn () => $this->assertSame(1, StoredPaymentMethod::query()->count()));

        // 外した後に、別の知らせ（ID が違う）で同じ支払いが届いても、外したカードは生き返らない
        $this->inside('spa', fn () => app(StoredPayments::class)->detach(StoredPaymentMethod::query()->sole(), 'storefront'));
        $event = $this->completed('evt_1b', $sale, 'pi_test_save');
        $event['type'] = 'checkout.session.async_payment_succeeded';
        $this->deliver($event)->assertOk();
        $this->inside('spa', fn () => $this->assertSame('detached', StoredPaymentMethod::query()->sole()->status));
    }

    public function test_cards_are_not_recorded_for_plain_payments_or_another_customer(): void
    {
        // ふつうの支払い（預けない）は、カードを記録しない
        [$plain] = $this->inside('spa', fn (): array => $this->order('taro@example.com'));
        $this->inside('spa', fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($plain), 'https://x/ok', 'https://x/back'));
        $this->assertArrayNotHasKey('customer', $this->stripe->lastParams());
        $this->stripe->intentWithSavedCard('pi_test_plain', 'cus_test_someone');
        $this->deliver($this->completed('evt_2', $plain, 'pi_test_plain'))->assertOk();

        // 預ける支払いでも、Stripe の答えの Customer がこのお客さんのものでなければ記録しない（取り違えない）
        [$sale, $account] = $this->inside('spa', fn (): array => $this->order('hanako@example.com'));
        $this->inside('spa', fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($sale), 'https://x/ok', 'https://x/back', saveCardFor: CustomerAccount::query()->findOrFail($account)));
        $this->stripe->intentWithSavedCard('pi_test_other', 'cus_test_not_hers');
        $this->deliver($this->completed('evt_3', $sale, 'pi_test_other'))->assertOk();

        $this->inside('spa', function () use ($sale): void {
            $this->assertSame(0, StoredPaymentMethod::query()->count());
            // 支払いの確定は変わらない
            $this->assertSame('paid', PaymentAttempt::query()->where('transaction_id', $sale)->sole()->status);
        });
    }

    public function test_charging_a_stored_card_once_and_reading_declines(): void
    {
        $card = $this->inside('spa', fn (): StoredPaymentMethod => $this->storedCard('hanako@example.com'));

        $this->inside('spa', function () use ($card): void {
            $stored = app(StoredPayments::class);

            // 通った。冪等キーは回の ID（同じ回は何度呼んでも 1 回の請求）
            $first = $stored->charge($card, $card->customer_account_id, 4400, 'JPY', 'round:01TESTROUND0000000000000A', 'txn-1');
            $this->assertSame('succeeded', $first->status);
            $this->assertSame('round:01TESTROUND0000000000000A', $this->stripe->lastHeader('Idempotency-Key'));
            $params = $this->stripe->lastParams();
            $this->assertSame([4400, 'jpy', $card->customer_ref, $card->method_ref, 'true', 'true'], [(int) $params['amount'], $params['currency'], $params['customer'], $params['payment_method'], (string) $params['off_session'], (string) $params['confirm']]);
            $again = $stored->charge($card, $card->customer_account_id, 4400, 'JPY', 'round:01TESTROUND0000000000000A', 'txn-1');
            $this->assertSame($first->ref, $again->ref);
            $this->assertCount(1, $this->stripe->intents);

            // カードの断りと本人確認は、答えの状態と符号で
            $this->stripe->chargeOutcome = 'declined';
            $declined = $stored->charge($card, $card->customer_account_id, 4400, 'JPY', 'round:B', 'txn-2');
            $this->assertSame(['failed', 'insufficient_funds'], [$declined->status, $declined->failureCode]);
            // 本人確認（本物の Stripe は 402・authentication_required・状態 requires_payment_method で返す）
            $this->stripe->chargeOutcome = 'requires_action';
            $action = $stored->charge($card, $card->customer_account_id, 4400, 'JPY', 'round:C', 'txn-3');
            $this->assertSame(['requires_action', 'authentication_required'], [$action->status, $action->failureCode]);
            $this->assertNotNull($action->ref);

            // 店の鍵の失効・同じ冪等キーで違う中身は、お客さんの失敗でなく rejected。通信の失敗・5xx は unknown（例外を外へ出さない）
            $this->stripe->chargeOutcome = 'unauthorized';
            $this->assertSame(['rejected', 'shop_settings'], [($r = $stored->charge($card, $card->customer_account_id, 4400, 'JPY', 'round:D', 'txn-4'))->status, $r->failureCode]);
            $this->stripe->chargeOutcome = 'idempotency';
            $this->assertSame(['rejected', 'idempotency'], [($r = $stored->charge($card, $card->customer_account_id, 4400, 'JPY', 'round:E', 'txn-5'))->status, $r->failureCode]);
            $this->stripe->chargeOutcome = 'server';
            $this->assertSame('unknown', $stored->charge($card, $card->customer_account_id, 4400, 'JPY', 'round:F', 'txn-6')->status);
            $this->assertSame('usable', $card->refresh()->status);

            // カードか預け先が無い: 失敗にして、登録し直しが要る印（もう請求しない）
            $this->stripe->chargeOutcome = 'missing';
            $this->assertSame(['failed', 'resource_missing'], [($r = $stored->charge($card, $card->customer_account_id, 4400, 'JPY', 'round:G', 'txn-7'))->status, $r->failureCode]);
            $this->assertSame('needs_update', $card->refresh()->status);
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.card_needs_update')->count());
        });
    }

    public function test_detached_cards_cards_of_another_account_and_zero_amounts_are_not_charged(): void
    {
        $card = $this->inside('spa', fn (): StoredPaymentMethod => $this->storedCard('hanako@example.com'));

        $this->inside('spa', function () use ($card): void {
            $stored = app(StoredPayments::class);

            foreach ([0, -100] as $amount) {
                try {
                    $stored->charge($card, $card->customer_account_id, $amount, 'JPY', 'round:z'.$amount, 'txn');
                    $this->fail('0 円以下で請求した');
                } catch (PaymentUnavailableException) {
                }
            }

            // 渡した契約のお客さんとカードの持ち主が違えば請求しない（取り違えない）
            try {
                $stored->charge($card, app(CustomerAccounts::class)->forEmail('taro@example.com', 'storefront')->id, 100, 'JPY', 'round:who', 'txn');
                $this->fail('ほかのお客さんの契約で、このカードを請求した');
            } catch (PaymentUnavailableException) {
            }

            // ほかのアカウント（店の鍵から Connect へ替えた等）のカードでは請求しない
            $other = $card->replicate(['id']);
            $other->forceFill(['account' => 'acct_old', 'method_ref' => 'pm_test_old'])->save();
            try {
                $stored->charge($other, $other->customer_account_id, 100, 'JPY', 'round:old', 'txn');
                $this->fail('前のアカウントのカードで請求した');
            } catch (PaymentUnavailableException) {
            }

            // 外すと Stripe でも外し、もう請求しない・使えるカードに出ない
            $stored->detach($card, 'storefront');
            $this->assertSame([$card->method_ref], $this->stripe->detached);
            $this->assertSame('detached', $card->refresh()->status);
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.card_detached')->count());
            $this->assertNull($stored->usableFor(CustomerAccount::query()->findOrFail($card->customer_account_id)));
            try {
                $stored->charge($card, $card->customer_account_id, 100, 'JPY', 'round:after', 'txn');
                $this->fail('外したカードで請求した');
            } catch (PaymentUnavailableException) {
            }
            $this->assertCount(0, $this->stripe->intents);
        });
    }

    public function test_only_the_orders_own_member_can_save_a_card_and_pages_are_not_mixed(): void
    {
        [$sale, $account] = $this->inside('spa', fn (): array => $this->order('hanako@example.com', member: false));
        [, $other] = $this->inside('spa', fn (): array => $this->order('taro@example.com', member: false));

        $this->inside('spa', function () use ($sale, $account, $other): void {
            $payments = app(PaymentService::class);
            $refused = function (string $who, string $case) use ($payments, $sale): void {
                try {
                    $payments->startCheckout(Transaction::query()->findOrFail($sale), 'https://x/ok', 'https://x/back', saveCardFor: CustomerAccount::query()->findOrFail($who));
                    $this->fail($case);
                } catch (PaymentUnavailableException) {
                }
            };

            // 会員でない・注文のお客さんでない・止めた・まとめられた台帳には預けない
            $refused($account, '会員でないのに預けた');
            CustomerAccount::query()->whereKey([$account, $other])->update(['member_since' => now()]);
            $refused($other, 'ほかのお客さんに預けた');
            CustomerAccount::query()->whereKey($account)->update(['disabled_at' => now()]);
            $refused($account, '止めた台帳に預けた');
            CustomerAccount::query()->whereKey($account)->update(['disabled_at' => null]);

            // 会員のまま、まとめられた台帳にはならない（DB が断る。ADR 0044 の 2、2026-11-03）。お金の側の断りは 2 枚目の守りとして残す
            try {
                DB::transaction(static fn () => CustomerAccount::query()->whereKey($account)->update(['merged_into_id' => $other, 'merged_at' => now()]));
                $this->fail('会員の台帳がまとめられた');
            } catch (QueryException $e) {
                $this->assertStringContainsString('customer_accounts_merged_not_member', $e->getMessage());
            }
            $this->assertSame(0, PaymentAttempt::query()->where('transaction_id', $sale)->count());
            // 断ったときは預け先も作らない（確かめが先）
            $this->assertCount(0, $this->stripe->customers);

            // ふつうの画面が開いていても、預ける画面を頼めば別の画面を作る（混ぜない）
            $plain = $payments->startCheckout(Transaction::query()->findOrFail($sale), 'https://x/ok', 'https://x/back');
            $saving = $payments->startCheckout(Transaction::query()->findOrFail($sale), 'https://x/ok', 'https://x/back', saveCardFor: CustomerAccount::query()->findOrFail($account));
            $this->assertNotSame($plain->ref, $saving->ref);
            $this->assertSame($account, PaymentAttempt::query()->where('provider_ref', $saving->ref)->value('saves_card_for'));
            $this->assertSame($saving->ref, $payments->startCheckout(Transaction::query()->findOrFail($sale), 'https://x/ok', 'https://x/back', saveCardFor: CustomerAccount::query()->findOrFail($account))->ref);
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.customer_prepared')->count());
        });
    }

    public function test_test_mode_cards_are_not_used_after_switching_to_live_keys_and_detached_cards_stay_detached(): void
    {
        $card = $this->inside('spa', fn (): StoredPaymentMethod => $this->storedCard('hanako@example.com'));

        $this->inside('spa', function () use ($card): void {
            $stored = app(StoredPayments::class);
            $account = CustomerAccount::query()->findOrFail($card->customer_account_id);
            $testCustomer = $stored->customerFor($account);

            // 本番の鍵に替えると、テストの預け先とカードは使わない（新しい預け先を作る）。本番の環境と同じく本番の鍵を許す
            config(['payment.live_keys_allowed' => true]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'live', self::BODY.'1234']), 'webhook_secret' => $this->secret()]);
            $this->assertNull($stored->usableFor($account));
            $this->assertNotSame($testCustomer, $stored->customerFor($account));
            try {
                $stored->charge($card, $account->id, 100, 'JPY', 'round:live', 'txn');
                $this->fail('テストのカードで本番の請求をした');
            } catch (PaymentUnavailableException) {
            }
        });
    }

    private function storedCard(string $email): StoredPaymentMethod
    {
        $account = app(CustomerAccounts::class)->forEmail($email, 'storefront');
        $customer = app(StoredPayments::class)->customerFor($account);

        return StoredPaymentMethod::create(['customer_account_id' => $account->id, 'provider' => 'stripe', 'account' => 'test:own', 'customer_ref' => $customer, 'method_ref' => 'pm_test_'.bin2hex(random_bytes(6)), 'status' => 'usable', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2028]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function order(string $email, bool $member = true): array
    {
        $seller = Seller::query()->firstOrFail();
        $location = Location::query()->firstOrFail();
        $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
        app(StockService::class)->receive($listing, $location, 3);
        $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => $email]);
        $account = app(CustomerAccounts::class)->forEmail($email, 'storefront');
        // カードを預けるのは会員だけ（ADR 0049）
        if ($member) {
            CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);
        }
        $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test', customerAccount: $account);

        return [$sale->id, $account->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function completed(string $id, string $sale, string $intent): array
    {
        $ref = $this->inside('spa', fn (): string => (string) PaymentAttempt::query()->where('transaction_id', $sale)->value('provider_ref'));

        return ['id' => $id, 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false, 'created' => time(), 'data' => ['object' => [
            'id' => $ref, 'object' => 'checkout.session', 'amount_total' => 4400, 'currency' => 'jpy', 'client_reference_id' => $sale, 'payment_status' => 'paid', 'payment_intent' => $intent,
        ]]];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function deliver(array $event): TestResponse
    {
        $payload = (string) json_encode($event);
        $time = time();
        $signature = "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $this->secret());
        $response = $this->call('POST', 'http://spa.localhost/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
        tenancy()->end();

        return $response;
    }

    private function secret(): string
    {
        return implode('_', ['whsec', self::BODY.'spa']);
    }
}
