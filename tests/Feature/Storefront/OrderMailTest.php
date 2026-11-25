<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\OrderReader;
use App\Storefront\Orders\SendOrderMail;
use App\Storefront\Orders\SentMail;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * お客さんへの注文の知らせ。支払いの確定と発送で、キューで送る。ショップの注文だけ、同じ知らせは 1 回だけ、
 * 載せるのは注文番号・商品・合計・発送・照会のリンクだけ（住所・電話は載せない）。
 */
final class OrderMailTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'oma', 'name' => "Demo\r\nBcc: evil@example.com"]);
        $this->tenant->domains()->create(['domain' => 'oma.localhost']);
        $this->inside(fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]));
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_paying_and_shipping_queue_one_mail_each_after_the_write(): void
    {
        Queue::fake();
        [$sale, $ref, $fulfillment] = $this->order(storefront: true);

        $this->inside(function () use ($sale, $ref, $fulfillment): void {
            app(PaymentNotices::class)->confirmPaid('stripe', 'evt_1', 'checkout.session.completed', $ref, $sale, 4400, 'jpy', 'pi_1');
            // 同じ通知の二度目では、もう一度は積まない
            app(PaymentNotices::class)->confirmPaid('stripe', 'evt_1', 'checkout.session.completed', $ref, $sale, 4400, 'jpy', 'pi_1');
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale), $fulfillment, 'yamato', '1234-5678', 'test');
        });

        Queue::assertPushed(SendOrderMail::class, 2);
        Queue::assertPushed(SendOrderMail::class, fn (SendOrderMail $job): bool => $job->kind === 'order_paid' && $job->transactionId === $sale);
        Queue::assertPushed(SendOrderMail::class, fn (SendOrderMail $job): bool => $job->kind === 'order_shipped' && $job->fulfillmentId === $fulfillment);
    }

    public function test_the_mail_carries_only_what_the_customer_needs_and_is_sent_once(): void
    {
        Mail::fake();
        [$sale, $ref, $fulfillment] = $this->order(storefront: true);
        $this->inside(function () use ($sale, $ref, $fulfillment): void {
            app(PaymentNotices::class)->confirmPaid('stripe', 'evt_1', 'checkout.session.completed', $ref, $sale, 4400, 'jpy', 'pi_1');
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale), $fulfillment, 'yamato', '1234-5678', 'test');
        });

        // キューは同期で動く（テスト）。支払いと発送で 1 通ずつ
        Mail::assertSent(OrderMail::class, 2);
        $sent = [];
        Mail::assertSent(OrderMail::class, function (OrderMail $mail) use (&$sent): bool {
            $sent[$mail->kind] = $mail;

            return $mail->hasTo('hanako@example.com');
        });

        $paid = $sent['order_paid'];
        // 件名と差出人の名前に、店の名前の改行を持ち込まない（ヘッダの注入）
        $this->assertSame('[Demo Bcc: evil@example.com] ご注文ありがとうございます（ご注文番号 '.strtoupper($sale).'）', $paid->subjectLine());
        $this->assertStringNotContainsString("\n", (string) $paid->envelope()->from->name);
        $html = $paid->render();
        // 照会のリンクはショップのドメインで、期限と店の ID と署名つき（店の ID は から。ほかの店の同じ道筋では通さない）
        $this->assertMatchesRegularExpression('#href="http://oma\.localhost(:\d+)?/orders/'.$sale.'\?expires=\d+&amp;shop=oma&amp;signature=[0-9a-f]{64}"#', $html);
        foreach ([strtoupper($sale), '&lt;b&gt;ニット&lt;/b&gt; × 1', '¥4,400'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        foreach (['<b>ニット', '渋谷', '神宮前', '09012345678', '山田'] as $pii) {
            $this->assertStringNotContainsString($pii, $html, $pii);
        }
        $this->assertStringContainsString('お問い合わせ番号 1234-5678', $sent['order_shipped']->render());
        $this->assertStringContainsString('ヤマト運輸', $sent['order_shipped']->render());

        $this->inside(function () use ($sale): void {
            $this->assertSame(['order_paid', 'order_shipped'], SentMail::query()->orderBy('kind')->pluck('kind')->all());
            $this->assertSame(['sent', 'sent'], SentMail::query()->pluck('status')->all());
            // 同じ知らせをもう一度動かしても送らない
            (new SendOrderMail($sale, 'order_paid'))->handle(app(OrderReader::class));
        });
        Mail::assertSent(OrderMail::class, 2);
    }

    public function test_no_mail_for_orders_outside_the_shop_and_a_failed_send_is_retried(): void
    {
        Mail::fake();
        [$admin, $adminRef] = $this->order(storefront: false);
        $this->inside(fn () => app(PaymentNotices::class)->confirmPaid('stripe', 'evt_a', 'checkout.session.completed', $adminRef, $admin, 4400, 'jpy', 'pi_a'));
        Mail::assertNothingSent();

        // 送れなかった: 履歴の行は残さず（やり直しで送る）、例外をキューに返す
        [$sale] = $this->order(storefront: true);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
        $this->inside(function () use ($sale): void {
            try {
                (new SendOrderMail($sale, 'order_paid'))->handle(app(OrderReader::class));
                $this->fail('送れないのに失敗にならなかった');
            } catch (RuntimeException) {
                $this->assertSame(0, SentMail::query()->count());
            }
        });
    }

    /**
     * @return array{0: string, 1: string, 2: string} 取引・支払いの画面・受渡
     */
    private function order(bool $storefront): array
    {
        return $this->inside(function () use ($storefront): array {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '<b>ニット</b>', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '09012345678', 'address' => ['prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1']]);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');
            $line = $sale->lines()->where('line_type', LineType::Item)->sole();
            $fulfillment = app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => 1], $location, ['name' => '山田 花子', 'city' => '渋谷区'], 'test');

            if ($storefront) {
                CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);
            }

            return [$sale->id, app(PaymentService::class)->startCheckout($sale, 'x', 'y')->ref, $fulfillment->id];
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(Closure $work): mixed
    {
        tenancy()->initialize($this->tenant);

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }
}
