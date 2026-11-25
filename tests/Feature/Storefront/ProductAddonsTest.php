<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\AddonService;
use App\Core\Listing\Services\ListingService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Cart\AddonInputFlash;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ショップでオプション（名入れ・ギフト包装など）を選ぶ（ADR 0036 区切り 3）。どのテーマでも核の部品の欄で選び、
 * JavaScript が無くてもフォームのままカートに入れられる。カートの行の名前と小計にオプションが入り、入れた文字そのものは出さない。
 */
final class ProductAddonsTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string NAME = '山田 花子';

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_choosing_addons_and_adding_them_to_the_cart_in_every_theme(): void
    {
        $this->tenant('paa');
        $ids = $this->inside('paa', function (): array {
            $mug = $this->mug();

            return ['mug' => $mug->id, 'plain' => $this->item('布巾', 800)->id] + $this->addonIds($mug);
        });

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->token = null;
            $this->defaultCookies = [];
            Tenant::query()->findOrFail('paa')->forceFill(['theme' => $theme])->save();

            // 詳細: 選ぶ・入れる文字・付けるの欄。選ぶの値は選択肢の名前で、必須なので空の「選んでください」から始まる（「選ばない」は無い）
            $html = (string) $this->send('GET', "/products/{$ids['mug']}")->assertOk()->getContent();
            $this->assertStringContainsString('data-addon-fields', $html, $theme);
            $this->assertStringContainsString('data-addons="{', $html, $theme);
            $this->assertMatchesRegularExpression('#<select id="addon-'.$ids['ギフト包装'].'" name="addons\['.$ids['ギフト包装'].'\]" data-addon-input\s+required\s*>#u', $html, $theme);
            $this->assertMatchesRegularExpression('#<option value="label:あり"\s*>あり（\+¥330）</option>#u', $html, $theme);
            $this->assertMatchesRegularExpression('#<option value="label:なし"\s*>なし</option>#u', $html, $theme);
            $this->assertStringContainsString('<option value="">選んでください</option>', $html, $theme);
            $this->assertStringNotContainsString('選ばない', $html, $theme);
            $this->assertStringNotContainsString('selected', $html, $theme);
            $this->assertMatchesRegularExpression('#<input type="text" id="addon-'.$ids['名入れ'].'" name="addons\['.$ids['名入れ'].'\]" maxlength="10"#u', $html, $theme);
            $this->assertStringContainsString('10 文字まで・+¥500', $html, $theme);
            $this->assertMatchesRegularExpression('#<input type="checkbox" id="addon-'.$ids['保証の延長'].'" name="addons\['.$ids['保証の延長'].'\]" value="1"#u', $html, $theme);
            $this->assertStringContainsString('保証の延長（+¥1,000）', $html, $theme);
            // 合計の印は JavaScript があるときだけ見せる。はじめは数 1 × 価格（何も選んでいない）。数を掛けた形で出す。金額は値段と同じ ¥ の形
            $this->assertMatchesRegularExpression('#<p data-addon-summary hidden>オプションを含めて <span data-addon-total>1 点で ¥2,000</span></p>#u', $html, $theme);

            // オプションの無い商品は今のまま（部品を出さない）
            $plain = (string) $this->send('GET', "/products/{$ids['plain']}")->assertOk()->getContent();
            $this->assertStringNotContainsString('data-addon-fields', $plain, $theme);
            $this->assertStringNotContainsString('name="addons[', $plain, $theme);

            // 選んでカートに入れると、行の名前と小計にオプションが入る。入れた文字そのものは出さない
            $this->send('POST', '/cart/items', ['listing_id' => $ids['mug'], 'quantity' => '2', 'addons' => [
                $ids['ギフト包装'] => 'label:あり', $ids['名入れ'] => self::NAME, $ids['保証の延長'] => '1',
            ]])->assertRedirect('http://paa.localhost/cart')->assertSessionHas('cart_message', 'カートに入れました。');
            $cart = (string) $this->send('GET', '/cart')->assertOk()->getContent();
            $this->assertStringContainsString('マグ／ギフト包装：あり、名入れ：入力あり、保証の延長：あり', $cart, $theme);
            $this->assertStringContainsString('¥7,660', $cart, $theme);
            // お客さんに見せる金額は「¥」の形だけ。「5,400 円」の書き方を残さない（商品の画面とカート）
            $this->assertDoesNotMatchRegularExpression('#[0-9] ?円#u', $html, $theme);
            $this->assertDoesNotMatchRegularExpression('#[0-9] ?円#u', $cart, $theme);
            $this->assertStringNotContainsString(self::NAME, $cart, $theme);
        }

        $this->assertSame(count(app(ThemeRegistry::class)->names()), $this->inside('paa', fn (): int => CartLine::query()->whereNotNull('addons')->count()));
    }

    public function test_a_missing_required_addon_too_long_text_another_products_addon_and_an_unchecked_toggle_are_refused(): void
    {
        $this->tenant('pab');
        $ids = $this->inside('pab', function (): array {
            $mug = $this->mug();
            $other = $this->item('布巾', 800);
            app(AddonService::class)->define($other, $other->version, [['kind' => 'toggle', 'name' => 'のし', 'amount' => 200]]);

            return ['mug' => $mug->id, 'other' => ListingAddon::query()->where('listing_id', $other->id)->sole()->id] + $this->addonIds($mug);
        });

        $gift = [$ids['ギフト包装'] => 'label:なし'];

        foreach ([
            '必須の抜け' => [[$ids['名入れ'] => self::NAME], '「ギフト包装」を選んでください。'],
            '長すぎる文字' => [$gift + [$ids['名入れ'] => str_repeat('あ', 11)], '「名入れ」は 10 文字までです。'],
            '別の商品のオプション' => [$gift + [$ids['other'] => '1'], 'この商品に無いオプションが選ばれています。'],
            '付けるに 0' => [$gift + [$ids['保証の延長'] => '0'], '「保証の延長」の選択内容が正しくありません。選び直してください。'],
            '無い選択肢' => [[$ids['ギフト包装'] => 'label:特別'], '「ギフト包装」の選択肢が見つかりません。選び直してください。'],
        ] as $case => [$addons, $error]) {
            $this->send('POST', '/cart/items', ['listing_id' => $ids['mug'], 'quantity' => '1', 'addons' => $addons], 'pab')
                ->assertSessionHas('cart_error', $error);
            // 断られたら、空のカートも残さない
            $this->assertSame([0, 0], $this->inside('pab', fn (): array => [CartLine::query()->count(), Cart::query()->count()]), $case);
        }

        // ページを移らずに入れるとき（cart.js と同じくフォームの形で送り、JSON の答えを求める）も同じに断る
        $this->post('http://pab.localhost/cart/items', ['listing_id' => $ids['mug'], 'quantity' => '1', 'addons' => $gift + [$ids['保証の延長'] => '0']], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(422)->assertJsonPath('message', '「保証の延長」の選択内容が正しくありません。選び直してください。');
        tenancy()->end();
    }

    public function test_a_refused_add_keeps_the_entered_values_for_the_next_page_only_and_encrypted(): void
    {
        $this->tenant('pac');
        $ids = $this->inside('pac', function (): array {
            $mug = $this->mug();

            return ['mug' => $mug->id] + $this->addonIds($mug);
        });
        $page = "http://pac.localhost/products/{$ids['mug']}";

        // 必須の選ぶを空のまま入れると断られ、商品の画面に戻る
        $this->from($page);
        $this->send('POST', '/cart/items', ['listing_id' => $ids['mug'], 'quantity' => '2', 'addons' => [
            $ids['ギフト包装'] => '', $ids['名入れ'] => self::NAME, $ids['保証の延長'] => '1',
        ]], 'pac')->assertRedirect($page)->assertSessionHas('cart_error', '「ギフト包装」を選んでください。');
        $this->assertSame(0, $this->inside('pac', fn (): int => CartLine::query()->count()));

        // セッションの中身に名入れの文字は平文で無い（暗号化して flash に置く）。解けば同じ商品の値として戻る
        $session = app('session.store');
        $flashed = $session->get(AddonInputFlash::KEY);
        $this->assertIsString($flashed);
        $this->assertStringNotContainsString(self::NAME, serialize($session->all()));
        $this->assertStringNotContainsString(self::NAME, (string) $session->getHandler()->read($session->getId()));
        $this->assertSame(self::NAME, AddonInputFlash::unwrap($flashed, $ids['mug'])[$ids['名入れ']] ?? null);
        $this->assertSame([], AddonInputFlash::unwrap($flashed, $ids['ギフト包装']));

        // 次の 1 回の画面では、入れていた値が欄に戻る（選ぶは空のまま）
        $html = (string) $this->send('GET', "/products/{$ids['mug']}", tenant: 'pac')->assertOk()->getContent();
        $this->assertStringContainsString('「ギフト包装」を選んでください。', $html);
        $this->assertStringContainsString('value="'.self::NAME.'"', $html);
        $this->assertMatchesRegularExpression('#name="addons\['.$ids['保証の延長'].'\]" value="1" data-addon-input\s+checked#u', $html);
        $this->assertStringNotContainsString('selected', $html);

        // 2 回目の画面には出ない
        $again = (string) $this->send('GET', "/products/{$ids['mug']}", tenant: 'pac')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::NAME, $again);
        $this->assertStringNotContainsString('checked', $again);

        // 選んだ選択肢も戻る（ほかの理由で断られたとき）
        $this->from($page);
        $this->send('POST', '/cart/items', ['listing_id' => $ids['mug'], 'quantity' => '1', 'addons' => [
            $ids['ギフト包装'] => 'label:あり', $ids['名入れ'] => str_repeat('あ', 11),
        ]], 'pac')->assertSessionHas('cart_error', '「名入れ」は 10 文字までです。');
        $html = (string) $this->send('GET', "/products/{$ids['mug']}", tenant: 'pac')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<option value="label:あり"\s*selected>#u', $html);
        // 合計の案内も戻した値で（2,000 ＋ 330 ＋ 500）
        $this->assertStringContainsString('<span data-addon-total>1 点で ¥2,830</span>', $html);
    }

    /**
     * マグ（2,000 円、在庫 10）。ギフト包装（必須の選ぶ: なし／あり +330 円）・名入れ（入れる文字 10 文字まで +500 円）・保証の延長（付ける +1,000 円）
     */
    private function mug(): Listing
    {
        $mug = $this->item('マグ', 2000);

        return app(AddonService::class)->define($mug, $mug->version, [
            ['kind' => 'choice', 'name' => 'ギフト包装', 'required' => true, 'choices' => [['label' => 'なし', 'amount' => 0], ['label' => 'あり', 'amount' => 330]]],
            ['kind' => 'text', 'name' => '名入れ', 'amount' => 500, 'max_length' => 10],
            ['kind' => 'toggle', 'name' => '保証の延長', 'amount' => 1000],
        ]);
    }

    private function item(string $title, int $amount): Listing
    {
        $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => $title, 'unit_amount' => $amount, 'status' => ListingStatus::Active]);
        app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 10);

        return Listing::query()->findOrFail($listing->id);
    }

    /**
     * @return array<string, string> オプションの名前 => ID
     */
    private function addonIds(Listing $listing): array
    {
        return ListingAddon::query()->where('listing_id', $listing->id)->pluck('id', 'name')->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = [], string $tenant = 'paa'): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $url = "http://{$tenant}.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        return $response;
    }
}
