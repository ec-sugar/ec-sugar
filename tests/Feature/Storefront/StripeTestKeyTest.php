<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 公開の前に試すテスト用の鍵（ADR 0096）。本番の鍵の店でも、準備中のあいだだけテスト用の鍵で注文を試せる。
 * 公開したら新しい支払いには使わない。返金・問い合わせは作ったときのモードの鍵。テスト用の鍵の通知は Stripe に問い合わせ直し、
 * 本番の支払いの試みには届かない。
 */
final class StripeTestKeyTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** 鍵の本体（テストの値。秘密情報の検知に掛からないよう、鍵の形はその場で組み立てる） */
    private const string BODY = 'FakeForTestsOnly000';

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        config(['payment.live_keys_allowed' => true]);
        $this->stripe = FakeStripe::install();
        $this->tenant('stk');
        $this->inside('stk', function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => self::key('live', 'LIVE'), 'webhook_secret' => self::whsec('live')]);
            app(PaymentGatewayService::class)->configureStripeTest(['secret_key' => self::key('test', 'TEST'), 'webhook_secret' => self::whsec('test')]);
            app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing);
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_preparing_shop_tries_orders_with_the_test_key_and_an_open_shop_uses_the_live_key(): void
    {
        // 準備中: テスト用の鍵で支払いの画面を作り、試みにテストのモードを残す
        [$sale, $attempt] = $this->checkout();
        $this->assertSame(self::key('test', 'TEST'), $this->lastKey());
        $this->inside('stk', fn () => $this->assertTrue(PaymentAttempt::query()->findOrFail($attempt)->test_mode));

        // 公開した: 同じ注文でも、テストで作った画面を使い回さず、本番の鍵で作り直す
        $this->inside('stk', function () use ($sale): void {
            app(StorefrontAccessService::class)->setStatus(ShopStatus::Open);
            app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($sale), 'x', 'y');
            $attempts = PaymentAttempt::query()->where('transaction_id', $sale)->orderBy('created_at')->get();
            $this->assertCount(2, $attempts);
            $this->assertSame([true, false], $attempts->pluck('test_mode')->all());
        });
        $this->assertSame(self::key('live', 'LIVE'), $this->lastKey());
    }

    public function test_the_test_key_is_never_used_once_open_and_only_test_keys_are_accepted(): void
    {
        $this->inside('stk', function (): void {
            $gateways = app(PaymentGatewayService::class);
            $this->assertTrue($gateways->credentials()?->testKey);
            $this->assertTrue($gateways->status()['test_key']['in_use']);

            // 公開した後は、ふだんの鍵が無ければ払えない（テスト用の鍵に落ちない）
            app(StorefrontAccessService::class)->setStatus(ShopStatus::Open);
            $this->assertSame('live', $gateways->credentials()->mode);
            $this->assertFalse($gateways->status()['test_key']['in_use']);
            $gateways->removeStripe();
            $this->assertNull($gateways->credentials());
            // 開設の手順の「支払い」は、テスト用の鍵だけでは済みにしない
            $this->assertNull($gateways->primaryMode());

            // テスト用の鍵の欄は、テストのモードの鍵だけ
            foreach ([self::key('live', 'LIVE'), 'rk_live_'.self::BODY.'1234', 'pk_test_'.self::BODY.'1234'] as $wrong) {
                try {
                    $gateways->configureStripeTest(['secret_key' => $wrong, 'webhook_secret' => self::whsec('test')]);
                    $this->fail('テスト用の鍵の欄に本番の鍵を受け付けた');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('secret_key', $e->errors());
                }
            }

            // DB でも、テスト用の鍵の行は test だけ
            try {
                DB::transaction(fn () => PaymentGateway::query()->where('provider', 'stripe_test')->update(['mode' => 'live']));
                $this->fail('テスト用の鍵の行を live にできた');
            } catch (QueryException $e) {
                $this->assertStringContainsString('payment_gateways_test_key_mode', $e->getMessage());
            }

            // 記録は代行の名前と test だけ（鍵は入れない）
            $event = DomainEvent::query()->where('type', 'payment.test_gateway_configured')->sole();
            $this->assertEquals(['provider' => 'stripe', 'mode' => 'test'], $event->payload);
            $gateways->removeStripeTest();
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.test_gateway_removed')->count());
        });
    }

    public function test_a_preparing_shop_without_a_test_key_still_refuses_live_checkouts(): void
    {
        $this->inside('stk', fn () => app(PaymentGatewayService::class)->removeStripeTest());

        $this->expectException(PaymentUnavailableException::class);
        $this->expectExceptionMessage(PaymentService::NOT_OPEN);
        $this->checkout();
    }

    public function test_notices_signed_with_the_test_key_are_refetched_from_stripe(): void
    {
        [$sale, $attempt] = $this->checkout();
        $event = $this->completed('evt_test_1', $attempt);

        // Stripe に同じ通知が無い（署名の鍵だけを持つ人が作った）: 受け取らない（Stripe なら送り直す）
        $this->deliver($event, self::whsec('test'))->assertStatus(400);
        $this->assertNotPaid($sale);

        // Stripe にある通知は、問い合わせ直した中身で処理する（テスト用の鍵で問い合わせる）
        $this->stripe->events['evt_test_1'] = $event;
        $this->deliver($event, self::whsec('test'))->assertOk();
        $this->assertSame(self::key('test', 'TEST'), $this->lastKey());
        $this->assertStringEndsWith('/v1/events/evt_test_1', $this->stripe->requests[array_key_last($this->stripe->requests) - 0]['url'] ?? '');
        $this->inside('stk', function () use ($sale): void {
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase);
        });

        // 公開した後も、試した注文の知らせ（期限切れ・返金など）はテスト用の鍵で受け取れる
        $this->inside('stk', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open));
        $again = $this->completed('evt_test_2', $attempt, 'checkout.session.async_payment_succeeded');
        $this->stripe->events['evt_test_2'] = $again;
        $this->deliver($again, self::whsec('test'))->assertOk();
    }

    public function test_a_notice_signed_with_the_test_key_never_reaches_a_live_payment(): void
    {
        // 公開した店の本番の注文（お客さんは支払いの画面の URL で画面の ID を知っている）
        $this->inside('stk', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open));
        [$sale, $attempt] = $this->checkout();
        $this->inside('stk', fn () => $this->assertFalse(PaymentAttempt::query()->findOrFail($attempt)->test_mode));
        $forged = $this->completed('evt_forged', $attempt);

        // テスト用の署名の鍵で作った「払われた」: Stripe に無いので断る。Stripe にあっても（テストのアカウントの通知）、試みのモードが違うので断る
        $this->deliver($forged, self::whsec('test'))->assertStatus(400);
        $this->stripe->events['evt_forged'] = $forged;
        $this->deliver($forged, self::whsec('test'))->assertStatus(400);
        // 本番の署名の鍵で livemode が false の通知も断る（取り違え）
        $this->deliver($forged, self::whsec('live'))->assertStatus(400);
        $this->assertNotPaid($sale);

        // 本番の鍵の本番の通知は今までどおり受け取る
        $this->deliver(['livemode' => true] + $forged, self::whsec('live'))->assertOk();
        $this->inside('stk', fn () => $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase));
    }

    public function test_refunds_and_lookups_use_the_key_of_the_mode_the_attempt_was_made_in(): void
    {
        [, $test] = $this->checkout();
        $this->inside('stk', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open));
        [, $live] = $this->checkout();

        $this->inside('stk', function () use ($test, $live): void {
            $gateways = app(PaymentGatewayService::class);
            $this->assertSame(self::key('test', 'TEST'), $gateways->credentialsForAttempt(PaymentAttempt::query()->findOrFail($test))?->secretKey);
            $this->assertSame(self::key('live', 'LIVE'), $gateways->credentialsForAttempt(PaymentAttempt::query()->findOrFail($live))?->secretKey);

            // テスト用の鍵を外しても、ふだんの鍵が本番なら、テストの試みに本番の鍵を使わない（Stripe で取り違えない）
            $gateways->removeStripeTest();
            $this->assertNull($gateways->credentialsForAttempt(PaymentAttempt::query()->findOrFail($test)));
            // ふだんの鍵をテストのモードに替えると、本番の試みには使わない（前はそのとき置いてある鍵で問い合わせていた）
            $gateways->configureStripe(['secret_key' => self::key('test', 'MAIN'), 'webhook_secret' => self::whsec('main')]);
            $this->assertSame(self::key('test', 'MAIN'), $gateways->credentialsForAttempt(PaymentAttempt::query()->findOrFail($test))?->secretKey);
            $this->assertNull($gateways->credentialsForAttempt(PaymentAttempt::query()->findOrFail($live)));
        });
    }

    /**
     * @return array{0: string, 1: string} [注文, 支払いの試み]
     */
    private function checkout(): array
    {
        return $this->inside('stk', function (): array {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com']);
            $sale = app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), $customer, [LineDraft::fromListing($listing, 1, 1000, Location::query()->firstOrFail())], actor: 'test');
            app(PaymentService::class)->startCheckout($sale, 'x', 'y');

            return [$sale->id, PaymentAttempt::query()->where('transaction_id', $sale->id)->sole()->id];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function completed(string $id, string $attempt, string $type = 'checkout.session.completed'): array
    {
        [$ref, $sale] = $this->inside('stk', fn (): array => [PaymentAttempt::query()->findOrFail($attempt)->provider_ref, PaymentAttempt::query()->findOrFail($attempt)->transaction_id]);

        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'created' => time(),
            'data' => ['object' => [
                'id' => $ref,
                'object' => 'checkout.session',
                'amount_total' => 4400,
                'currency' => 'jpy',
                'payment_status' => 'paid',
                'client_reference_id' => $sale,
                'payment_intent' => 'pi_'.substr(md5($id), 0, 12),
                'metadata' => ['tenant' => 'stk'],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function deliver(array $event, string $secret): TestResponse
    {
        $payload = (string) json_encode($event);
        $time = time();
        $response = $this->call('POST', 'http://stk.localhost/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $secret)], $payload);
        tenancy()->end();

        return $response;
    }

    private function assertNotPaid(string $sale): void
    {
        $this->inside('stk', function () use ($sale): void {
            $this->assertSame(Phase::Agreement, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Payment)->count());
        });
    }

    /**
     * 最後に Stripe へ送った要求の鍵（Authorization: Bearer の値）
     */
    private function lastKey(): ?string
    {
        $header = $this->stripe->lastHeader('Authorization');

        return $header === null ? null : preg_replace('/\ABearer /', '', $header);
    }

    private static function key(string $mode, string $tag): string
    {
        return implode('_', ['sk', $mode, self::BODY.$tag.'1234']);
    }

    private static function whsec(string $tag): string
    {
        return implode('_', ['whsec', self::BODY.$tag]);
    }
}
