<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderReader;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ご注文の確認。注文番号とメールで照会するか、確認のメールの期限つきの署名つきリンクで開く。
 * 見つからない・メールが違う・ショップの注文でない、は同じ文。総当たりは接続元ごとと注文番号ごとに止める。名前・住所・電話は出さない。
 */
final class OrderLookupTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->shop('ola');
        $this->shop('olb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_an_order_is_found_by_number_and_email_in_every_theme(): void
    {
        $sale = $this->order('ola', storefront: true);

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('ola')->forceFill(['theme' => $theme])->save();
            $this->flushSession();
            $this->send('ola', 'GET', '/orders')->assertOk()->assertSee('data-test="order-lookup"', false);

            // 番号は大文字・前後の空白でも、メールは大文字でも
            $this->send('ola', 'POST', '/orders', ['order_number' => ' '.strtoupper($sale).' ', 'email' => 'Hanako@Example.com'])->assertRedirect("http://ola.localhost/orders/{$sale}");
            $html = (string) $this->send('ola', 'GET', "/orders/{$sale}")->assertOk()->getContent();
            foreach ([strtoupper($sale), '入金待ち', '&lt;b&gt;ニット&lt;/b&gt; × 1', '¥4,400'] as $text) {
                $this->assertStringContainsString($text, $html, "{$theme}: {$text}");
            }
            foreach (['山田', '渋谷', '09012345678', 'hanako'] as $pii) {
                $this->assertStringNotContainsString($pii, $html, "{$theme}: {$pii}");
            }
        }
    }

    public function test_wrong_unknown_and_foreign_orders_get_the_same_answer(): void
    {
        $sale = $this->order('ola', storefront: true);
        $admin = $this->order('ola', storefront: false);
        $other = $this->order('olb', storefront: true);

        foreach ([
            '違うメール' => ['order_number' => $sale, 'email' => 'someone@example.com'],
            '無い番号' => ['order_number' => '01aaaaaaaaaaaaaaaaaaaaaaaa', 'email' => 'hanako@example.com'],
            'ショップの注文でない' => ['order_number' => $admin, 'email' => 'hanako@example.com'],
            '別の店の注文' => ['order_number' => $other, 'email' => 'hanako@example.com'],
            '形の違う番号' => ['order_number' => "1' or '1'='1", 'email' => 'hanako@example.com'],
        ] as $case => $input) {
            $this->send('ola', 'POST', '/orders', $input)->assertRedirect('http://ola.localhost/orders');
            $this->assertStringContainsString('ご注文番号またはメールアドレスが正しくありません', (string) $this->send('ola', 'GET', '/orders')->getContent(), $case);
        }
        // 配列などの形の違う入力
        $this->send('ola', 'POST', '/orders', ['order_number' => [$sale], 'email' => 'hanako@example.com'])->assertRedirect('http://ola.localhost/orders');

        // 照会していない注文は、番号を知っていても見えない
        $this->send('ola', 'GET', "/orders/{$sale}")->assertRedirect('http://ola.localhost/orders');
    }

    public function test_guessing_is_limited_per_order_number(): void
    {
        $sale = $this->order('ola', storefront: true);

        for ($i = 0; $i < 10; $i++) {
            $this->send('ola', 'POST', '/orders', ['order_number' => $sale, 'email' => "guess{$i}@example.com"]);
        }

        // 11 回目は、正しいメールでも 1 時間は通さない（接続元を変えても、注文番号ごとに数える）
        $this->send('ola', 'POST', '/orders', ['order_number' => strtoupper($sale), 'email' => 'hanako@example.com'], '203.0.113.9')->assertRedirect('http://ola.localhost/orders');
        $this->assertStringContainsString('確認の回数が多すぎます', (string) $this->send('ola', 'GET', '/orders')->getContent());
    }

    public function test_the_signed_link_from_the_mail_opens_the_order_until_it_expires(): void
    {
        $sale = $this->order('ola', storefront: true);
        $link = $this->inside('ola', fn (): string => OrderReader::link(Transaction::query()->findOrFail($sale), 'http://ola.localhost'));
        $path = (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);

        // 署名（30 日の閲覧の資格）は、この画面から移った先の参照元に残さない（同じショップの次の画面で動く外のタグから読まれないように）
        $this->send('ola', 'GET', $path)->assertOk()->assertSee(strtoupper($sale))->assertHeader('Referrer-Policy', 'no-referrer');
        // 書き換えた署名、別の注文に付け替え、期限切れ
        $this->send('ola', 'GET', preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $path) ?? '')->assertRedirect('http://ola.localhost/orders');
        $other = $this->order('ola', storefront: true);
        $this->send('ola', 'GET', str_replace($sale, $other, $path))->assertRedirect('http://ola.localhost/orders');
        $this->travel(OrderReader::LINK_DAYS + 1)->days();
        $this->send('ola', 'GET', $path)->assertRedirect('http://ola.localhost/orders')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('リンクの期限が切れました', (string) $this->send('ola', 'GET', '/orders')->getContent());
    }

    private function order(string $shop, bool $storefront): string
    {
        return $this->inside($shop, function () use ($storefront): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '<b>ニット</b>', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '09012345678', 'address' => ['city' => '渋谷区']]);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');

            if ($storefront) {
                CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);
            }

            return $sale->id;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $shop, string $method, string $path, array $data = [], ?string $ip = null): TestResponse
    {
        $url = "http://{$shop}.localhost{$path}";
        $server = $ip === null ? [] : ['REMOTE_ADDR' => $ip];
        $response = $method === 'GET' ? $this->withServerVariables($server)->get($url) : $this->withServerVariables($server)->post($url, $data);
        tenancy()->end();

        return $response;
    }

    private function shop(string $id): void
    {
        $this->tenant($id);
    }
}
