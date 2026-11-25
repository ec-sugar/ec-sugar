<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderMail;
use Carbon\CarbonImmutable;
use EcSugar\PluginLine\LineAdminPage;
use EcSugar\PluginLine\LineNotifier;
use EcSugar\PluginLine\Models\LineLink;
use EcSugar\PluginLine\Models\LineMessage;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「LINE 連携」の知らせと Webhook と管理画面（ADR 0070）。つないで友だちの会員にだけ、注文を受けた・発送したを push で
 * （中身は店の名前・注文番号・マイページの URL・追跡番号だけ）。友だちでない・ブロックの後は送らない。Webhook は署名を確かめる。
 * 送れなくても注文は止まらない。秘密は画面・記録・ログに出ない。閲覧の人は設定を変えられない。ほかの店には届かない
 */
final class LineNotifyTest extends TestCase
{
    use DatabaseTruncation;
    use LineHelpers;
    use OperatorSession;
    use WithTenants;

    private ?string $cart = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        FakeStripe::install();
        $this->fakeLine();
        $this->configureAdminHost();
        $this->tenant('lna', 'かくざとう');
        $this->configureLine('lna');
        $this->inside('lna', function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 0, 'free_over' => null], 'test');
            app(ShopSettingsService::class)->setSellFreeItems(true, 'test');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_linked_friend_is_told_of_the_order_and_the_shipment_without_personal_data(): void
    {
        $this->linkHanako();
        $order = $this->placeFree($this->listing());

        // メールは今までどおり。LINE は友だちの会員へ 1 通
        Mail::assertSent(OrderMail::class);
        $pushes = $this->pushes();
        $this->assertCount(1, $pushes);
        $push = $pushes[0];
        $this->assertSame('Bearer '.self::ACCESS_TOKEN, $push->header('Authorization')[0]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $push->header('X-Line-Retry-Key')[0]);
        $this->assertSame(self::SUB, $push['to']);
        $text = (string) $push['messages'][0]['text'];
        $this->assertStringContainsString('【かくざとう】ご注文を受け付けました。', $text);
        $this->assertStringContainsString('注文番号: '.strtoupper($order), $text);
        $this->assertStringContainsString('http://lna.localhost/account', $text);
        // 名前・住所・電話・金額・署名つきのリンクは載せない
        foreach (['山田', '渋谷区', '09012345678', '¥', 'signature='] as $never) {
            $this->assertStringNotContainsString($never, $text);
        }

        // 同じ知らせがもう一度来ても、送るのは 1 回だけ
        $this->inside('lna', fn () => event(new PaymentConfirmed($order)));
        $this->assertCount(1, $this->pushes());

        // 発送: 運送会社と追跡番号を添える
        $this->inside('lna', function () use ($order): void {
            $transaction = Transaction::query()->findOrFail($order);
            app(SaleOrderService::class)->ship($transaction, (string) Fulfillment::query()->where('transaction_id', $order)->value('id'), 'yamato', '1234-5678-9012', 'operator:test');
        });
        $this->assertCount(2, $this->pushes());
        $shipped = (string) $this->pushes()[1]['messages'][0]['text'];
        $this->assertStringContainsString('ご注文の品を発送しました。', $shipped);
        $this->assertStringContainsString('配送: ヤマト運輸 / 追跡番号: 1234-5678-9012', $shipped);

        $this->inside('lna', function (): void {
            $this->assertSame(['order_paid' => 'sent', 'order_shipped' => 'sent'], LineMessage::query()->pluck('status', 'kind')->all());
            // 記録には宛先も本文も無い
            $this->assertStringNotContainsString(self::SUB, (string) json_encode(DB::table('line_messages')->get()));
        });
    }

    public function test_nobody_but_linked_friends_is_told_and_the_webhook_must_be_signed(): void
    {
        // つないでいないお客さんには送らない
        $this->placeFree($this->listing(), 'jiro@example.com');
        $this->assertCount(0, $this->pushes());

        // 友だちでない会員には送らない
        $this->friendFlag = false;
        $this->linkHanako();
        $this->assertFalse($this->inside('lna', fn (): bool => LineLink::query()->sole()->friend));
        $listing = $this->listing();
        $this->placeFree($listing);
        $this->assertCount(0, $this->pushes());

        // 署名が違う・無い・ほかの鍵の Webhook は断り、印を変えない
        // 知らせの時刻は LINE の時計（ミリ秒）。テストは時刻を止めて進めているので、つないだ時刻（テストの今）より後に
        $now = (int) CarbonImmutable::now()->getTimestampMs() + 1000;
        $this->webhook([self::friendEvent('follow', self::SUB, $now)], secret: 'ffffffffffffffffffffffffffffffff')->assertStatus(400);
        $this->webhook([self::friendEvent('follow', self::SUB, $now)], secret: null)->assertStatus(400);
        $this->webhook([self::friendEvent('follow', self::SUB, $now)], secret: self::SECRET)->assertStatus(400);
        $this->assertFalse($this->inside('lna', fn (): bool => LineLink::query()->sole()->friend));

        // 署名の合う follow で送るようになる（準備中のショップでも受ける）
        $this->inside('lna', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'test'));
        $this->webhook([self::friendEvent('follow', self::SUB, $now)])->assertOk();
        $this->assertTrue($this->inside('lna', fn (): bool => LineLink::query()->sole()->friend));
        $this->inside('lna', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, 'test'));
        $this->placeFree($listing);
        $this->assertCount(1, $this->pushes());

        // ブロック（unfollow）の後は送らない。前の時刻の follow が後から届いても戻さない
        $this->webhook([self::friendEvent('unfollow', self::SUB, $now + 1000), self::friendEvent('follow', self::SUB, $now + 500)])->assertOk();
        $this->assertFalse($this->inside('lna', fn (): bool => LineLink::query()->sole()->friend));
        $this->placeFree($listing);
        $this->assertCount(1, $this->pushes());

        // つないでいない LINE の知らせ・壊れた知らせは何も残さない（200 で受ける）
        $this->webhook([self::friendEvent('follow', self::OTHER_SUB, $now + 2000), ['type' => 'message', 'source' => ['type' => 'user', 'userId' => 'bad']], 'x'])->assertOk();
        $this->assertSame(1, $this->inside('lna', fn (): int => LineLink::query()->count()));

        // App を止めると、つないだ友だちにも送らない（Webhook も受けない）
        $this->webhook([self::friendEvent('follow', self::SUB, $now + 3000)])->assertOk();
        $this->inside('lna', fn () => app(PluginStates::class)->set('line', false, 'test'));
        $this->placeFree($listing);
        $this->assertCount(1, $this->pushes());
        $this->webhook([self::friendEvent('unfollow', self::SUB, $now + 4000)])->assertNotFound();
    }

    public function test_a_failing_push_never_stops_the_order_and_is_not_retried(): void
    {
        $this->linkHanako();
        $listing = $this->listing();

        $this->pushReply = 'error';
        $first = $this->placeFree($listing);
        $this->pushReply = 'down';
        $second = $this->placeFree($listing);

        $this->inside('lna', function () use ($first, $second): void {
            // 注文は受け付けられ、メールも送れている
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($first)->phase);
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($second)->phase);
            $this->assertSame(['failed', 'http_500'], [LineMessage::query()->where('transaction_id', $first)->value('status'), LineMessage::query()->where('transaction_id', $first)->value('error')]);
            $this->assertSame('connection', LineMessage::query()->where('transaction_id', $second)->value('error'));
        });
        Mail::assertSent(OrderMail::class, 2);
        // 送り直さない（同じ知らせが来ても、記録があるので送らない）
        $this->pushReply = 'ok';
        $this->inside('lna', fn () => event(new PaymentConfirmed($first)));
        $this->assertCount(2, $this->pushes());

        // 店ごとの上限を超えたら送らずに記録する
        $this->travel(2)->hours();
        RateLimiter::clear('line-push|lna');
        for ($i = 0; $i < LineNotifier::SHOP_PER_MINUTE; $i++) {
            RateLimiter::hit('line-push|lna', 60);
        }
        $third = $this->placeFree($listing, wait: false);
        $this->assertSame(['skipped', 'limit'], $this->inside('lna', fn (): array => [LineMessage::query()->where('transaction_id', $third)->value('status'), LineMessage::query()->where('transaction_id', $third)->value('error')]));
        $this->assertCount(2, $this->pushes());
    }

    public function test_secrets_stay_off_screens_records_and_logs_and_readers_cannot_change_settings(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.json_encode($e->context);
        });
        [$owner, $authenticator] = $this->enrolledOperator();
        // 店の LINE の鍵を変えるのは、運用の段階からで、直前のパスキーの再確認の後だけ
        $this->send('PUT', '/api/tenants/lna/plugins/line/settings', ['values' => ['bot_prompt' => 'off']])->assertStatus(423);
        $this->reconfirm($owner, $authenticator);

        // 秘密は入っているかだけ
        $plugins = $this->send('GET', '/api/tenants/lna/plugins')->assertOk();
        $line = $plugins->collect('plugins')->firstWhere('name', 'line');
        $this->assertSame(['login_channel_secret' => true, 'messaging_access_token' => true, 'messaging_channel_secret' => true], $line['settings']['secrets']);
        foreach ([self::SECRET, self::ACCESS_TOKEN, self::BOT_SECRET] as $secret) {
            $plugins->assertDontSee($secret);
        }

        // 管理画面で入れ直しても、答えにも記録にも出ない
        $saved = $this->send('PUT', '/api/tenants/lna/plugins/line/settings', ['values' => ['messaging_access_token' => 'test-line-access-token-1111', 'bot_prompt' => 'aggressive']])->assertOk();
        $saved->assertDontSee('test-line-access-token-1111');
        // 変えたことは中央の監査ログ（誰が・どの経路・結果）と店の記録（変えた項目の名前だけ）に残り、値は入らない
        $audit = AuditEvent::query()->where('type', 'operator.tenant_request')->where('tenant_id', 'lna')->get()
            ->filter(fn (AuditEvent $e): bool => ($e->data['route'] ?? null) === 'api/tenants/{tenant}/plugins/{plugin}/settings' && ($e->data['status'] ?? null) === 200);
        $this->assertCount(1, $audit);
        $this->assertSame($owner->id, $audit->first()?->operator_id);
        $this->assertStringNotContainsString('test-line-access-token-1111', (string) json_encode(AuditEvent::query()->pluck('data')));
        $this->assertSame(['bot_prompt', 'messaging_access_token'], $this->inside('lna', fn (): array => DomainEvent::query()->where('type', 'plugin.settings_changed')->orderByDesc('sequence')->value('payload')['changed']));

        // 「つなぎ方と数」: 写す URL と数だけ（誰がつないだかは出さない）
        $this->linkHanako();
        $record = $this->send('GET', '/api/tenants/lna/apps/line/records/'.LineAdminPage::SUMMARY)->assertOk();
        $record->assertJsonFragment(['label' => 'LINE ログインのコールバック URL', 'type' => 'text', 'value' => 'http://lna.localhost/p/line/callback', 'copy' => true]);
        $record->assertJsonFragment(['label' => 'Messaging API の Webhook URL', 'type' => 'text', 'value' => 'http://lna.localhost/p/line/webhook', 'copy' => true]);
        $record->assertJsonFragment(['label' => 'LINE と連携した会員', 'type' => 'number', 'value' => 1]);
        $this->placeFree($this->listing());
        $this->send('GET', '/api/tenants/lna/apps/line/records?filter=all')->assertOk()->assertJsonFragment(['label' => '送信済み']);
        foreach ([self::SUB, 'hanako@example.com', 'test-line-access-token-1111', self::SECRET] as $never) {
            $this->assertStringNotContainsString($never, (string) $record->getContent());
            $this->assertStringNotContainsString($never, (string) json_encode($this->inside('lna', fn () => DomainEvent::query()->pluck('payload'))));
            $this->assertStringNotContainsString($never, implode("\n", $logged));
        }
        $this->assertSame('test-line-access-token-1111', $this->pushes()[0]->header('Authorization')[0] === 'Bearer test-line-access-token-1111' ? 'test-line-access-token-1111' : null);
    }

    public function test_readers_can_look_but_cannot_change_the_line_settings(): void
    {
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        $this->reconfirm($staff, $authenticator);
        app(OperatorAccess::class)->grant($staff, 'lna', 'read', 'test');
        $this->send('GET', '/api/tenants/lna/apps/line/records/'.LineAdminPage::SUMMARY)->assertOk();
        $this->send('GET', '/api/tenants/lna/plugins')->assertOk()->assertDontSee(self::SECRET)->assertDontSee(self::ACCESS_TOKEN);
        $this->send('PUT', '/api/tenants/lna/plugins/line/settings', ['values' => ['bot_prompt' => 'off']])->assertForbidden();
        $this->assertSame('normal', $this->inside('lna', fn () => app(PluginSettings::class)->publicValues('line')['bot_prompt'] ?? null));
    }

    public function test_another_shop_never_reaches_this_shops_settings_or_members(): void
    {
        $this->linkHanako();
        $this->tenant('lnb', 'ほかの店');
        $this->configureLine('lnb');

        // この店の Messaging API の鍵で署名した Webhook は、ほかの店でも同じ鍵なら通るが、ほかの店の会員には何もつながっていない
        $this->webhook([self::friendEvent('unfollow', self::SUB, (int) CarbonImmutable::now()->getTimestampMs())], shop: 'lnb')->assertOk();
        $this->assertTrue($this->inside('lna', fn (): bool => LineLink::query()->sole()->friend));
        $this->assertSame(0, $this->inside('lnb', fn (): int => LineLink::query()->count()));

        // 管理画面: ほかの店の人は、この店の LINE の画面に入れない（与えられていない店は、あるかどうかも見せない 404）
        [$other] = $this->enrolledOperator('other@example.com', 'staff');
        app(OperatorAccess::class)->grant($other, 'lnb', 'operate', 'test');
        $this->send('GET', '/api/tenants/lna/apps/line/records/'.LineAdminPage::SUMMARY)->assertNotFound();
        $this->send('PUT', '/api/tenants/lna/plugins/line/settings', ['values' => ['bot_prompt' => 'off']])->assertNotFound();
        $this->send('GET', '/api/tenants/lnb/apps/line/records/'.LineAdminPage::SUMMARY)->assertOk()->assertJsonFragment(['label' => 'LINE と連携した会員', 'type' => 'number', 'value' => 0]);
    }

    public function test_the_disclosure_page_names_line_while_the_app_is_used(): void
    {
        $this->shop('GET', '/legal/external')->assertOk()->assertSee('LINE ヤフー株式会社（LINE）')->assertSee('https://line.me/ja/terms/policy/', false);
        $this->inside('lna', fn () => app(PluginStates::class)->set('line', false, 'test'));
        $this->shop('GET', '/legal/external')->assertOk()->assertDontSee('LINE ヤフー株式会社（LINE）');
    }

    /**
     * @return list<Request>
     */
    private function pushes(): array
    {
        return array_values(array_map(static fn (array $pair): Request => $pair[0], Http::recorded(fn (Request $r): bool => $r->url() === LineNotifier::PUSH)->all()));
    }

    private function linkHanako(): void
    {
        $this->returnFromLine($this->startLine());
        $this->shop('POST', '/p/line/email', ['email' => 'hanako@example.com']);
        $this->shop('POST', '/p/line/code', ['code' => $this->lastCode()])->assertRedirect();
        $this->member = null;
    }

    private function listing(): string
    {
        return $this->inside('lna', function (): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '試供品', 'unit_amount' => 0, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 20);

            return $listing->id;
        });
    }

    /**
     * 0 円の注文をショップで確定する（払いの確定 PaymentConfirmed が届く）。注文のメールで、お客さんの台帳につながる
     */
    private function placeFree(string $listing, string $email = 'hanako@example.com', bool $wait = true): string
    {
        $this->cart = null;

        // 0 円の注文は、核が続けての注文を止める（いたずらの防ぎ）。1 件ごとに時間をおく
        if ($wait) {
            $this->travel(2)->hours();
        }

        $this->cartShop('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $this->cartShop('POST', '/checkout', ['buyer' => ['email' => $email, 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => ''], 'same_as_buyer' => '1']);
        $confirm = (string) $this->cartShop('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->cartShop('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertRedirect('http://lna.localhost/checkout/complete');

        return $this->inside('lna', fn (): string => (string) CheckoutOrder::query()->latest('created_at')->orderByDesc('id')->value('transaction_id'));
    }

    /**
     * カートの Cookie を持ち回る（会員の印は持たない＝ゲストの注文。メールで台帳につながる）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function cartShop(string $method, string $path, array $data = []): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        // 人の速さで（1 分の回数の上限にテスト自身が当たらないように）
        $this->travel(7)->seconds();
        $this->app['auth']->forgetGuards();
        $response = $method === 'GET' ? $this->get("http://lna.localhost{$path}") : $this->post("http://lna.localhost{$path}", $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;

        return $response;
    }
}
