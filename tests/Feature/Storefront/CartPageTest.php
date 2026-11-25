<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店頭のカート。フォームでも JSON でも同じ経路。カートは初めて入れたときに作り、合い言葉の Cookie で紐付ける。
 */
final class CartPageTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** ページを移らずに送る時の見出し（resources/storefront/cart.js と同じ。本文はフォームの形） */
    private const array XHR = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // json() は withCredentials() が無いと Cookie を送らない
        $this->withCredentials();
        Cache::store()->flush();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_adding_changing_and_removing_through_forms_in_every_theme(): void
    {
        $this->tenant('cpa');
        [$shirt, $cap] = $this->inside('cpa', fn (): array => [$this->listing('<b>白い</b>シャツ', 7700, 5)->id, $this->listing('キャップ', 3300, 10)->id]);

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            // テーマごとに新しいお客さんとして（前の回の Cookie を持ち越さない）
            $this->token = null;
            $this->defaultCookies = [];
            Tenant::query()->findOrFail('cpa')->forceFill(['theme' => $theme])->save();

            // 詳細のフォーム: 数は「− 1 +」で、在庫と 10 の小さい方まで
            $detail = (string) $this->send('GET', "/products/{$shirt}")->getContent();
            $this->assertStringContainsString('action="http://cpa.localhost/cart/items"', $detail, $theme);
            $this->assertStringContainsString('name="quantity" value="1" min="1" max="5"', $detail, $theme);
            $this->assertStringContainsString('data-stepper', $detail, $theme);

            $added = $this->send('POST', '/cart/items', ['listing_id' => $shirt, 'quantity' => '2'])->assertRedirect('http://cpa.localhost/cart');
            $cookie = $added->getCookie(CartCookie::NAME, false);
            $this->assertNotNull($cookie);
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame('lax', $cookie->getSameSite());
            $this->assertGreaterThan(now()->addDays(29)->getTimestamp(), $cookie->getExpiresTime());

            $this->send('POST', '/cart/items', ['listing_id' => $cap, 'quantity' => '1']);
            $page = (string) $this->send('GET', '/cart')->assertOk()->getContent();
            $this->assertSame(2, substr_count($page, 'data-test="cart-line"'), $theme);
            $this->assertStringContainsString('¥18,700', $page, $theme);
            $this->assertStringContainsString('&lt;b&gt;白い&lt;/b&gt;シャツ', $page, $theme);
            $this->assertStringNotContainsString('<b>白い', $page, $theme);
            // ほかの画面のヘッダにも数
            $this->assertMatchesRegularExpression('/data-test="cart-count" data-cart-count>3</', (string) $this->send('GET', '/')->getContent(), $theme);

            $this->send('POST', "/cart/items/{$this->lineOf('cpa', $shirt)}/quantity", ['quantity' => '1'])->assertRedirect('http://cpa.localhost/cart');
            $this->send('POST', "/cart/items/{$this->lineOf('cpa', $cap)}/remove")->assertRedirect('http://cpa.localhost/cart');
            $page = (string) $this->send('GET', '/cart')->getContent();
            $this->assertSame(1, substr_count($page, 'data-test="cart-line"'), $theme);
            $this->assertStringContainsString('¥7,700', $page, $theme);
            $this->send('POST', "/cart/items/{$this->lineOf('cpa', $shirt)}/quantity", ['quantity' => '0']);
            $this->send('GET', '/cart')->assertSee('data-test="cart-empty"', false);
        }
    }

    public function test_json_for_the_script_and_refusals_without_creating_carts(): void
    {
        $this->tenant('cpb');
        $this->tenant('cpc');
        $other = $this->inside('cpc', fn (): string => $this->listing('別の店の商品', 1000, 5)->id);
        [$shirt, $draft] = $this->inside('cpb', fn (): array => [$this->listing('シャツ', 7700, 3)->id, $this->listing('下書き', 1000, 5, ListingStatus::Draft)->id]);

        // 最初の 1 品が断られたら、カートを作らない
        foreach ([
            ['listing_id' => $draft, 'quantity' => '1'],
            ['listing_id' => $other, 'quantity' => '1'],
            ['listing_id' => '1 or 1=1', 'quantity' => '1'],
            ['listing_id' => $shirt, 'quantity' => '0'],
            ['listing_id' => $shirt, 'quantity' => '11'],
            ['listing_id' => $shirt, 'quantity' => 'abc'],
            ['listing_id' => $shirt, 'quantity' => ['1']],
            ['listing_id' => [$shirt], 'quantity' => '1'],
            ['listing_id' => $shirt, 'quantity' => '4'],
        ] as $input) {
            $this->post('http://cpb.localhost/cart/items', $input, self::XHR)->assertStatus(422)->assertJsonStructure(['message', 'cart' => ['count', 'lines']]);
            tenancy()->end();
        }
        $this->inside('cpb', fn () => $this->assertSame(0, Cart::query()->count()));

        // ショップは JSON の本文を受けない。正しい中身でも、読まずに 415 で、カートを作らない
        $this->postJson('http://cpb.localhost/cart/items', ['listing_id' => $shirt, 'quantity' => '1'])->assertStatus(415)
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertExactJson(['message' => 'この形式の送信は受け付けていません。']);
        $this->call('POST', 'http://cpb.localhost/cart/items', [], [], [], ['CONTENT_TYPE' => 'application/vnd.api+json'], '{"listing_id":"'.$shirt.'","quantity":"1"}')->assertStatus(415);
        tenancy()->end();
        $this->inside('cpb', fn () => $this->assertSame(0, Cart::query()->count()));

        // フォームの形で入れて、JSON の答えを求める（cart.js と同じ）。答えはカートの中身（数字と文字だけ）
        $this->send('POST', '/cart/items', ['listing_id' => $shirt, 'quantity' => '2'], json: true, tenant: 'cpb')->assertOk()
            ->assertJsonPath('cart.count', 2)
            ->assertJsonPath('cart.subtotal', 15400)
            ->assertJsonPath('cart.lines.0.title', 'シャツ')
            ->assertJsonPath('cart.lines.0.url', "/products/{$shirt}")
            ->assertJsonPath('message', 'カートに入れました。');
        $this->send('GET', '/cart', json: true, tenant: 'cpb')->assertOk()->assertJsonPath('cart.count', 2);
        $this->send('POST', '/cart/items', ['listing_id' => $shirt, 'quantity' => '2'], json: true, tenant: 'cpb')->assertStatus(422)->assertJsonPath('message', 'この商品は 3 点までです。');
        $this->inside('cpb', fn () => $this->assertSame(1, Cart::query()->count()));

        // 同じ合い言葉を別の店に送っても、カートは空
        $this->send('GET', '/cart', json: true, tenant: 'cpc')->assertOk()->assertJsonPath('cart.count', 0);
        // 壊れた合い言葉は、空のカートとして扱う
        $this->token = 'not-a-token';
        $this->send('GET', '/cart', json: true, tenant: 'cpb')->assertOk()->assertJsonPath('cart.count', 0);
        $this->send('POST', "/cart/items/{$this->lineOf('cpb', $shirt)}/quantity", ['quantity' => '1'], json: true, tenant: 'cpb')->assertStatus(422);
    }

    /**
     * 在庫が減った後の「−」。減らす操作は在庫を超えていても受け付け、答えの JSON に行ごとの知らせと注文できるかを入れる。
     * 核の部品（cart.js）はこれで、読み込み直さずに行の知らせと「注文できない」の見せ方を書き換える
     */
    public function test_decreasing_over_the_stock_is_accepted_and_the_json_tells_each_lines_state(): void
    {
        $this->tenant('cpe');
        $shirt = $this->inside('cpe', fn (): string => $this->listing('シャツ', 2000, 4)->id);

        $this->send('POST', '/cart/items', ['listing_id' => $shirt, 'quantity' => '4'], json: true, tenant: 'cpe')->assertOk();
        $this->inside('cpe', fn () => app(StockService::class)->adjust(Listing::query()->findOrFail($shirt), Location::query()->firstOrFail(), -2));

        $this->send('GET', '/cart', json: true, tenant: 'cpe')->assertOk()
            ->assertJsonPath('cart.lines.0.notice', '在庫が少なくなりました（残り 2 点）。数量を減らしてください。')
            ->assertJsonPath('cart.lines.0.purchasable', false)
            ->assertJsonPath('cart.count', 0);

        // 「−」は 3 点を送る。受け付けるが、まだ在庫より多いので知らせは残る
        $this->send('POST', "/cart/items/{$this->lineOf('cpe', $shirt)}/quantity", ['quantity' => '3'], json: true, tenant: 'cpe')->assertOk()
            ->assertJsonPath('message', '数量を変更しました。')
            ->assertJsonPath('cart.lines.0.quantity', 3)
            ->assertJsonPath('cart.lines.0.max_quantity', 2)
            ->assertJsonPath('cart.lines.0.notice', '在庫が少なくなりました（残り 2 点）。数量を減らしてください。')
            ->assertJsonPath('cart.lines.0.purchasable', false)
            ->assertJsonPath('cart.count', 0)
            ->assertJsonPath('cart.subtotal_label', '¥0');

        // 増やすのは断る（数は 3 のまま）
        $this->send('POST', "/cart/items/{$this->lineOf('cpe', $shirt)}/quantity", ['quantity' => '4'], json: true, tenant: 'cpe')->assertStatus(422)
            ->assertJsonPath('message', 'この商品は 2 点までです。')
            ->assertJsonPath('cart.lines.0.quantity', 3);

        // 在庫まで減らすと、知らせが消えて注文できる
        $this->send('POST', "/cart/items/{$this->lineOf('cpe', $shirt)}/quantity", ['quantity' => '2'], json: true, tenant: 'cpe')->assertOk()
            ->assertJsonPath('cart.lines.0.notice', null)
            ->assertJsonPath('cart.lines.0.purchasable', true)
            ->assertJsonPath('cart.count', 2)
            ->assertJsonPath('cart.subtotal_label', '¥4,000');

        // フォーム（JS なし）でも同じ
        $this->send('POST', "/cart/items/{$this->lineOf('cpe', $shirt)}/quantity", ['quantity' => '1'], tenant: 'cpe')->assertRedirect('http://cpe.localhost/cart')->assertSessionHas('cart_message', '数量を変更しました。');
    }

    /**
     * どのテーマのカートのページも、核の部品が書き換える印を行ごとに持つ。
     * 知らせの欄は知らせが無くても置き（hidden）、注文できない行には data-unavailable を付ける。見せ方（打ち消しなど）はテーマが印から決める
     */
    public function test_every_theme_marks_each_line_for_the_script_to_rewrite(): void
    {
        $this->tenant('cpf');
        [$shirt, $cap] = $this->inside('cpf', fn (): array => [$this->listing('シャツ', 2000, 4)->id, $this->listing('キャップ', 1000, 5)->id]);
        $this->send('POST', '/cart/items', ['listing_id' => $shirt, 'quantity' => '4'], tenant: 'cpf');
        $this->send('POST', '/cart/items', ['listing_id' => $cap, 'quantity' => '1'], tenant: 'cpf');
        $this->inside('cpf', fn () => app(StockService::class)->adjust(Listing::query()->findOrFail($shirt), Location::query()->firstOrFail(), -2));

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('cpf')->forceFill(['theme' => $theme])->save();
            $page = (string) $this->send('GET', '/cart', tenant: 'cpf')->assertOk()->getContent();

            $over = $this->row($page, $this->lineOf('cpf', $shirt));
            $this->assertMatchesRegularExpression('/<li\b[^>]*\bdata-unavailable\b/', $over, $theme);
            $this->assertMatchesRegularExpression('#<p\b[^>]*data-field="notice"[^>]*>在庫が少なくなりました（残り 2 点）。#u', $over, $theme);

            $fine = $this->row($page, $this->lineOf('cpf', $cap));
            $this->assertDoesNotMatchRegularExpression('/<li\b[^>]*\bdata-unavailable\b/', $fine, $theme);
            // 知らせが無くても欄はある（核の部品が後から入れる）。隠しておく
            $this->assertMatchesRegularExpression('#<p\b[^>]*data-field="notice"[^>]*\bhidden\b[^>]*></p>#', $fine, $theme);
        }
    }

    public function test_cart_writes_are_rate_limited(): void
    {
        $this->tenant('cpd');
        $shirt = $this->inside('cpd', fn (): string => $this->listing('シャツ', 1000, 5)->id);

        for ($i = 0; $i < 60; $i++) {
            $this->send('POST', "/cart/items/{$this->lineOf('cpd', $shirt)}/remove", tenant: 'cpd');
        }

        $this->send('POST', '/cart/items', ['listing_id' => $shirt, 'quantity' => '1'], tenant: 'cpd')->assertStatus(429)->assertSee('操作が多すぎます');
    }

    /**
     * 合い言葉の Cookie を持ち越して送る
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = [], bool $json = false, string $tenant = 'cpa'): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $url = "http://{$tenant}.localhost{$path}";
        // call() は withCookie() の Cookie を送らないので、get() / post() を使う。
        // ページを移らない呼び出し（json）は cart.js と同じく、フォームの形で送って JSON の答えを求める（ショップは JSON の本文を受けない）
        $headers = $json ? self::XHR : [];
        $response = $method === 'GET' ? $this->get($url, $headers) : $this->post($url, $data, $headers);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        return $response;
    }

    /**
     * カートのページの 1 行（data-cart-line の li から、閉じる </li> まで）
     */
    private function row(string $page, string $lineId): string
    {
        $from = strrpos(substr($page, 0, (int) strpos($page, "data-cart-line=\"{$lineId}\"")), '<li');
        $this->assertNotFalse($from, $lineId);
        $rest = substr($page, $from);

        return substr($rest, 0, (int) strpos($rest, '</li>'));
    }

    /**
     * その商品のカートの行（いちばん新しいもの。無ければ、まだ無い行の ID）。数を変える・外すはカートの行で指す（ADR 0036）
     */
    private function lineOf(string $tenant, string $listingId): string
    {
        return $this->inside($tenant, fn (): string => (string) (CartLine::query()->where('listing_id', $listingId)->orderByDesc('created_at')->orderByDesc('id')->value('id')
            ?? strtolower((string) Str::ulid())));
    }

    private function listing(string $title, int $amount, int $stock, ListingStatus $status = ListingStatus::Active): Listing
    {
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => $status]);
        app(StockService::class)->receive($listing, Location::query()->firstOrFail(), $stock);

        return $listing;
    }
}
