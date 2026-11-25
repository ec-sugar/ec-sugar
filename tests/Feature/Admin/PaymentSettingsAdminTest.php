<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店の決済代行の鍵（ADR 0019）。置く・外すは直前の再確認、鍵は暗号化して置き、画面・記録・答えに出さない。
 */
final class PaymentSettingsAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    /** 鍵の本体（テストの値。秘密情報の検知に掛からないよう、鍵の形はその場で組み立てる） */
    private const string FAKE = 'FakeForTestsOnly000';

    private Operator $operator;

    private SoftwareAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_keys_need_a_fresh_confirmation_are_encrypted_and_never_shown(): void
    {
        $this->tenant('psa');
        $this->signIn();
        $keys = ['secret_key' => self::fakeKey('sk', 'test'), 'webhook_secret' => self::fakeKey('whsec')];

        // 本番と同じ https で（送信先の http / https と番号は APP_URL に合わせる）
        config(['app.url' => 'https://ec.example.jp']);
        $this->send('GET', '/api/tenants/psa/payment-settings')->assertOk()
            ->assertJsonPath('stripe', ['configured' => false, 'mode' => null, 'key_hint' => null, 'updated_at' => null, 'test_key' => ['configured' => false, 'key_hint' => null, 'updated_at' => null, 'in_use' => false]])
            // Stripe に登録する通知の送信先（店のドメイン）と選ぶ通知
            ->assertJsonPath('webhook.url', 'https://psa.localhost/webhooks/stripe')
            ->assertJsonPath('webhook.events', ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired', 'payment_intent.succeeded', 'payment_intent.payment_failed', 'refund.created', 'refund.updated', 'refund.failed'])
            // 手順で開く Stripe の画面（まだ鍵が無い・テスト用の鍵はテスト環境の画面）
            ->assertJsonPath('dashboard', ['apikeys' => 'https://dashboard.stripe.com/test/apikeys', 'webhooks' => 'https://dashboard.stripe.com/test/webhooks', 'payment_methods' => 'https://dashboard.stripe.com/test/settings/payment_methods'])
            // 使う支払いの手段（ADR 0058）。何も選んでいなければカードだけ
            ->assertJsonPath('methods', ['konbini' => false, 'paypay' => false, 'konbini_days' => 3, 'paypay_available' => true]);

        // 手段を変えるのも再確認の後（お金の権限）
        $this->send('PUT', '/api/tenants/psa/payment-settings/methods', ['konbini' => true, 'paypay' => false, 'konbini_days' => 5])->assertStatus(423);

        // 再確認なしでは置けない・外せない
        $this->send('PUT', '/api/tenants/psa/payment-settings/stripe', $keys)->assertStatus(423);
        $this->send('DELETE', '/api/tenants/psa/payment-settings/stripe')->assertStatus(423);
        $this->inside('psa', fn () => $this->assertSame(0, PaymentGateway::query()->count()));

        $this->reconfirm($this->operator, $this->authenticator);
        $response = $this->send('PUT', '/api/tenants/psa/payment-settings/stripe', $keys)->assertOk()
            ->assertJsonPath('stripe.configured', true)
            ->assertJsonPath('stripe.mode', 'test')
            ->assertJsonPath('stripe.key_hint', '1234');
        // 答えに鍵は入らない
        $this->assertStringNotContainsString('sk_test_', (string) $response->getContent());
        $this->assertStringNotContainsString('whsec_', (string) $response->getContent());
        $this->assertStringNotContainsString('sk_test_', (string) $this->send('GET', '/api/tenants/psa/payment-settings')->getContent());

        $this->inside('psa', function (): void {
            // 列は暗号化。モデルを配列にしても鍵は出ない
            $raw = (string) DB::table('payment_gateways')->value('credentials');
            $this->assertStringStartsWith(TenantCipher::PREFIX, $raw);
            $this->assertStringNotContainsString('sk_test_', $raw);
            $this->assertArrayNotHasKey('credentials', PaymentGateway::query()->sole()->toArray());

            // ドライバに渡す値は復号できる。ダンプしても鍵は出ない
            $credentials = app(PaymentGatewayService::class)->credentials();
            $this->assertNotNull($credentials);
            $this->assertSame([self::fakeKey('sk', 'test'), self::fakeKey('whsec'), 'test'], [$credentials->secretKey, $credentials->webhookSecret, $credentials->mode]);
            $this->assertStringNotContainsString('sk_test_', print_r($credentials, true));

            // 記録は代行の名前と test / live だけ
            $event = DomainEvent::query()->where('type', 'payment.gateway_configured')->sole();
            $this->assertEquals(['provider' => 'stripe', 'mode' => 'test'], $event->payload);
            $this->assertStringStartsWith('operator:', (string) $event->actor);
        });

        // 置き換えは 1 行のまま。外すと未設定
        $this->send('PUT', '/api/tenants/psa/payment-settings/stripe', ['secret_key' => self::fakeKey('rk', 'test', '9876'), 'webhook_secret' => self::fakeKey('whsec')])->assertOk()->assertJsonPath('stripe.key_hint', '9876');
        $this->inside('psa', fn () => $this->assertSame(1, PaymentGateway::query()->count()));
        $this->send('DELETE', '/api/tenants/psa/payment-settings/stripe')->assertOk()->assertJsonPath('stripe.configured', false);
        $this->send('DELETE', '/api/tenants/psa/payment-settings/stripe')->assertOk();
        $this->inside('psa', function (): void {
            $this->assertNull(app(PaymentGatewayService::class)->credentials());
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.gateway_removed')->count());
        });
    }

    public function test_malformed_and_live_keys_are_refused_without_echoing_them(): void
    {
        $this->tenant('psb');
        $this->signIn();
        $this->reconfirm($this->operator, $this->authenticator);

        foreach ([
            'secret_key' => ['secret_key' => self::fakeKey('pk', 'test'), 'webhook_secret' => self::fakeKey('whsec')],
            'secret_key ' => ['secret_key' => self::fakeKey('sk', 'test', '', 'short'), 'webhook_secret' => self::fakeKey('whsec')],
            // 前後の空白と改行は貼り付けのくせなので除く（TrimStrings）。途中の改行は断る
            'secret_key  ' => ['secret_key' => self::fakeKey('sk', 'test', "\n1234"), 'webhook_secret' => self::fakeKey('whsec')],
            'secret_key   ' => ['secret_key' => [self::fakeKey('sk', 'test')], 'webhook_secret' => self::fakeKey('whsec')],
            'secret_key    ' => ['webhook_secret' => self::fakeKey('whsec')],
            'webhook_secret' => ['secret_key' => self::fakeKey('sk', 'test'), 'webhook_secret' => self::fakeKey('whsec', null, '<script>alert(1)</script>')],
            'webhook_secret ' => ['secret_key' => self::fakeKey('sk', 'test'), 'webhook_secret' => ''],
            // 本番でない環境では live の鍵を断る（本物のお金を動かさない）
            'secret_key     ' => ['secret_key' => self::fakeKey('sk', 'live'), 'webhook_secret' => self::fakeKey('whsec')],
        ] as $field => $input) {
            $response = $this->send('PUT', '/api/tenants/psb/payment-settings/stripe', $input);
            $this->assertSame(422, $response->status(), $field);
            $response->assertJsonValidationErrors(trim($field));
            $this->assertStringNotContainsString(self::FAKE, (string) $response->getContent(), $field);
        }
        $this->inside('psb', fn () => $this->assertSame(0, PaymentGateway::query()->count()));

        // 本番の環境なら live の鍵を置ける
        config(['payment.live_keys_allowed' => true]);
        $this->send('PUT', '/api/tenants/psb/payment-settings/stripe', ['secret_key' => self::fakeKey('sk', 'live'), 'webhook_secret' => self::fakeKey('whsec')])->assertOk()->assertJsonPath('stripe.mode', 'live');
    }

    public function test_keys_stay_inside_their_tenant_and_need_an_active_operator(): void
    {
        $this->tenant('psc');
        $this->tenant('psd');
        $this->signIn();
        $this->reconfirm($this->operator, $this->authenticator);

        $this->send('PUT', '/api/tenants/psc/payment-settings/stripe', ['secret_key' => self::fakeKey('sk', 'test'), 'webhook_secret' => self::fakeKey('whsec')])->assertOk();
        $this->send('GET', '/api/tenants/psd/payment-settings')->assertOk()->assertJsonPath('stripe.configured', false);
        $this->inside('psd', fn () => $this->assertNull(app(PaymentGatewayService::class)->credentials()));

        $this->operator->update(['disabled_at' => now()]);
        $this->send('DELETE', '/api/tenants/psc/payment-settings/stripe')->assertUnauthorized();
        $this->sessionId = null;
        $this->send('GET', '/api/tenants/psc/payment-settings')->assertUnauthorized();
        $this->inside('psc', fn () => $this->assertNotNull(app(PaymentGatewayService::class)->credentials()));
    }

    public function test_payment_methods_are_chosen_after_a_fresh_confirmation_inside_their_tenant(): void
    {
        $this->tenant('pse');
        $this->tenant('psf');
        $this->signIn();
        $this->reconfirm($this->operator, $this->authenticator);

        // 使う手段（ADR 0058）。期限の日数は 1〜30 日、形の違う値は断る
        $this->send('PUT', '/api/tenants/pse/payment-settings/methods', ['konbini' => true, 'paypay' => true, 'konbini_days' => 7])->assertOk()
            ->assertJsonPath('methods', ['konbini' => true, 'paypay' => true, 'konbini_days' => 7, 'paypay_available' => true]);
        $this->send('PUT', '/api/tenants/pse/payment-settings/methods', ['konbini' => true, 'paypay' => false, 'konbini_days' => 31])->assertStatus(422)->assertJsonValidationErrors('konbini_days');
        $this->send('PUT', '/api/tenants/pse/payment-settings/methods', ['konbini' => 'yes', 'paypay' => false, 'konbini_days' => 3])->assertStatus(422)->assertJsonValidationErrors('konbini');

        // ほかの店は変わらない。記録は値だけで、誰が変えたかを残す
        $this->send('GET', '/api/tenants/psf/payment-settings')->assertOk()->assertJsonPath('methods.konbini', false);
        $this->inside('pse', function (): void {
            $event = DomainEvent::query()->where('type', 'payment.methods_updated')->sole();
            $this->assertEquals(['konbini' => true, 'paypay' => true, 'konbini_days' => 7], $event->payload);
            $this->assertStringStartsWith('operator:', (string) $event->actor);
        });
    }

    private static function fakeKey(string $prefix, ?string $mode = null, string $tail = '1234', ?string $body = null): string
    {
        return implode('_', array_filter([$prefix, $mode])).'_'.($body ?? self::FAKE).$tail;
    }

    private function signIn(): void
    {
        [$this->operator, $this->authenticator] = $this->enrolledOperator();
    }
}
