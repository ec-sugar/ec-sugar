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
use App\Core\Payment\Models\WebhookEvent;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * Stripe の通知（ADR 0019）。署名を確かめた通知だけで支払いを確定し、同じ通知の二度目は何もしない。
 * 金額などが合わない・二重に払われた・閉じた注文に届いたときは、支払いにせず記録する（人が返金を判断する）。
 */
final class StripeWebhookTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string BODY = 'FakeForTestsOnly000';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        FakeStripe::install();
        $this->shop('swa');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_signed_completion_records_the_payment_once(): void
    {
        [$sale, $attempt] = $this->checkout('swa');
        $event = $this->completed('evt_1', $attempt, $sale);

        $this->deliver('swa', $event)->assertOk()->assertExactJson(['received' => true])->assertCookieMissing('laravel_session');

        $this->inside('swa', function () use ($sale, $attempt): void {
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame('paid', PaymentAttempt::query()->findOrFail($attempt)->status);
            $journal = LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Payment)->sole();
            $this->assertSame('pi_test_1', $journal->reference);
            $received = DomainEvent::query()->where('type', 'payment.received')->sole();
            $this->assertEquals(['attempt' => $attempt, 'provider' => 'stripe', 'amount' => 4400], $received->payload);
            $this->assertSame('webhook:stripe', $received->actor);
            // 通知の中身（お客さんのメールなど）は残さない
            $this->assertSame(['provider' => 'stripe', 'event_id' => 'evt_1', 'type' => 'checkout.session.completed', 'outcome' => 'paid'], WebhookEvent::query()->sole()->only(['provider', 'event_id', 'type', 'outcome']));
        });

        // 同じ通知の送り直し、同じ画面の別の通知（後払いの「払われた」）。どちらも二度目の支払いにしない
        $this->deliver('swa', $event)->assertOk();
        $this->deliver('swa', $this->completed('evt_2', $attempt, $sale, type: 'checkout.session.async_payment_succeeded'))->assertOk();
        $this->inside('swa', function () use ($sale): void {
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Payment)->count());
            $this->assertSame(['already_paid', 'paid'], WebhookEvent::query()->orderBy('outcome')->pluck('outcome')->all());
        });
    }

    public function test_unsigned_forged_stale_and_foreign_notices_are_refused(): void
    {
        [$sale, $attempt] = $this->checkout('swa');
        $this->shop('swb');
        $event = $this->completed('evt_1', $attempt, $sale);
        $payload = (string) json_encode($event);

        foreach ([
            '署名なし' => null,
            '違う鍵' => $this->signature($payload, 'whsec_'.self::BODY.'9999'),
            '別の店の鍵' => $this->signature($payload, $this->webhookSecret('swb')),
            '5 分より古い' => $this->signature($payload, $this->webhookSecret('swa'), time() - 301),
            '壊れた署名' => 't='.time().',v1=zzz',
        ] as $case => $signature) {
            $this->raw('swa', $payload, $signature)->assertStatus(400);
            $this->assertNotPaid($sale, $case);
        }

        // 署名の後に金額を書き換えた
        $signature = $this->signature($payload, $this->webhookSecret('swa'));
        $tampered = str_replace('"amount_total":4400', '"amount_total":1', $payload);
        $this->raw('swa', $tampered, $signature)->assertStatus(400);
        // テスト用の鍵の店に本番の通知、形の違う中身
        $this->deliver('swa', ['livemode' => true] + $event)->assertStatus(400);
        $this->raw('swa', '{not json', $this->signature('{not json', $this->webhookSecret('swa')))->assertStatus(400);
        // 鍵を外した店
        $this->inside('swa', fn () => app(PaymentGatewayService::class)->removeStripe());
        $this->deliver('swa', $event, secret: 'whsec_'.self::BODY.'swa')->assertStatus(400);

        $this->assertNotPaid($sale, '全部');
        $this->inside('swa', fn () => $this->assertSame(0, WebhookEvent::query()->count()));
        // 中央のホストには受け口が無い
        $this->raw('', $payload, $signature)->assertNotFound();
    }

    public function test_notices_that_do_not_match_the_checkout_are_recorded_but_not_paid(): void
    {
        [$sale, $attempt] = $this->checkout('swa');

        foreach ([
            'evt_amount' => ['amount_total' => 4000],
            'evt_currency' => ['currency' => 'usd'],
            'evt_reference' => ['client_reference_id' => '01aaaaaaaaaaaaaaaaaaaaaaaa'],
            'evt_amount_text' => ['amount_total' => '4400'],
        ] as $id => $change) {
            $this->deliver('swa', $this->completed($id, $attempt, $sale, change: $change))->assertOk();
        }
        // 知らない支払いの画面、まだ払われていない（後払いの受付だけ）、扱わない種類
        $this->deliver('swa', $this->completed('evt_unknown', 'cs_test_unknown', $sale))->assertOk();
        $this->deliver('swa', $this->completed('evt_unpaid', $attempt, $sale, change: ['payment_status' => 'unpaid']))->assertOk();
        $this->deliver('swa', ['type' => 'charge.refunded'] + $this->completed('evt_other', $attempt, $sale))->assertOk();

        $this->assertNotPaid($sale, '合わない通知');
        $this->inside('swa', function (): void {
            $this->assertSame(4, DomainEvent::query()->where('type', 'payment.mismatch')->count());
            $this->assertEqualsCanonicalizing(
                ['mismatch', 'mismatch', 'mismatch', 'mismatch', 'unknown_session', 'ignored', 'ignored'],
                WebhookEvent::query()->pluck('outcome')->all(),
            );
        });
    }

    public function test_double_payments_late_payments_and_expiry_are_kept_for_a_person(): void
    {
        // 同じ注文の 2 つ目の支払いの画面でも払われた
        [$sale, $first] = $this->checkout('swa');
        $second = $this->inside('swa', function () use ($first, $sale): string {
            // 1 つ目の期限が近づいたので、2 つ目の画面を作った（両方の画面で払われうる）
            PaymentAttempt::query()->whereKey($first)->update(['expires_at' => now()->addMinute()]);
            app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($sale), 'x', 'y');

            return PaymentAttempt::query()->where('transaction_id', $sale)->whereKeyNot($first)->sole()->id;
        });
        $this->assertNotSame($first, $second);
        $this->deliver('swa', $this->completed('evt_a', $first, $sale))->assertOk();
        $this->deliver('swa', $this->completed('evt_b', $second, $sale))->assertOk();

        // 取消した注文に支払いが届いた
        [$cancelled, $late] = $this->checkout('swa');
        $this->inside('swa', fn () => app(TransactionService::class)->transition(Transaction::query()->findOrFail($cancelled), Phase::Cancel));
        $this->deliver('swa', $this->completed('evt_c', $late, $cancelled))->assertOk();

        // 期限が切れた
        [, $expired] = $this->checkout('swa');
        $this->deliver('swa', ['type' => 'checkout.session.expired'] + $this->completed('evt_d', $expired, $sale, change: ['payment_status' => 'unpaid', 'status' => 'expired']))->assertOk();

        $this->inside('swa', function () use ($sale, $cancelled, $expired): void {
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Payment)->count());
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $cancelled)->where('type', JournalType::Payment)->count());
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.duplicate_received')->count());
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.received_late')->count());
            $this->assertSame('expired', PaymentAttempt::query()->findOrFail($expired)->status);
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.checkout_expired')->count());
        });
    }

    /**
     * 店に鍵を置き、在庫のある商品の取引と支払いの画面を作る
     *
     * @return array{0: string, 1: string} 取引の ID と支払いの画面（試み）の ID
     */
    private function checkout(string $shop): array
    {
        return $this->inside($shop, function (): array {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com']);
            $sale = app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), $customer, [LineDraft::fromListing($listing, 1, 1000, Location::query()->firstOrFail())], actor: 'test');
            app(PaymentService::class)->startCheckout($sale, 'x', 'y');

            return [$sale->id, PaymentAttempt::query()->where('transaction_id', $sale->id)->sole()->id];
        });
    }

    /**
     * Stripe の checkout.session.completed の形（必要なところだけ）
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function completed(string $id, string $attempt, string $sale, string $type = 'checkout.session.completed', array $change = []): array
    {
        $ref = str_starts_with($attempt, 'cs_') ? $attempt : $this->inside('swa', fn (): string => PaymentAttempt::query()->findOrFail($attempt)->provider_ref);

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
                'client_reference_id' => $sale,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_1',
                'customer_details' => ['email' => 'hanako@example.com'],
                ...$change,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function deliver(string $shop, array $event, ?string $secret = null): TestResponse
    {
        $payload = (string) json_encode($event);

        return $this->raw($shop, $payload, $this->signature($payload, $secret ?? $this->webhookSecret($shop)));
    }

    /**
     * 中身をそのまま送る（Stripe と同じ。空の店の名前は中央のホスト）
     *
     * @return TestResponse<Response>
     */
    private function raw(string $shop, string $payload, ?string $signature): TestResponse
    {
        $host = $shop === '' ? 'localhost' : "{$shop}.localhost";
        $server = ['CONTENT_TYPE' => 'application/json'] + ($signature === null ? [] : ['HTTP_STRIPE_SIGNATURE' => $signature]);
        $response = $this->call('POST', "http://{$host}/webhooks/stripe", [], [], [], $server, $payload);
        tenancy()->end();

        return $response;
    }

    /**
     * Stripe と同じ署名（t=時刻,v1=HMAC-SHA256(鍵, "時刻.中身")）
     */
    private function signature(string $payload, string $secret, ?int $time = null): string
    {
        $time ??= time();

        return "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $secret);
    }

    private function webhookSecret(string $shop): string
    {
        return implode('_', ['whsec', self::BODY.$shop]);
    }

    private function assertNotPaid(string $sale, string $case): void
    {
        $this->inside('swa', function () use ($sale, $case): void {
            $this->assertSame(Phase::Agreement, Transaction::query()->findOrFail($sale)->phase, $case);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Payment)->count(), $case);
        });
    }

    private function shop(string $id): void
    {
        $this->tenant($id);
        $this->inside($id, fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => $this->webhookSecret($id)]));
    }
}
