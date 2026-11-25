<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 入口の Caddy が証明書をその場で取る前に聞く経路（ADR 0031）。
 *
 * - 管理画面のホストと、ショップのドメインの 1 つ下の名前のうち中央のホストか domains にある名前だけ 200、ほかは 404
 *   （知らない名前で証明書を取らせない。Let's Encrypt の回数の上限を守る。店の独自ドメインは、nginx が受ける形ができるまで取らない）
 * - おかしな名前（大文字・ポート・道筋・IP・長すぎ・配列）は 422
 * - 外からは呼べない: nginx が内側のポート（8081）で Caddy の住所からだけ受け、FastCGI の印 EC_SUGAR_TLS_ASK=1 を付ける。
 *   印の無い要求は、名前が正しくても、Host を管理画面・店・内側の名前に偽っても 404。印は要求の見出しからは作れない（見出しは HTTP_… になる）
 */
final class TlsAskTest extends TestCase
{
    // 店の行は中央 DB に直に入れる（店の DB は作らない）ので、トランザクションで包む形でよい
    use RefreshDatabase;

    private const string URL = 'http://web:8081/internal/tls/allowed';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.central_domains' => ['localhost', '127.0.0.1', 'api.shop.example.test', 'api.elsewhere.test', (string) config('admin.domain')],
            'tls.shops' => 'multi',
            'tls.shop_domain' => 'shop.example.test',
        ]);

        $now = now();
        DB::table('tenants')->insert(['id' => 'demo', 'name' => 'Demo', 'created_at' => $now, 'updated_at' => $now, 'data' => '{}']);
        DB::table('domains')->insert(['domain' => 'demo.shop.example.test', 'tenant_id' => 'demo', 'created_at' => $now, 'updated_at' => $now]);
        // ID と 1 段目が違う店（ID demosake・ホスト demo-sake）
        DB::table('tenants')->insert(['id' => 'demosake', 'name' => 'Demo Sake', 'created_at' => $now, 'updated_at' => $now, 'data' => '{}']);
        DB::table('domains')->insert(['domain' => 'demo-sake.shop.example.test', 'tenant_id' => 'demosake', 'created_at' => $now, 'updated_at' => $now]);
        // 店の独自ドメインと、ショップのドメインの 2 つ下の名前（どちらも nginx がまだ受けない）
        DB::table('domains')->insert(['domain' => 'shop.customer.example', 'tenant_id' => 'demo', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('domains')->insert(['domain' => 'deep.demo.shop.example.test', 'tenant_id' => 'demo', 'created_at' => $now, 'updated_at' => $now]);
    }

    public function test_known_names_are_allowed(): void
    {
        $this->ask('demo.shop.example.test')->assertOk();
        $this->ask('demo-sake.shop.example.test')->assertOk();
        $this->ask('api.shop.example.test')->assertOk();
        $this->ask((string) config('admin.domain'))->assertOk();
    }

    public function test_unknown_names_are_refused(): void
    {
        $this->ask('nope.shop.example.test')->assertNotFound();
        // 店の ID のホスト（demosake）は、その店のホストでない
        $this->ask('demosake.shop.example.test')->assertNotFound();
        $this->ask('shop.example.test')->assertNotFound();
        $this->ask('demo.shop.example.test.evil.example')->assertNotFound();
        $this->ask('evil.example')->assertNotFound();
    }

    public function test_names_outside_the_shop_domain_are_refused_even_if_known(): void
    {
        // domains にあっても、nginx が受けない名前（独自ドメイン・2 つ下）は取らない
        $this->ask('shop.customer.example')->assertNotFound();
        $this->ask('deep.demo.shop.example.test')->assertNotFound();
        // 中央のホストでも、ショップのドメインの外なら取らない
        $this->ask('api.elsewhere.test')->assertNotFound();
        // ショップのドメインの名前が似ているだけ（区切りの点が無い）
        $this->ask('demoshop.example.test')->assertNotFound();
    }

    public function test_nothing_is_allowed_in_the_single_shop_form(): void
    {
        // 1 店舗の形は、Caddy が 2 つの名前を起動のときに取り、その場では取らない。問い合わせの経路は何にも「取ってよい」と答えない
        config(['tls.shops' => 'single', 'tls.shop_domain' => 'demo.shop.example.test']);

        $this->ask((string) config('admin.domain'))->assertNotFound();
        $this->ask('demo.shop.example.test')->assertNotFound();
        $this->ask('api.shop.example.test')->assertNotFound();

        // 店の数の形が分からないときも、何も取らせない（既定は 1 店舗の形）
        config(['tls.shops' => null]);
        $this->ask((string) config('admin.domain'))->assertNotFound();
    }

    public function test_only_the_admin_host_is_allowed_without_a_shop_domain(): void
    {
        config(['tls.shop_domain' => null]);

        $this->ask((string) config('admin.domain'))->assertOk();
        $this->ask('demo.shop.example.test')->assertNotFound();
        $this->ask('api.shop.example.test')->assertNotFound();
    }

    public function test_malformed_names_are_rejected(): void
    {
        $bad = [
            '',
            'Demo.Shop.Example.Test',
            'demo.shop.example.test:443',
            'demo.shop.example.test/',
            'demo shop.example.test',
            'demo.shop.example.test.',
            '.demo.shop.example.test',
            '*.shop.example.test',
            '203.0.113.10',
            'localhost',
            '-demo.shop.example.test',
            // 端の NUL と空白（全体の前処理が落とす前の、届いたままの名前で確かめる）
            "demo.shop.example.test\0",
            ' demo.shop.example.test',
            "demo.shop.example.test\n",
            str_repeat('a', 64).'.example.test',
            str_repeat('abcdefghi.', 26).'test',
        ];

        foreach ($bad as $domain) {
            $this->assertSame(422, $this->ask($domain)->status(), var_export($domain, true));
        }

        $this->withServerVariables(['EC_SUGAR_TLS_ASK' => '1'])->get(self::URL)->assertStatus(422);
        $this->withServerVariables(['EC_SUGAR_TLS_ASK' => '1'])->get(self::URL.'?domain[]=demo.shop.example.test')->assertStatus(422);
    }

    public function test_cannot_be_called_from_outside_even_with_a_forged_host(): void
    {
        $hosts = ['web:8081', (string) config('admin.domain'), 'demo.shop.example.test', 'localhost', 'api.shop.example.test'];

        foreach ($hosts as $host) {
            // 印が無い（外の nginx の入口から来た）
            $this->get("http://{$host}/internal/tls/allowed?domain=demo.shop.example.test")->assertNotFound();
            // 印を見出しで偽っても、FastCGI の印にはならない
            $this->get("http://{$host}/internal/tls/allowed?domain=demo.shop.example.test", ['EC-SUGAR-TLS-ASK' => '1'])->assertNotFound();
        }

        // 印の値が違う
        foreach (['', '0', 'yes', '1 '] as $value) {
            $this->withServerVariables(['EC_SUGAR_TLS_ASK' => $value])->get(self::URL.'?domain=demo.shop.example.test')->assertNotFound();
        }

        // 印が無ければ、おかしな名前でも 422 を返さない（中を見せない）
        $this->get(self::URL.'?domain=Bad Name')->assertNotFound();
    }

    public function test_does_not_start_a_session(): void
    {
        $response = $this->ask('demo.shop.example.test')->assertOk();

        $this->assertSame([], $response->headers->getCookies());
    }

    /**
     * @return TestResponse<Response>
     */
    private function ask(string $domain): TestResponse
    {
        return $this->withServerVariables(['EC_SUGAR_TLS_ASK' => '1'])->get(self::URL.'?domain='.rawurlencode($domain));
    }
}
