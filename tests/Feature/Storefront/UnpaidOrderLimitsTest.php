<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Admin\Models\AuditEvent;
use App\Core\Crypto\BlindIndex;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\DirectPayments;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PaymentChoices;
use App\Storefront\Checkout\PaymentOffer;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Checkout\UnpaidOrderLimits;
use App\Storefront\Members\Member;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * まだ払われていない注文の回数の上限（ADR 0085）。
 * 1 時間の確定の回数（接続元ごと・メールごと。0 円の注文は今の 3 回のまま）、同じメール・同じ接続元の銀行振込・代引きの入金待ちの数、
 * 店の設定「銀行振込・代金引換は会員だけ」。どれも注文の入力・確かめの画面・確定（錠の中）で同じ答えになるか、断った試みが監査ログに回数だけ残るか
 */
final class UnpaidOrderLimitsTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private ?string $token = null;

    private string $knit;

    private string $ip = '192.0.2.10';

    /** このテストの前の監査ログの最後の番号（監査ログは追記だけで、テストの間も消えない） */
    private int $auditFrom = 0;

    /** @var array<string, mixed> */
    private array $valid = [
        'buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
        'same_as_buyer' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Queue::fake();
        $this->auditFrom = (int) AuditEvent::query()->max('sequence');
        FakeStripe::install();
        config(['payment.awaiting_grace_hours' => 24]);
        // 2026-11-05（木）10:00（日本の時間）
        $this->travelTo(new CarbonImmutable('2026-11-05 10:00:00', 'Asia/Tokyo'));
        $this->tenant = Tenant::create(['id' => 'ulm', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'ulm.localhost']);
        $this->knit = $this->inside(function (): string {
            $knit = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($knit, Location::query()->orderBy('id')->firstOrFail(), 50);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => null]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(DirectMethodSettings::class)->update($this->directSettings(), 'test');

            return $knit->id;
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->app['auth']->forgetGuards();
        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_paid_orders_through_the_checkout_are_limited_per_hour_and_the_free_limit_stays(): void
    {
        // Stripe の注文（払われるまで最長 180 分押さえる）: 同じメール・同じ接続元から 1 時間に 5 回まで。ちょうど 5 回目は通る
        for ($i = 1; $i <= UnpaidOrderLimits::ORDERS_PER_EMAIL_HOUR; $i++) {
            $this->order('stripe')->assertStatus(303)->assertRedirectContains('checkout.stripe.com');
        }

        // 6 回目（1 つ越え）は確かめの画面へ戻して理由を出し、注文を作らない
        $this->order('stripe')->assertRedirect('http://ulm.localhost/checkout/confirm');
        $this->assertStringContainsString(UnpaidOrderLimits::TOO_MANY, (string) $this->send('GET', '/checkout/confirm')->getContent());
        $this->inside(fn () => $this->assertSame(5, Transaction::query()->count()));

        // 大文字・前後の空白を変えても同じメール
        $this->order('stripe', ['email' => '  HANAKO@Example.COM '])->assertRedirect('http://ulm.localhost/checkout/confirm');
        // 同じ接続元でもメールが違えば、接続元の上限（20 回）までは通る
        $this->order('stripe', ['email' => 'taro@example.com'])->assertStatus(303)->assertRedirectContains('checkout.stripe.com');
        // 接続元が違えば、元のメールでも接続元の数は別。ただしメールの数は同じなので断る
        $this->ip = '198.51.100.20';
        $this->order('stripe')->assertRedirect('http://ulm.localhost/checkout/confirm');
        $this->inside(fn () => $this->assertSame(6, Transaction::query()->count()));

        // 窓の前後: 59 分はまだ断り、61 分たてば通る
        $this->ip = '192.0.2.10';
        $this->travel(59)->minutes();
        $this->order('stripe')->assertRedirect('http://ulm.localhost/checkout/confirm');
        $this->travel(2)->minutes();
        $this->order('stripe')->assertStatus(303)->assertRedirectContains('checkout.stripe.com');
        $this->inside(fn () => $this->assertSame(7, Transaction::query()->count()));

        // 断った試みは監査ログに回数だけ（ここでは 4 回断ったので 1 回目だけ。10 回目は下のテスト）。接続元・メールは入れない
        $records = $this->audits()->orderBy('sequence')->get();
        $this->assertSame([[UnpaidOrderLimits::RULE_ORDERS, 1]], $records->map(static fn (AuditEvent $e): array => [$e->data['rule'], $e->data['refused']])->all());
        $record = $records->sole();
        $this->assertSame('ulm', $record->tenant_id);
        $this->assertNull($record->ip);
        $this->assertNull($record->operator_id);
        $this->assertStringNotContainsStringIgnoringCase('hanako', (string) json_encode($record->data));
        $this->assertStringNotContainsString('192.0.2', (string) json_encode($record->data));
    }

    public function test_the_hourly_source_counter_has_exact_boundaries_and_counts_ipv6_in_two_steps(): void
    {
        $this->inside(function (): void {
            $limits = app(UnpaidOrderLimits::class);

            // 接続元ごと 20 回: 20 回は通り、21 回目で断る
            for ($i = 1; $i <= UnpaidOrderLimits::ORDERS_PER_SOURCE_HOUR; $i++) {
                $this->assertNull($limits->hitSource('203.0.113.5', false), "{$i} 回目");
            }
            $this->assertSame(UnpaidOrderLimits::TOO_MANY, $limits->hitSource('203.0.113.5', false));
            // IPv6 は /64 にまとめる（後ろを変えても同じ接続元）
            for ($i = 1; $i <= UnpaidOrderLimits::ORDERS_PER_SOURCE_HOUR; $i++) {
                $this->assertNull($limits->hitSource("2001:db8:1:2::{$i}", false));
            }
            $this->assertSame(UnpaidOrderLimits::TOO_MANY, $limits->hitSource('2001:db8:1:2:ffff::1', false));
            // 2 段目の /48: /64 を替えても、同じ /48 からは 60 回まで（断った試みも数える。ここまで 21 回、あと 39 回は通り、61 回目で断る）
            for ($i = 1; $i <= 39; $i++) {
                $this->assertNull($limits->hitSource('2001:db8:1:'.(3 + intdiv($i - 1, 13)).'::1', false), "/48 の {$i} 回目");
            }
            $this->assertSame(UnpaidOrderLimits::TOO_MANY, $limits->hitSource('2001:db8:1:9::1', false));
            $this->assertNull($limits->hitSource('2001:db8:2:5::1', false));

            // 0 円の注文は今までどおり 3 回（払う額のある注文の数とは別）
            for ($i = 1; $i <= UnpaidOrderLimits::FREE_PER_HOUR; $i++) {
                $this->assertNull($limits->hitSource('198.51.100.1', true));
            }
            $this->assertSame(UnpaidOrderLimits::FREE_TOO_MANY, $limits->hitSource('198.51.100.1', true));
            $this->assertNull($limits->hitSource('198.51.100.1', false));

            // 数えるキーに平文の IP アドレスを入れない（キーは店の鍵の HMAC）。メールはキャッシュでは数えない
            $this->assertSame(UnpaidOrderLimits::ORDERS_PER_SOURCE_HOUR + 1, RateLimiter::attempts('unpaid-order|ulm|orders|source|'.$limits->sourceIndex('203.0.113.5')));
            $this->assertSame(0, RateLimiter::attempts('unpaid-order|ulm|orders|source|203.0.113.5'));

            // 断った回数は決まりごとに 1 回目と 10 回目に記録（払う額のある注文は今 3 回断った。あと 7 回で 10 回目）
            for ($i = 0; $i < 7; $i++) {
                $this->assertSame(UnpaidOrderLimits::TOO_MANY, $limits->hitSource('203.0.113.5', false));
            }
        });

        $this->assertEqualsCanonicalizing([[UnpaidOrderLimits::RULE_ORDERS, 1], [UnpaidOrderLimits::RULE_ORDERS, 10], [UnpaidOrderLimits::RULE_FREE, 1]],
            $this->audits()->get()->map(static fn (AuditEvent $e): array => [$e->data['rule'], $e->data['refused']])->all());
    }

    public function test_when_the_counter_store_is_down_orders_are_refused_with_a_fixed_message(): void
    {
        $this->inside(function (): void {
            // 数えるのは核の Throttle（RateLimiter::increment）。差し替えた AtomicRateLimiter は final なので、Laravel の RateLimiter の偽物と入れ替える
            $down = \Mockery::mock(\Illuminate\Cache\RateLimiter::class);
            $down->shouldReceive('increment')->andThrow(new \RuntimeException('保存先に届かない'));
            RateLimiter::swap($down);

            // 500 にせず、数えられないまま通さない（決まった断りの文）。断った記録の失敗でも落ちない
            $this->assertSame(UnpaidOrderLimits::UNAVAILABLE, app(UnpaidOrderLimits::class)->hitSource('203.0.113.5', false));
            app(UnpaidOrderLimits::class)->refused(UnpaidOrderLimits::RULE_ORDERS);
        });
    }

    public function test_one_email_can_hold_three_unpaid_direct_orders_until_one_is_paid_or_lapses(): void
    {
        $this->inside(function (): void {
            [$first, $second] = [$this->place($this->cart(), 'bank_transfer'), $this->place($this->cart(), 'cod')];
            $this->place($this->cart(), 'bank_transfer', email: 'Hanako@EXAMPLE.com');

            // 4 つ目: 銀行振込・代引きは理由を添えて選べない（決済の画面で選ぶ手段は選べる）。メールの大文字小文字・空白は同じに数える
            $offer = $this->offer('hanako@example.com');
            $this->assertSame([false, false, true], [$offer->allows('bank_transfer'), $offer->allows('cod'), $offer->allows('stripe')]);
            $this->assertSame([UnpaidOrderLimits::PENDING, UnpaidOrderLimits::PENDING], [$offer->reason('bank_transfer'), $offer->reason('cod')]);
            $this->assertSame(UnpaidOrderLimits::PENDING, app(UnpaidOrderLimits::class)->pendingProblem('  HANAKO@example.COM ', '198.51.100.99'));
            $this->expectRefused(fn () => $this->place($this->cart(), 'cod'));
            $this->expectRefused(fn () => $this->place($this->cart(), 'bank_transfer', email: 'HANAKO@example.com'));
            // カードなど（Stripe）では注文できる
            $this->assertSame('checkout', CheckoutOrder::query()->where('transaction_id', $this->place($this->cart(), 'stripe')->id)->value('pays_with'));
            // ほかのメールは別に数える（接続元の数は 3 で、上限 5 の下）
            $this->assertTrue($this->offer('taro@example.com')->allows('bank_transfer'));

            // 払われたら数から外れる（代引きの入金の確認）
            app(DirectPayments::class)->confirm($second, (int) $second->total_amount, false, 'operator:test');
            $this->assertTrue($this->offer('hanako@example.com')->allows('cod'));
            $third = $this->place($this->cart(), 'cod');
            $this->assertFalse($this->offer('hanako@example.com')->allows('bank_transfer'));

            // 店の人が取り消したら外れる
            app(SaleOrderService::class)->cancel($third, 'operator:test');
            $this->assertTrue($this->offer('hanako@example.com')->allows('bank_transfer'));
            // （同じメールの 1 時間の確定は 5 回まで。ここまでに 5 回確定したので 1 時間あける）
            $this->travel(61)->minutes();
            $this->place($this->cart(), 'bank_transfer');
            $this->assertFalse($this->offer('hanako@example.com')->allows('bank_transfer'));

            // 振込の期限（11-12 23:59:59）と猶予 24 時間を過ぎたら、見回りの前でも外れる（ちょうどの 1 秒前はまだ数える）
            $this->travelTo(new CarbonImmutable('2026-11-13 23:59:58', 'Asia/Tokyo'));
            $this->assertFalse($this->offer('hanako@example.com')->allows('bank_transfer'));
            $this->travelTo(new CarbonImmutable('2026-11-14 00:00:00', 'Asia/Tokyo'));
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($first->id)->status);
            // 入金待ちの振込は 3 つとも期限と猶予を過ぎた（代引きは払われた 1 つと取り消した 1 つ）。見回りが取り消す前でも数えない
            $this->assertTrue($this->offer('hanako@example.com')->allows('bank_transfer'));
        });

        // 断った回数の記録は確定の経路がロールバックの後に残す（錠の中の確定を直に呼んだここでは残らない）
        $this->assertSame(0, $this->audits()->count());
    }

    public function test_one_source_can_hold_five_unpaid_orders_and_the_marks_are_cleared_after_the_window(): void
    {
        $this->inside(function (): void {
            for ($i = 1; $i <= UnpaidOrderLimits::PENDING_PER_SOURCE; $i++) {
                $this->place($this->cart(), 'bank_transfer', email: "buyer{$i}@example.com");
            }

            // 6 つ目は新しいメールでも断る。ほかの接続元なら通る
            $this->assertSame(UnpaidOrderLimits::PENDING, $this->offer('buyer6@example.com')->reason('bank_transfer'));
            $this->expectRefused(fn () => $this->place($this->cart(), 'bank_transfer', email: 'buyer6@example.com'));
            $this->ip = '198.51.100.30';
            $this->assertTrue($this->offer('buyer6@example.com')->allows('bank_transfer'));
            // メールをまだ入れていない入力の画面でも、接続元で決める
            $this->ip = '192.0.2.10';
            $this->assertFalse($this->offer(null)->allows('bank_transfer'));

            // 印は店の鍵の HMAC（平文の IP アドレス・メールを持たない）。IPv4 には 2 段目の印が無い
            $marks = CheckoutOrder::query()->whereNotNull('source_index')->pluck('source_index')->unique()->values()->all();
            $this->assertSame([app(UnpaidOrderLimits::class)->sourceIndex('192.0.2.10')], $marks);
            $this->assertSame(0, CheckoutOrder::query()->whereNotNull('source_wide_index')->count());
            $json = (string) json_encode(CheckoutOrder::query()->get()->toArray());
            $this->assertStringNotContainsString('192.0.2.10', $json);
            $this->assertStringNotContainsString('buyer1', $json);
        });

        // 印を持つ日数（払える期限のいちばん長いもの 30 日 ＋ 1 日 ＋ 猶予 24 時間 ＝ 32 日）のちょうど前は残し、過ぎたら見回りが消す
        $this->assertSame(32 * 86_400, UnpaidOrderLimits::markSeconds());
        $this->travel(UnpaidOrderLimits::markSeconds() - 1)->seconds();
        $this->artisan('storefront:expire-unpaid-orders')->assertSuccessful();
        $this->inside(fn () => $this->assertSame(5, CheckoutOrder::query()->whereNotNull('source_index')->whereNotNull('email_mark')->count()));
        $this->travel(2)->seconds();
        $this->artisan('storefront:expire-unpaid-orders')->assertSuccessful();
        $this->inside(fn () => $this->assertSame(0, CheckoutOrder::query()->where(static fn (Builder $q) => $q->whereNotNull('source_index')->orWhereNotNull('email_mark'))->count()));
    }

    public function test_two_confirmations_at_the_same_time_are_counted_inside_the_lock(): void
    {
        $this->inside(function (): void {
            $this->place($this->cart(), 'bank_transfer');
            $this->place($this->cart(), 'bank_transfer');

            // 2 つのカートを同じ時に確かめの画面まで進めた（どちらも 2 つの入金待ちを見て、銀行振込を選べた）
            [$a, $b] = [$this->cart(), $this->cart()];
            [$fa, $fb] = [$this->fingerprint($a, 'bank_transfer'), $this->fingerprint($b, 'bank_transfer')];

            // 先に錠を取った方だけが 3 つ目になり、後の方は錠の中で数え直して断る（入力の画面で選び直してもらう）
            $this->place($a, 'bank_transfer', fingerprint: $fa);
            $this->expectRefused(fn () => $this->place($b, 'bank_transfer', fingerprint: $fb));
            $this->assertSame(3, PaymentAttempt::query()->where('kind', 'direct')->count());
        });
    }

    public function test_the_checkout_pages_show_the_pending_reason_and_never_loop_on_the_confirmation(): void
    {
        $this->inside(function (): void {
            for ($i = 0; $i < UnpaidOrderLimits::PENDING_PER_EMAIL; $i++) {
                $this->place($this->cart(), 'bank_transfer');
            }
        });

        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'stripe'])->assertRedirect('http://ulm.localhost/checkout/confirm');
        // 入れたメールで、入力の画面の銀行振込・代引きは押せないまま理由を添える。注文の番号やリンクは出さない
        $page = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('data-test="payment-bank_transfer-reason">'.UnpaidOrderLimits::PENDING, $page);
        $this->assertStringContainsString('data-test="payment-cod-reason">'.UnpaidOrderLimits::PENDING, $page);
        $this->inside(function () use ($page): void {
            foreach (Transaction::query()->pluck('id') as $id) {
                $this->assertStringNotContainsString((string) $id, $page);
            }
        });
        // 銀行振込を送ると、入力の画面へ戻して欄の下に理由
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'bank_transfer'])->assertRedirect()->assertSessionHasErrors(['payment' => UnpaidOrderLimits::PENDING]);

        // 決済の画面で選ぶ手段なら、確かめの画面から確定まで進める（確かめの画面の答えと確定の答えが同じ）
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'stripe'])->assertRedirect('http://ulm.localhost/checkout/confirm');
        $this->send('POST', '/checkout/confirm', $this->confirm())->assertStatus(303)->assertRedirectContains('checkout.stripe.com');
        $this->assertSame(UnpaidOrderLimits::RULE_PENDING, $this->audits()->value('data')['rule'] ?? null);
    }

    public function test_members_only_hides_bank_transfer_and_cod_from_guests_and_refuses_them_at_the_lock(): void
    {
        // ゲストが銀行振込を選んで確かめの画面まで進んだ後に、店が「会員だけ」にした
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'bank_transfer'])->assertRedirect('http://ulm.localhost/checkout/confirm');
        $form = $this->confirm();
        $this->inside(fn () => app(DirectMethodSettings::class)->update([...$this->directSettings(), 'members_only' => true], 'test'));

        // 確定は錠の中で断り、確かめの画面 → 入力の画面で選び直してもらう（確かめの画面に閉じ込めない）
        $this->send('POST', '/checkout/confirm', $form)->assertRedirect('http://ulm.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://ulm.localhost/checkout');
        $this->inside(fn () => $this->assertSame(0, Transaction::query()->count()));

        // ゲストの入力の画面には選び方を出さず、添え書きだけ
        $page = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-test="payment-bank_transfer"', $page);
        $this->assertStringNotContainsString('data-test="payment-cod"', $page);
        $this->assertStringContainsString('銀行振込・代金引換は、ログインした会員の方だけお選びいただけます。', $page);
        // 送られてきても断る（欄の下に同じ添え書き）
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'cod'])->assertRedirect()->assertSessionHasErrors(['payment' => '銀行振込・代金引換は、ログインした会員の方だけお選びいただけます。']);
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'stripe'])->assertRedirect('http://ulm.localhost/checkout/confirm');

        $this->inside(function (): void {
            $this->expectRefused(fn () => $this->place($this->cart(), 'bank_transfer'), '会員の方だけ');
            $this->assertSame([false, false], [$this->offer('hanako@example.com')->allows('bank_transfer'), $this->offer('hanako@example.com')->allows('cod')]);

            // 会員は今までどおり選べて、確定できる
            $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'test');
            CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);
            Auth::guard('customer')->setUser(new Member(CustomerAccount::query()->findOrFail($account->id), 'ulm'));
            $offer = $this->offer('hanako@example.com');
            $this->assertSame([true, true, null], [$offer->allows('bank_transfer'), $offer->allows('cod'), $offer->note]);
            $this->assertSame('cod', CheckoutOrder::query()->where('transaction_id', $this->place($this->cart(), 'cod')->id)->value('pays_with'));

            // 切れば（既定）ゲストも選べる。設定を送らない保存では今のまま
            $this->app['auth']->forgetGuards();
            app(DirectMethodSettings::class)->update($this->directSettings(), 'test');
            $this->assertTrue(app(DirectMethodSettings::class)->membersOnly());
            app(DirectMethodSettings::class)->update([...$this->directSettings(), 'members_only' => false], 'test');
            $this->assertTrue($this->offer('hanako@example.com')->allows('bank_transfer'));
        });
    }

    public function test_one_mailbox_written_in_different_ways_is_counted_once(): void
    {
        // 数えるときだけの正規化: 全角 → 半角、IDN のドメイン → ASCII の形、小文字、+ 以降を落とす、Gmail はドットも落として gmail.com に
        $cases = [
            '  Victim+promo@ＧＭＡＩＬ.com ' => 'victim@gmail.com',
            'v.i.c.t.i.m+1@googlemail.com' => 'victim@gmail.com',
            'Ｖｉｃｔｉｍ@gmail.com' => 'victim@gmail.com',
            'User.Name+x@Example.COM' => 'user.name@example.com',
            'taro@例え.jp' => 'taro@xn--r8jz45g.jp',
            'taro@例え.ＪＰ' => 'taro@xn--r8jz45g.jp',
            // ASCII の形にできないドメインは、その形のまま（小文字）で数える
            'a@-例え-.jp' => 'a@-例え-.jp',
            'no-at-mark' => 'no-at-mark',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, UnpaidOrderLimits::countingEmail($input), $input);
        }

        $this->inside(function (): void {
            $limits = app(UnpaidOrderLimits::class);
            // 保存するブラインドインデックスとは別の印（そちらの正規化は変えない）
            $this->assertNotSame(BlindIndex::of('email', 'victim@gmail.com'), $limits->emailMark('victim@gmail.com'));

            // 入金待ち: 書き方を変えた 3 つの注文は、同じメールの 3 つ
            foreach (['victim@gmail.com', 'vic.tim+1@gmail.com', 'VICTIM@googlemail.com'] as $i => $email) {
                $this->ip = "192.0.2.2{$i}";
                $this->place($this->cart(), 'bank_transfer', email: $email);
            }
            $this->ip = '198.51.100.40';
            $this->assertSame(UnpaidOrderLimits::PENDING, $limits->pendingProblem('victim+x@ＧＭＡＩＬ.com', '198.51.100.40'));
            $this->assertNull($limits->pendingProblem('victim@example.com', '198.51.100.40'));
            $this->expectRefused(fn () => $this->place($this->cart(), 'cod', email: 'v.i.c.t.i.m@gmail.com'));

            // 1 時間の確定の数も同じ: あと 2 回（カードなど）で 5 回、6 回目は書き方を変えても断る
            $this->place($this->cart(), 'stripe', email: 'victim+2@gmail.com');
            $this->place($this->cart(), 'stripe', email: 'Victim@Gmail.com');
            $this->expectRefused(fn () => $this->place($this->cart(), 'stripe', email: 'vi.ctim+3@googlemail.com'), UnpaidOrderLimits::TOO_MANY);
        });
    }

    public function test_failed_attempts_with_someone_elses_email_do_not_use_up_their_hour(): void
    {
        // いたずらの人（ほかの接続元）が、他人のメールで確定を押し続けても注文はできない（ここでは指紋を壊して）
        $this->ip = '203.0.113.66';
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'stripe'])->assertRedirect('http://ulm.localhost/checkout/confirm');
        $form = $this->confirm();
        for ($i = 0; $i < UnpaidOrderLimits::ORDERS_PER_EMAIL_HOUR + 3; $i++) {
            $this->send('POST', '/checkout/confirm', [...$form, 'fingerprint' => str_repeat('0', 64)])->assertRedirect('http://ulm.localhost/checkout/confirm');
        }
        $this->inside(fn () => $this->assertSame(0, Transaction::query()->count()));

        // 本人は、確定できた注文で数える 5 回まで注文できる（いたずらの試みで枠を使い切られない）
        $this->token = null;
        $this->ip = '192.0.2.10';
        for ($i = 1; $i <= UnpaidOrderLimits::ORDERS_PER_EMAIL_HOUR; $i++) {
            $this->order('stripe')->assertStatus(303)->assertRedirectContains('checkout.stripe.com');
        }
        $this->order('stripe')->assertRedirect('http://ulm.localhost/checkout/confirm');
        $this->inside(fn () => $this->assertSame(UnpaidOrderLimits::ORDERS_PER_EMAIL_HOUR, Transaction::query()->count()));
    }

    public function test_cash_on_delivery_counts_only_before_shipping_and_inside_the_window(): void
    {
        $this->inside(function (): void {
            $cods = [];
            for ($i = 0; $i < UnpaidOrderLimits::PENDING_PER_EMAIL; $i++) {
                $cods[] = $this->place($this->cart(), 'cod');
            }
            $this->assertFalse($this->offer('hanako@example.com')->allows('cod'));

            // 発送した代引きは、店が入金を確かめる前でも数えない（荷物が動いている）
            $this->shipAll($cods[0]);
            $this->assertTrue($this->offer('hanako@example.com')->allows('cod'));
            $this->place($this->cart(), 'cod');
            $this->assertFalse($this->offer('hanako@example.com')->allows('cod'));
        });

        // 発送の前のままでも、印を持つ日数を過ぎたら数えない（ちょうどの前はまだ数える）
        $this->travel(UnpaidOrderLimits::markSeconds() - 1)->seconds();
        $this->inside(fn () => $this->assertFalse($this->offer('hanako@example.com')->allows('cod')));
        $this->travel(2)->seconds();
        $this->inside(fn () => $this->assertTrue($this->offer('hanako@example.com')->allows('cod')));
    }

    public function test_a_konbini_voucher_waiting_for_payment_counts_as_unpaid(): void
    {
        $this->inside(function (): void {
            $this->place($this->cart(), 'bank_transfer');
            $this->place($this->cart(), 'cod');
            $this->assertTrue($this->offer('hanako@example.com')->allows('bank_transfer'));

            // カードなどで確定し、Stripe の画面でコンビニ払いを選んで払込票が出た（入金待ち）
            $sale = $this->place($this->cart(), 'stripe');
            $this->awaitKonbini($sale, CarbonImmutable::now()->addDays(3));
            $this->assertSame(UnpaidOrderLimits::PENDING, $this->offer('hanako@example.com')->reason('bank_transfer'));
            // 接続元でも数える（Stripe の注文にも接続元の印を付ける）
            $this->assertNotNull(CheckoutOrder::query()->where('transaction_id', $sale->id)->value('source_index'));

            // 払込票の期限と猶予を過ぎたら、見回りの前でも数えない
            $this->travel(4)->days();
            $this->travel(1)->hours();
            $this->assertTrue($this->offer('hanako@example.com')->allows('bank_transfer'));
        });
    }

    public function test_one_ipv6_block_can_hold_fifteen_unpaid_orders_across_its_networks(): void
    {
        $this->inside(function (): void {
            // 同じ /48 の中の 3 つの /64 から 5 つずつ（/64 ごとの上限 5 の下）。メールは注文ごとに別
            $n = 0;
            foreach (['2001:db8:9:1::1', '2001:db8:9:2::1', '2001:db8:9:3::1'] as $ip) {
                $this->ip = $ip;
                for ($i = 0; $i < UnpaidOrderLimits::PENDING_PER_SOURCE; $i++) {
                    $this->place($this->cart(), 'bank_transfer', email: 'v6-'.(++$n).'@example.com');
                }
            }
            $this->assertNotNull(CheckoutOrder::query()->value('source_wide_index'));

            // 同じ /48 の 4 つ目の /64 でも断る。ほかの /48 なら通る
            $this->ip = '2001:db8:9:4::1';
            $this->assertSame(UnpaidOrderLimits::PENDING, $this->offer('v6-new@example.com')->reason('bank_transfer'));
            $this->ip = '2001:db8:a:4::1';
            $this->assertTrue($this->offer('v6-new@example.com')->allows('bank_transfer'));
        });
    }

    public function test_refusals_inside_the_lock_are_recorded_only_after_the_rollback(): void
    {
        $this->inside(function (): void {
            for ($i = 0; $i < UnpaidOrderLimits::PENDING_PER_EMAIL; $i++) {
                $this->place($this->cart(), 'bank_transfer');
            }

            // 確定（錠の中）は監査ログを書かず、断った決まりの名前を例外に持たせる
            try {
                $this->place($this->cart(), 'bank_transfer', fingerprint: $this->fingerprint($this->cart(), 'stripe'));
                $this->fail('入金待ちの上限を越えて確定した');
            } catch (OrderException $e) {
                $this->assertSame(UnpaidOrderLimits::RULE_PENDING, $e->limitedBy);
            }
            $this->assertSame(0, $this->audits()->count());
        });
    }

    public function test_the_marks_must_be_hmac_hex_in_the_database(): void
    {
        $this->inside(function (): void {
            $sale = $this->place($this->cart(), 'bank_transfer');

            foreach (['source_index' => '192.0.2.10', 'source_wide_index' => '2001:db8:1::/48', 'email_mark' => 'hanako@example.com'] as $column => $plain) {
                try {
                    DB::transaction(static fn () => DB::table('checkout_orders')->where('transaction_id', $sale->id)->update([$column => str_pad($plain, 64, '0')]));
                    $this->fail("{$column} に平文が入った");
                } catch (QueryException $e) {
                    $this->assertStringContainsString("checkout_orders_{$column}_hex", $e->getMessage());
                }
            }
        });
    }

    public function test_a_members_only_shop_without_stripe_tells_guests_to_log_in_and_members_see_their_own_limit_before_typing(): void
    {
        $this->inside(function (): void {
            app(PaymentGatewayService::class)->removeStripe('test');
            app(DirectMethodSettings::class)->update([...$this->directSettings(), 'members_only' => true], 'test');
        });

        // ゲスト: 払う手段が無いので「ログインすると選べる」とログインへのリンク。確かめの画面へは進めない
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $page = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('ログインすると、銀行振込・代金引換でご注文いただけます。', $page);
        $this->assertStringContainsString('href="http://ulm.localhost/account/login" data-test="payment-login"', $page);
        $this->send('POST', '/checkout', $this->valid)->assertRedirect()->assertSessionHasErrors('payment');
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://ulm.localhost/checkout');

        // 会員: メールを入れる前の画面でも、会員のメールの入金待ちで決める（入れた後と同じ答え）
        $this->inside(function (): void {
            $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'test');
            CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);
            Auth::guard('customer')->setUser(new Member(CustomerAccount::query()->findOrFail($account->id), 'ulm'));
            $this->assertTrue($this->offer(null)->allows('bank_transfer'));
            for ($i = 0; $i < UnpaidOrderLimits::PENDING_PER_EMAIL; $i++) {
                $this->ip = "192.0.2.3{$i}";
                $this->place($this->cart(), 'bank_transfer');
            }
            $this->ip = '198.51.100.50';
            $this->assertSame(UnpaidOrderLimits::PENDING, $this->offer(null)->reason('bank_transfer'));
            $this->assertNull($this->offer(null)->note);
        });
    }

    // --- 補助 ---

    /**
     * このテストで残った「注文の回数の上限で断った」記録
     *
     * @return Builder<AuditEvent>
     */
    private function audits(): Builder
    {
        return AuditEvent::query()->where('type', 'storefront.orders_limited')->where('sequence', '>', $this->auditFrom);
    }

    /**
     * @return array<string, mixed>
     */
    private function directSettings(): array
    {
        return ['bank_transfer' => true, 'bank_days' => 7, 'bank_account' => ['bank' => 'シュガー銀行', 'branch' => '渋谷支店', 'type' => 'ordinary', 'number' => '7654321', 'holder' => 'カ）シュガーテスト'],
            'cod' => true, 'cod_fees' => DirectMethodSettings::DEFAULT_COD_FEES, 'cod_limit' => 300000];
    }

    private function shipAll(Transaction $sale): void
    {
        foreach (Fulfillment::query()->where('transaction_id', $sale->id)->get() as $fulfillment) {
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), $fulfillment->id, 'yamato', '1234', 'operator:test');
        }
    }

    /**
     * Stripe の画面でコンビニ払いを選び、払込票が出た形にする（支払いの画面を作り、入金待ちの記録にする。ADR 0058 の流れの結果だけ）
     */
    private function awaitKonbini(Transaction $sale, CarbonImmutable $payBy): void
    {
        app(PaymentService::class)->startCheckout($sale, 'http://ulm.localhost/checkout/complete?returned=1', 'http://ulm.localhost/checkout/complete', 'storefront');
        DB::table('payment_attempts')->where('transaction_id', $sale->id)->update(['status' => 'awaiting', 'method' => 'konbini', 'pay_by' => $payBy]);
    }

    private function cart(): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->knit)->value('id'), 1);

        return $cart;
    }

    private function input(string $payment, ?string $email = 'hanako@example.com'): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => $email ?? '', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null, 'payment' => $payment]);
        $this->assertNotNull($input);

        return $input;
    }

    /**
     * 今の接続元で見る支払いの選び方（確かめの画面と同じ決め方）
     */
    private function offer(?string $email): PaymentOffer
    {
        request()->server->set('REMOTE_ADDR', $this->ip);
        $cart = app(CartService::class)->view($this->cart());

        return app(PaymentChoices::class)->offer($cart, $email === null ? null : $this->input('stripe', $email));
    }

    private function fingerprint(Cart $cart, string $payment, string $email = 'hanako@example.com'): string
    {
        request()->server->set('REMOTE_ADDR', $this->ip);
        $input = $this->input($payment, $email);
        $view = app(CartService::class)->view($cart, $input->destination(), $email, $input->pointsRequested());
        $offer = app(PaymentChoices::class)->offer($view, $input);
        $choice = $offer->allows($payment) ? $payment : 'stripe';
        $view = $view->withPaymentFee($choice === 'cod' ? $offer->fee('cod') : 0);

        return CheckoutSummary::fingerprintOf($view, app(PaymentMethodSettings::class)->forCheckout($view->total()), null, '', CheckoutSummary::paymentKey($payment, $offer->bankDays, $view->paymentFee));
    }

    /**
     * 確かめの画面と同じ決め方で指紋を作って、今の接続元から確定する
     */
    private function place(Cart $cart, string $payment, string $email = 'hanako@example.com', ?string $fingerprint = null): Transaction
    {
        $fingerprint ??= $this->fingerprint($cart, $payment, $email);
        request()->server->set('REMOTE_ADDR', $this->ip);

        return app(PlaceOrder::class)->place($cart, $this->input($payment, $email), rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);
    }

    private function expectRefused(Closure $work, string $reason = UnpaidOrderLimits::PENDING): void
    {
        $before = DB::table('transactions')->count();

        try {
            $work();
            $this->fail('入金待ちの上限・会員だけの決まりを越えて確定した');
        } catch (OrderException $e) {
            $this->assertTrue($e->changed);
            $this->assertStringContainsString($reason, $e->getMessage());
        }

        $this->assertSame($before, DB::table('transactions')->count());
    }

    /**
     * カート → 入力 → 確かめ → 確定（Stripe なら Stripe の画面へ移る）
     *
     * @param  array<string, string>  $buyer
     * @return TestResponse<Response>
     */
    private function order(string $payment, array $buyer = []): TestResponse
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', ['buyer' => [...$this->valid['buyer'], ...$buyer], 'same_as_buyer' => '1', 'payment' => $payment])->assertRedirect('http://ulm.localhost/checkout/confirm');

        return $this->send('POST', '/checkout/confirm', $this->confirm());
    }

    /**
     * 確認の画面を開き、確定のフォームの値を取り出す
     *
     * @return array<string, string>
     */
    private function confirm(): array
    {
        $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);

        return ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''];
    }

    /**
     * Cookie（カートの合い言葉とセッション）を持ち越して、今の接続元から送る
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $this->withServerVariables(['REMOTE_ADDR' => $this->ip]);
        $url = "http://ulm.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        return $response;
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
