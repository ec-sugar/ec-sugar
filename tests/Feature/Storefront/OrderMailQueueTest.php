<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\SentMail;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ショップで入れた注文のメールの仕事が、中央で動く送信担当（queue:work redis）に届くこと。
 * 本物の Redis を使う。店の文脈では Redis の鍵に店の接頭辞（前置きの文字）が付くので、キューの接続がそれに巻き込まれると
 * 仕事は店ごとの鍵に入り、送信担当からは見えない。開発とテストの既定（sync / database）では気づけないので、ここで Redis のキューにして確かめる。
 */
final class OrderMailQueueTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** ほかのテストや開発の送信担当（default のキューを見る）とぶつからない、このテストだけのキューの名前 */
    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueName = 't0068-'.Str::lower(Str::random(12));
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => $this->queueName,
        ]);
        FakeStripe::install();
        $this->tenant('omq', 'キューの店');
        $this->inside('omq', fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]));
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // このテストのキューの鍵を、店の接頭辞つきで迷い込んだものも含めて消す（名前は乱数なので、ほかの鍵には当たらない）
        // 鍵の数 0 で渡すので、探す形（パターン）に接続の接頭辞は付かない
        Redis::connection('default')->command('eval', [
            "for _, k in ipairs(redis.call('keys', ARGV[1])) do redis.call('del', k) end return 0",
            ['*queues:'.$this->queueName.'*'],
            0,
        ]);

        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_mail_queued_in_the_shop_reaches_the_central_worker_and_is_sent_in_the_shop(): void
    {
        $queueConnection = config('queue.connections.redis.connection');
        $this->assertNotContains($queueConnection, config('tenancy.redis.prefixed_connections'), 'キューの Redis の接続に店の接頭辞が付くと、ショップで入れた仕事が送信担当に見えない');

        Mail::fake();
        $sale = $this->paidInTheShop();

        // 中央の文脈（送信担当と同じ）から、仕事が 1 件だけ見える。仕事には店の ID が付いている
        $this->assertFalse(tenancy()->initialized);
        $queue = Queue::connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $queue);
        $this->assertSame(1, $queue->size(), 'ショップで入れた仕事が中央のキューに無い');
        $raw = $queue->getConnection()->lindex('queues:'.$this->queueName, 0);
        $this->assertIsString($raw);
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('omq', $payload['tenant_id'] ?? null);
        Mail::assertNothingSent();

        // 送信担当として 1 回だけ処理する。店の ID から店の文脈に入り、その店の注文を読んで送る
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => $this->queueName, '--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        Mail::assertSent(OrderMail::class, 1);
        Mail::assertSent(OrderMail::class, fn (OrderMail $mail): bool => $mail->hasTo('hanako@example.com') && $mail->kind === 'order_paid');
        // 処理の後は中央の文脈に戻り、仕事は待ち・遅れ・処理中のどれにも残らない（処理中の印が店の鍵に迷い込むと、時間切れで二重に送る）
        $this->assertFalse(tenancy()->initialized);
        $this->assertSame(0, $queue->size());
        $this->inside('omq', function () use ($sale): void {
            $this->assertSame(['sent'], SentMail::query()->where('transaction_id', $sale)->pluck('status')->all());
        });
    }

    /**
     * ショップの注文を作り、支払いの確定の知らせを店の文脈で受ける（ここでメールの仕事がキューに入る）
     */
    private function paidInTheShop(): string
    {
        return $this->inside('omq', function (): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');
            CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);
            $ref = app(PaymentService::class)->startCheckout($sale, 'x', 'y')->ref;

            app(PaymentNotices::class)->confirmPaid('stripe', 'evt_q1', 'checkout.session.completed', $ref, $sale->id, 4400, 'jpy', 'pi_q1');

            return $sale->id;
        });
    }
}
