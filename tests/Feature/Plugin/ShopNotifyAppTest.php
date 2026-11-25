<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\OperatorAccess;
use App\Core\Inventory\Enums\AdjustmentReason;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Events\PaymentAwaiting;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Models\PluginSetting;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Checkout\CheckoutOrder;
use EcSugar\PluginShopNotify\Mail\ShopNoticeMail;
use EcSugar\PluginShopNotify\Models\ShopNotice;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「店への知らせ」（ADR 0060 の 2）。注文が入った・取り消された・在庫が少なくなったを Slack か店のメールへ。
 * 中身に個人情報が無い・宛先は hooks.slack.com だけ・送れなくても注文は通る・同じ知らせは 1 回・使わない店では何もしない・試しの 1 通と回数の上限
 */
final class ShopNotifyAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private ?string $cart = null;

    /** 偽の Slack の答え（error は 500・down はつながらない・ok は届く） */
    private string $slackReply = 'error';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        Http::preventStrayRequests();
        FakeStripe::install();
        $this->configureAdminHost();
        $this->tenant('sna', 'みどり雑貨');
        $this->inside('sna', function (): void {
            app(PluginStates::class)->set('shop-notify', true, 'test');
            // 管理画面の保存と同じく、切り替えも一緒に送る（秘密の項目だけを最初に保存すると、核の PluginSettings が値の列を [] で書いて DB の制約に当たる。報告済み）
            app(PluginSettings::class)->update('shop-notify', ['slack_webhook_url' => self::slack(), 'mail_to' => 'shop@example.com', 'on_paid' => true], 'test');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_only_the_slack_webhook_host_is_accepted_and_the_destinations_are_never_shown(): void
    {
        $this->inside('sna', function (): void {
            foreach ([
                'https://evil.example/services/T0/B0/x',
                'https://hooks.slack.com.evil.example/services/T0/B0/x',
                'http://hooks.slack.com/services/T0/B0/x',
                'https://hooks.slack.com/services/T0/B0/x?redirect=https://evil.example',
                'https://hooks.slack.com@evil.example/services/T0/B0/x',
                'https://hooks.slack.com/workflows/T0/B0/x',
            ] as $url) {
                try {
                    app(PluginSettings::class)->update('shop-notify', ['slack_webhook_url' => $url], 'test');
                    $this->fail("受けてしまった: {$url}");
                } catch (ValidationException $error) {
                    $this->assertArrayHasKey('values.slack_webhook_url', $error->errors(), $url);
                }
            }

        });

        // 2 枚目の守り: 保存の確かめをすり抜けて別の宛先が入っていても、送る直前の確かめで送らない（メールだけ送る）
        Http::fake();
        $this->inside('sna', function (): void {
            $row = PluginSetting::query()->where('plugin', 'shop-notify')->sole();
            $row->forceFill(['secrets' => ['slack_webhook_url' => 'https://evil.example/services/T0/B0/x', 'mail_to' => 'shop@example.com']])->save();
            event(new PaymentConfirmed($this->openSale(500, paid: true)->id));
            $this->assertSame(['mail'], ShopNotice::query()->pluck('channel')->all());
        });
        Http::assertNothingSent();

        // 管理画面には「入っているか」だけ（URL もメールも返さない）
        $this->enrolledOperator();
        $body = (string) $this->send('GET', '/api/tenants/sna/plugins')->assertOk()->getContent();
        $this->assertStringNotContainsString('hooks.slack.com/services/T0TEST', $body);
        $this->assertStringNotContainsString('shop@example.com', $body);
    }

    public function test_a_paid_order_is_told_once_to_slack_and_mail_without_personal_data(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $order = $this->paidOrder();

        // 2 回届いても（Webhook と問い合わせの両方で確かめた時など）、送るのは宛先ごとに 1 回
        $this->inside('sna', fn () => event(new PaymentConfirmed($order)));
        $this->inside('sna', fn () => event(new PaymentConfirmed($order)));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($order): bool {
            $text = (string) $request['text'];
            $this->assertSame(self::slack(), $request->url());
            $this->assertStringContainsString('注文が入りました（¥5,400）', $text);
            $this->assertStringContainsString('注文番号: '.strtoupper($order), $text);
            $this->assertStringContainsString('（3 点）', $text);
            $this->assertStringContainsString("<http://admin.localhost/tenants/sna/transactions/{$order}|管理画面で注文を見る>", $text);
            // 店の名前は出し、お客さんの名前・メール・住所・電話は出さない
            $this->assertStringContainsString('[みどり雑貨]', $text);
            foreach (['山田', '花子', 'hanako@', '渋谷', '09012345678'] as $personal) {
                $this->assertStringNotContainsString($personal, $text);
            }

            return true;
        });
        Mail::assertSent(ShopNoticeMail::class, function (ShopNoticeMail $mail) use ($order): bool {
            $this->assertTrue($mail->hasTo('shop@example.com'));
            $text = implode("\n", $mail->lines);
            $this->assertStringContainsString(strtoupper($order), $text);
            $this->assertStringNotContainsString('山田', $text.$mail->subjectLine);

            return $mail->subjectLine === '[みどり雑貨] 注文が入りました（¥5,400）';
        });
        $this->assertSame(1, Mail::sent(ShopNoticeMail::class)->count());

        // 記録にも個人情報と宛先を残さない
        $rows = $this->inside('sna', fn () => ShopNotice::query()->orderBy('channel')->get());
        $this->assertSame([['mail', 'sent'], ['slack', 'sent']], $rows->map(fn (ShopNotice $n): array => [$n->channel, $n->status])->all());
        $this->assertSame(['amount', 'items', 'link', 'number', 'transaction_id'], array_values(collect($rows[0]->facts)->keys()->sort()->all()));
        $this->assertStringNotContainsString('hooks.slack.com', $this->inside('sna', fn (): string => (string) json_encode(ShopNotice::query()->get()->toArray())));
    }

    public function test_a_bank_transfer_order_is_told_when_it_comes_in_awaiting_payment_and_its_lapse_too(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $order = $this->inside('sna', function (): string {
            $sale = $this->openSale(1800, quantity: 3);
            PaymentAttempt::create(['transaction_id' => $sale->id, 'provider' => 'direct', 'provider_ref' => 'direct-'.$sale->id, 'checkout_url' => '', 'amount' => (int) $sale->total_amount,
                'currency' => 'JPY', 'status' => 'awaiting', 'expires_at' => now()->addDays(7), 'pay_by' => now()->addDays(7), 'kind' => 'direct', 'methods' => 'bank_transfer', 'method' => 'bank_transfer']);

            return $sale->id;
        });

        // 銀行振込の入金待ち（ADR 0074）: 「注文が入った（入金待ち）」を 1 回
        $this->inside('sna', fn () => event(new PaymentAwaiting($order)));
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $text = (string) $request['text'];
            $this->assertStringContainsString('注文が入りました（¥5,400・入金待ち）', $text);
            $this->assertStringContainsString('お支払い: 銀行振込（入金待ち', $text);

            return true;
        });

        // 入金を確かめても重ねては送らない。コンビニ払いの入金待ち（店が受け取る支払いでない）は今までどおり送らない
        $this->inside('sna', fn () => event(new PaymentConfirmed($order)));
        Http::assertSentCount(1);
        $konbini = $this->inside('sna', fn (): string => $this->openSale(500)->id);
        $this->inside('sna', fn () => event(new PaymentAwaiting($konbini)));
        Http::assertSentCount(1);

        // 期限切れで取り消すと「取り消されました」（受け付けた注文として）
        $this->inside('sna', fn () => app(TransactionService::class)->transition(Transaction::query()->findOrFail($order), Phase::Cancel, 'system:expiry'));
        Http::assertSentCount(2);
    }

    public function test_a_failing_destination_never_stops_the_order_and_is_shown_as_not_sent(): void
    {
        // Slack が 500 を返す・メールは届く。注文は受け付けられ、Slack の記録は「送れなかった」
        Http::fake(['hooks.slack.com/*' => function () {
            return match ($this->slackReply) {
                'down' => throw new ConnectionException('down'),
                'ok' => Http::response('ok'),
                default => Http::response('server error', 500),
            };
        }]);
        $this->inside('sna', function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 0, 'free_over' => null], 'test');
            app(ShopSettingsService::class)->setSellFreeItems(true, 'test');
        });
        $sample = $this->listing('試供品', 0, 5);
        $order = $this->placeFree($sample);

        $this->inside('sna', function () use ($order): void {
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($order)->phase);
            $slack = ShopNotice::query()->where('channel', 'slack')->sole();
            $this->assertSame(['paid', 'failed', 'http_500', 1], [$slack->kind, $slack->status, $slack->error, $slack->attempts]);
            $this->assertSame('sent', ShopNotice::query()->where('channel', 'mail')->value('status'));
        });
        Mail::assertSent(ShopNoticeMail::class, fn (ShopNoticeMail $mail): bool => $mail->subjectLine === '[みどり雑貨] 注文を受け付けました（0 円）');

        // つながらない時も同じ（注文は止めない）
        $this->slackReply = 'down';
        $second = $this->placeFree($sample);
        $this->inside('sna', fn () => $this->assertSame('connection', ShopNotice::query()->where('kind', 'paid')->where('dedupe_key', $second)->where('channel', 'slack')->value('error')));

        // 取り消すと知らせる（受け付けていた 0 円の注文）。払われていない注文の取消は知らせない
        $this->slackReply = 'ok';
        $this->inside('sna', fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order), 'operator:test'));
        $unpaid = $this->inside('sna', function (): string {
            $sale = $this->openSale(1000);

            return $sale->id;
        });
        $this->inside('sna', fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($unpaid), 'operator:test'));
        $this->inside('sna', function () use ($order, $unpaid): void {
            $this->assertSame(['mail', 'slack'], ShopNotice::query()->where('kind', 'cancelled')->where('dedupe_key', $order)->orderBy('channel')->pluck('channel')->all());
            $this->assertSame(0, ShopNotice::query()->where('dedupe_key', $unpaid)->count());
        });
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], '注文が取り消されました'));
    }

    public function test_low_stock_is_told_once_until_it_comes_back_and_titles_cannot_mention_everyone(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $mug = $this->listing('<!channel> & <b>マグ</b>', 1200, 5);
        $adjust = fn (int $delta) => $this->inside('sna', function () use ($mug, $delta): void {
            $listing = Listing::query()->findOrFail($mug);
            $delta > 0
                ? app(InventoryOperations::class)->receive($listing, Location::query()->firstOrFail(), $delta, 'operator:test')
                : app(InventoryOperations::class)->adjust($listing, Location::query()->firstOrFail(), $delta, AdjustmentReason::Damage, 'operator:test');
        });

        $adjust(-1); // 4 点（しきい値 3 より多い）
        $this->assertSame(0, $this->lowStockSlacks());
        $adjust(-1); // 3 点 → 知らせる
        $adjust(-2); // 1 点 → もう知らせない
        $this->assertSame(1, $this->lowStockSlacks());
        Http::assertSent(function (Request $request): bool {
            $text = (string) $request['text'];

            // Slack の書式の特別な文字は逃がす（商品名で全員への呼び出しやリンクを作らせない）
            return str_contains($text, '商品: &lt;!channel&gt; &amp; &lt;b&gt;マグ&lt;/b&gt;') && str_contains($text, '注文できる在庫: 3 点（お知らせの基準: 3 点以下）') && ! str_contains($text, '<!channel>');
        });

        $adjust(5);  // 6 点 → 印を消す
        $adjust(-4); // 2 点 → もう 1 回
        $this->assertSame(2, $this->lowStockSlacks());

        // 切り替えを切ると送らない
        $this->inside('sna', fn () => app(PluginSettings::class)->update('shop-notify', ['on_low_stock' => false], 'test'));
        $adjust(5);
        $adjust(-5);
        $this->assertSame(2, $this->lowStockSlacks());
    }

    public function test_a_shop_that_does_not_use_it_sends_nothing(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $this->tenant('snb');
        $this->inside('snb', function (): void {
            // 設定は入れたが、使っていない
            app(PluginStates::class)->set('shop-notify', true, 'test');
            app(PluginSettings::class)->update('shop-notify', ['slack_webhook_url' => self::slack(), 'on_paid' => true], 'test');
            app(PluginStates::class)->set('shop-notify', false, 'test');
            $sale = $this->openSale(800, paid: true);
            event(new PaymentConfirmed($sale->id));
            $this->assertSame(0, ShopNotice::query()->count());
        });
        Http::assertNothingSent();

        // 使っている店でも、宛先が無ければ何も送らない
        $this->inside('sna', function (): void {
            app(PluginSettings::class)->update('shop-notify', [], 'test', ['slack_webhook_url', 'mail_to']);
            event(new PaymentConfirmed($this->openSale(800, paid: true)->id));
            $this->assertSame(0, ShopNotice::query()->count());
        });
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_the_admin_can_send_one_test_notice_with_a_limit_and_staff_need_operate(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'sna', 'read', 'test');
        $base = '/api/tenants/sna/apps/shop-notify/records';

        // 閲覧は一覧だけ。送るのは運用から
        $this->send('GET', $base)->assertOk();
        $this->send('POST', $base, ['values' => ['channel' => 'slack']])->assertForbidden();
        Http::assertNothingSent();

        $access->grant($staff, 'sna', 'operate', 'test');
        $id = $this->send('POST', $base, ['values' => ['channel' => 'slack']])->assertCreated()->json('id');
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'テストのお知らせです'));
        $this->send('GET', "{$base}/{$id}")->assertOk()->assertJsonFragment(['label' => '送信済み']);
        Mail::assertNothingSent();

        // 10 分に 5 回まで
        for ($i = 0; $i < 4; $i++) {
            $this->send('POST', $base, ['values' => ['channel' => 'all']])->assertCreated();
        }
        $this->send('POST', $base, ['values' => ['channel' => 'slack']])->assertUnprocessable()->assertJsonValidationErrors(['values.channel']);
        Http::assertSentCount(5);

        // 宛先が無ければ断る
        $this->travel(11)->minutes();
        $this->inside('sna', fn () => app(PluginSettings::class)->update('shop-notify', [], 'test', ['mail_to']));
        $this->send('POST', $base, ['values' => ['channel' => 'mail']])->assertUnprocessable()->assertJsonValidationErrors(['values.channel']);
    }

    private static function slack(): string
    {
        // 本物の形の短い見本（秘密の検知に当たらない長さ）
        return implode('/', ['https://hooks.slack.com/services', 'T0TEST', 'B0TEST', 'fakefake']);
    }

    private function lowStockSlacks(): int
    {
        return $this->inside('sna', fn (): int => ShopNotice::query()->where('kind', 'low_stock')->where('channel', 'slack')->where('status', 'sent')->count());
    }

    /**
     * 払いの確かになった注文（¥1,800 × 3 点。お客さんの名前・メール・住所つき）
     */
    private function paidOrder(): string
    {
        return $this->inside('sna', fn (): string => $this->openSale(1800, quantity: 3, paid: true)->id);
    }

    private function openSale(int $price, int $quantity = 1, bool $paid = false): Transaction
    {
        $seller = Seller::query()->firstOrFail();
        $store = Location::query()->firstOrFail();
        $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '帆布のトート', 'unit_amount' => $price, 'status' => ListingStatus::Active]);
        app(StockService::class)->receive($listing, $store, 50);
        $buyer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '09012345678', 'address' => ['prefecture' => '東京都', 'city' => '渋谷区']]);
        $sale = app(TransactionService::class)->open('sale', $seller, $buyer, [LineDraft::fromListing($listing, $quantity, 1000, $store)], actor: 'system');

        if ($paid) {
            app(LedgerService::class)->recordPayment($sale, $sale->total_amount, PaymentRoute::Platform, 'cs_live_'.$sale->id, 'webhook:stripe');
            $sale = app(TransactionService::class)->transition($sale, Phase::Payment);
        }

        return $sale;
    }

    private function listing(string $title, int $amount, int $stock): string
    {
        return $this->inside('sna', function () use ($title, $amount, $stock): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), $stock);

            return $listing->id;
        });
    }

    private function placeFree(string $listing): string
    {
        $this->shop('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $this->shop('POST', '/checkout', ['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => ''], 'same_as_buyer' => '1']);
        $confirm = (string) $this->shop('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->shop('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertRedirect('http://sna.localhost/checkout/complete');

        return $this->inside('sna', fn (): string => (string) CheckoutOrder::query()->latest('created_at')->orderByDesc('id')->value('transaction_id'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        $response = $method === 'GET' ? $this->get("http://sna.localhost{$path}") : $this->post("http://sna.localhost{$path}", $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;

        return $response;
    }
}
