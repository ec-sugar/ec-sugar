<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\VariantService;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Storefront\Access\ShopPasswordCookie;
use App\Storefront\Http\Controllers\PasswordController;
use DOMDocument;
use DOMElement;
use DOMXPath;
use EcSugar\PluginProductFeed\FeedCache;
use EcSugar\PluginProductFeed\FeedFormat;
use EcSugar\PluginProductFeed\FeedWriter;
use EcSugar\PluginProductFeed\Http\FeedController;
use EcSugar\PluginProductFeed\Models\Feed;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「商品フィード」。Google と Meta が読みに来る XML（RSS 2.0 と g: の名前空間）の形と必須の項目・種類ごとの行・在庫切れ・価格の形・エスケープ、
 * 出さない商品（下書き・外した・写真の無い）、URL のトークン（違う・作り直し・止める・ほかの店）、準備中の店、キャッシュとその消え方、回数の上限、
 * 管理画面の App の画面（URL を写す印・数・権限）と商品の画面の欄
 */
final class ProductFeedAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BASE = '/api/tenants/pfa/apps/product-feed/records';

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->tenant('pfa', 'SUGAR 工房');
        $this->ids = $this->inside('pfa', fn (): array => $this->catalog());
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_an_unused_app_has_no_feed_and_no_admin_page(): void
    {
        $this->enrolledOperator();
        // 使っていない店でも表はあり、トークンを置いても出さない（経路は核の plugin: の印で 404）
        $token = $this->inside('pfa', static function (): string {
            $token = str_repeat('a', 40);
            Feed::query()->where('format', 'google')->firstOrFail()->fill(['token' => $token, 'token_hash' => hash('sha256', $token), 'issued_at' => now()])->save();

            return $token;
        });

        $this->send('GET', '/api/tenants/pfa/app-pages')->assertOk()->assertExactJson(['pages' => []]);
        $this->send('GET', self::BASE)->assertNotFound();
        $this->shop("/p/product-feed/google/{$token}.xml")->assertNotFound();
        $this->use();
        $this->shop("/p/product-feed/google/{$token}.xml")->assertOk();

        // トークンは空か、暗号化した値と SHA-256 の値と日時がそろう（DB が断る）
        $this->inside('pfa', function (): void {
            $this->expectException(QueryException::class);
            DB::table('product_feed_feeds')->where('format', 'meta')->update(['token_hash' => hash('sha256', 'x')]);
        });
    }

    public function test_the_google_feed_is_valid_xml_with_the_required_fields_one_row_per_variant_and_escaped_text(): void
    {
        $this->use();
        $this->enrolledOperator();
        $url = $this->issue('google');
        $this->assertMatchesRegularExpression('#\Ahttp://pfa\.localhost/p/product-feed/google/[0-9a-f]{40}\.xml\z#', $url);

        $response = $this->shop($this->path($url))->assertOk();
        $this->assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $raw = (string) $response->getContent();
        [$xpath, $items] = $this->parse($raw);

        // チャンネル: 店の名前とショップの入口
        $this->assertSame('SUGAR 工房', $xpath->evaluate('string(/rss/channel/title)'));
        $this->assertSame('http://pfa.localhost', $xpath->evaluate('string(/rss/channel/link)'));

        // 出すのは公開した商品の行だけ（下書き・写真の無い商品は出ない）。種類のある商品は種類ごと
        $tote = $this->ids['tote'];
        $apron = $this->ids['apron'];
        $this->assertEqualsCanonicalizing([$tote, $this->ids['sold'], $this->ids['墨 / M'], $this->ids['墨 / L'], $this->ids['からし / M'], $this->ids['からし / L']], array_keys($items));

        // 種類の無い商品: id は商品の ID、必須の項目、価格は「5400 JPY」、写真は完全な URL、2 枚目は追加の写真
        $row = $items[$tote];
        $this->assertSame('帆布 & <トート>', $row['g:title']);
        $this->assertSame("丈夫な帆布 & 革の持ち手 <b>太字ではない</b>\n2 行目", $row['g:description']);
        $this->assertSame("http://pfa.localhost/products/{$tote}", $row['g:link']);
        $this->assertSame('5400 JPY', $row['g:price']);
        $this->assertSame('in_stock', $row['g:availability']);
        $this->assertSame('new', $row['g:condition']);
        $this->assertSame('SUGAR 工房', $row['g:brand'], 'ブランドの既定は店の名前');
        $this->assertSame('no', $row['g:identifier_exists'], 'GTIN と MPN が無い');
        $this->assertMatchesRegularExpression('#\Ahttp://pfa\.localhost/media/listings/[0-9a-z]{26}/large\z#', $row['g:image_link']);
        $this->assertCount(1, $row['g:additional_image_link']);
        $this->assertArrayNotHasKey('g:item_group_id', $row);
        $this->assertArrayNotHasKey('g:google_product_category', $row);
        // 生の XML では & と < がエスケープされている（文は HTML として扱われない）
        $this->assertStringContainsString('<g:title>帆布 &amp; &lt;トート&gt;</g:title>', $raw);
        $this->assertStringNotContainsString('<b>', $raw);

        // 種類: id は種類の ID、item_group_id は商品の ID、色とサイズ、商品名に種類の名前、種類の価格、売り切れの種類
        $variant = $items[$this->ids['からし / L']];
        $this->assertSame($apron, $variant['g:item_group_id']);
        $this->assertSame(['からし', 'L'], [$variant['g:color'], $variant['g:size']]);
        $this->assertSame('エプロン（からし / L）', $variant['g:title']);
        $this->assertSame('5200 JPY', $variant['g:price']);
        $this->assertSame('out_of_stock', $variant['g:availability']);
        $this->assertSame('in_stock', $items[$this->ids['墨 / M']]['g:availability']);
        $this->assertSame('4800 JPY', $items[$this->ids['墨 / M']]['g:price']);
        // 説明の無い商品は商品名を説明にする
        $this->assertSame('エプロン', $variant['g:description']);

        // 在庫の無い商品は在庫切れ
        $this->assertSame('out_of_stock', $items[$this->ids['sold']]['g:availability']);
    }

    public function test_the_meta_feed_uses_meta_words_and_the_settings_and_product_fields_fill_brand_category_and_mpn(): void
    {
        $this->use();
        [$owner] = $this->enrolledOperator();
        $this->inside('pfa', fn () => app(PluginSettings::class)->update('product-feed', ['brand' => 'Kakuzato', 'google_product_category' => '166'], 'test'));
        $path = "/api/tenants/pfa/listings/{$this->ids['tote']}/apps/product-feed";

        // 商品の画面の欄: 形の違う値は欄の名前つきで断る
        $this->send('PUT', $path, ['values' => ['google_product_category' => 'Apparel']])->assertUnprocessable()->assertJsonValidationErrors(['values.google_product_category']);
        $this->send('PUT', $path, ['values' => ['brand' => '<script>']])->assertUnprocessable()->assertJsonValidationErrors(['values.brand']);
        $this->send('PUT', $path, ['values' => ['condition' => 'broken']])->assertUnprocessable()->assertJsonValidationErrors(['values.condition']);
        $this->send('GET', "/api/tenants/pfa/listings/{$this->ids['tote']}/apps")->assertOk()->assertJsonPath('apps.0.note', 'この商品はフィードに 1 行載ります。');
        $this->send('PUT', $path, ['values' => ['brand' => '帆布工房', 'google_product_category' => '3032', 'condition' => 'used', 'mpn_from_sku' => true]])->assertOk();
        $event = $this->inside('pfa', fn () => DomainEvent::query()->where('type', 'product_feed.listing_updated')->sole());
        $this->assertSame(['brand', 'condition', 'google_product_category', 'mpn_from_sku'], array_values(array_intersect(['brand', 'condition', 'google_product_category', 'mpn_from_sku'], $event->payload['changed'])));
        $this->assertSame($owner->actor(), $event->actor);
        $this->assertStringNotContainsString('帆布工房', json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $url = $this->issue('meta');
        $raw = (string) $this->shop($this->path($url))->assertOk()->getContent();
        [, $items] = $this->parse($raw);

        // Meta の言葉: 在庫は空白で、追加の写真と色・サイズは g: なし。identifier_exists は出さない
        $row = $items[$this->ids['tote']];
        $this->assertSame('in stock', $row['g:availability']);
        $this->assertSame(['帆布工房', '3032', 'used', 'TOTE-001'], [$row['g:brand'], $row['g:google_product_category'], $row['g:condition'], $row['g:mpn']]);
        $this->assertCount(1, $row['additional_image_link']);
        $this->assertArrayNotHasKey('g:identifier_exists', $row);
        $variant = $items[$this->ids['からし / L']];
        $this->assertSame(['out of stock', 'からし', 'L'], [$variant['g:availability'], $variant['color'], $variant['size']]);
        // 商品ごとに書かなかった商品は App の設定の既定
        $this->assertSame(['Kakuzato', '166'], [$variant['g:brand'], $variant['g:google_product_category']]);
        $this->assertStringNotContainsString('<g:color>', $raw);
    }

    public function test_excluded_products_are_left_out_and_shown_with_the_reasons_in_the_admin(): void
    {
        $this->use();
        $this->enrolledOperator();
        $this->send('PUT', "/api/tenants/pfa/listings/{$this->ids['sold']}/apps/product-feed", ['values' => ['excluded' => true]])->assertOk()
            ->assertJsonPath('app.note', '「フィードに出さない」にしているので、フィードに出ません。');
        $this->send('GET', "/api/tenants/pfa/listings/{$this->ids['photoless']}/apps")->assertOk()->assertJsonPath('apps.0.note', '写真がありません（Google と Meta は写真が要ります）。フィードに出ません。');
        $this->send('GET', "/api/tenants/pfa/listings/{$this->ids['draft']}/apps")->assertOk()->assertJsonPath('apps.0.note', 'ショップに公開していない商品は、フィードに出ません。');
        $this->send('GET', "/api/tenants/pfa/listings/{$this->ids['apron']}/apps")->assertOk()->assertJsonPath('apps.0.note', 'この商品はフィードに、種類ごとに 4 行載ります。');

        $url = $this->issue('google');
        [, $items] = $this->parse((string) $this->shop($this->path($url))->assertOk()->getContent());
        $this->assertArrayNotHasKey($this->ids['sold'], $items);
        $this->assertCount(5, $items);

        // 1 件の画面: 数と、外した商品・出せない商品（理由つき）。URL には写す印
        $id = $this->feedId('google');
        $record = $this->send('GET', self::BASE.'/'.$id)->assertOk()->json('record');
        $facts = array_column($record['facts'], null, 'label');
        $this->assertSame($url, $facts['フィードの URL']['value']);
        $this->assertTrue($facts['フィードの URL']['copy']);
        $this->assertArrayNotHasKey('copy', $facts['形式']);
        $this->assertSame([5, 2, 1, 1], [$facts['フィードの行数（種類ごと）']['value'], $facts['フィードに載る商品']['value'], $facts['フィードから外した商品']['value'], $facts['フィードに載せられない商品']['value']]);
        $this->assertSame($this->ids['sold'], $record['tables'][0]['rows'][0]['cells']['listing']['id']);
        $this->assertSame(['写真の無い品', '写真がありません（Google と Meta は写真が要ります）'], [$record['tables'][1]['rows'][0]['cells']['listing']['label'], $record['tables'][1]['rows'][0]['cells']['reason']]);
        $this->assertSame(['rotate', 'stop'], array_column($record['actions'], 'key'));
    }

    public function test_wrong_rotated_stopped_or_another_shops_tokens_get_nothing(): void
    {
        $this->use();
        [$owner, $key] = $this->enrolledOperator();
        $google = $this->path($this->issue('google'));
        $token = basename($google, '.xml');

        // 違うトークン・違う行き先・形の違うトークンは 404
        $this->shop('/p/product-feed/google/'.str_repeat('0', 40).'.xml')->assertNotFound();
        $this->shop("/p/product-feed/meta/{$token}.xml")->assertNotFound();
        $this->shop('/p/product-feed/google/'.strtoupper($token).'.xml')->assertNotFound();
        $this->shop("/p/product-feed/google/{$token}x.xml")->assertNotFound();

        // ほかの店のホストからは、同じトークンでも出さない（店ごとに表が分かれている）
        $this->tenant('pfb');
        $this->inside('pfb', fn () => app(PluginStates::class)->set('product-feed', true, 'test'));
        $this->shop($google, 'pfb')->assertNotFound();

        // 作り直す・止めるは取り消せないので、直前の再確認の後だけ。記録にトークンは入らない
        $id = $this->feedId('google');
        $this->send('POST', self::BASE."/{$id}/actions/rotate")->assertStatus(423);
        $this->shop($google)->assertOk();
        $this->reconfirm($owner, $key);
        $rotated = $this->send('POST', self::BASE."/{$id}/actions/rotate")->assertOk()->json('record.facts.1.value');
        $this->assertIsString($rotated);
        $this->assertNotSame($google, $this->path($rotated));
        $this->shop($google)->assertNotFound();
        $this->shop($this->path($rotated))->assertOk();

        $this->send('POST', self::BASE."/{$id}/actions/stop")->assertOk()->assertJsonPath('record.facts.1.value', null);
        $this->shop($this->path($rotated))->assertNotFound();
        $events = $this->inside('pfa', fn () => DomainEvent::query()->where('type', 'like', 'product_feed.token_%')->orderBy('sequence')->get());
        $this->assertSame(['product_feed.token_issued', 'product_feed.token_rotated', 'product_feed.token_stopped'], $events->pluck('type')->all());
        $this->assertSame([['format' => 'google']], $events->pluck('payload')->unique()->values()->all());
        $this->assertSame($owner->actor(), $events[1]->actor);
        // トークンは暗号化して置く（DB の列に平文が無い）
        $this->assertSame(0, $this->inside('pfa', static fn (): int => DB::table('product_feed_feeds')->where('token', 'like', '%'.$token.'%')->count()));

        // 閲覧の段階の人は見られるが、URL を作る・作り直す・止めるはできない
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'pfa', 'read', 'test');
        $this->send('GET', self::BASE."/{$id}")->assertOk();
        $this->send('POST', self::BASE."/{$id}/actions/issue")->assertForbidden();
    }

    public function test_a_preparing_shop_does_not_hand_out_the_feed_even_to_someone_with_the_password(): void
    {
        $this->use();
        $this->enrolledOperator();
        $path = $this->path($this->issue('google'));
        $this->inside('pfa', static function (): void {
            app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'cli:test');
            app(StorefrontAccessService::class)->setPassword('preview-2026', 'cli:test');
        });

        // 準備中の店は、核の関所が「準備中です」の画面へ送る（フィードの中身は出ない）
        $response = $this->shop($path)->assertRedirect('http://pfa.localhost/password');
        $this->assertStringNotContainsString('<rss', (string) $response->getContent());

        // パスワードを入れた人にも出さない（フィードは公開している店だけ）
        $this->travel(PasswordController::SHOP_INTERVAL_SECONDS + 1)->seconds();
        $unlock = $this->post('http://pfa.localhost/password', ['password' => 'preview-2026']);
        tenancy()->end();
        $pass = $unlock->getCookie(ShopPasswordCookie::NAME)?->getValue();
        $this->assertIsString($pass);
        $this->shop($path, cookie: $pass)->assertNotFound();
    }

    public function test_the_feed_is_cached_for_an_hour_and_rebuilt_after_a_product_changes(): void
    {
        $this->use();

        // キャッシュは店ごと（店に入るたびに核がキャッシュの印を替える）なので、1 つの店の文脈の中で続けて読む
        $this->inside('pfa', function (): void {
            $cache = app(FeedCache::class);
            $read = static fn (): string => $cache->xml(FeedFormat::Google, 'SUGAR 工房', 'http://pfa.localhost');
            $this->assertStringContainsString('帆布 &amp; &lt;トート&gt;', $read());

            // 記録を残さずに DB だけを書き換えても、キャッシュのまま（作り直さない）
            DB::table('listings')->where('id', $this->ids['tote'])->update(['title' => 'こっそり変えた名前']);
            $this->assertStringNotContainsString('こっそり変えた名前', $read());

            // 商品を直す（記録が残る）と、次の読み込みから作り直す
            $listing = Listing::query()->findOrFail($this->ids['tote']);
            app(ListingService::class)->update($listing, $listing->version, ['title' => '直した名前'], 'cli:test');
            $this->assertStringContainsString('<g:title>直した名前</g:title>', $read());

            // 在庫を受け入れる（記録が残る。取引の確保と出庫も取引の記録が残る）と、在庫の具合も作り直す
            $this->assertMatchesRegularExpression('#<g:id>'.$this->ids['sold'].'</g:id>(?:(?!</item>).)*<g:availability>out_of_stock</g:availability>#s', $read());
            app(InventoryOperations::class)->receive(Listing::query()->findOrFail($this->ids['sold']), Location::query()->firstOrFail(), 2, 'cli:test');
            $this->assertMatchesRegularExpression('#<g:id>'.$this->ids['sold'].'</g:id>(?:(?!</item>).)*<g:availability>in_stock</g:availability>#s', $read());

            // 1 時間を過ぎると、記録が無くても作り直す
            DB::table('listings')->where('id', $this->ids['tote'])->update(['title' => '時間で直った名前']);
            $this->assertStringNotContainsString('時間で直った名前', $read());
            $this->travel(61)->minutes();
            $this->assertStringContainsString('<g:title>時間で直った名前</g:title>', $read());
        });
    }

    public function test_reads_are_limited_per_shop_and_address(): void
    {
        $this->use();
        $this->enrolledOperator();
        $path = $this->path($this->issue('google'));

        for ($i = 0; $i < FeedController::PER_HOUR; $i++) {
            $this->shop('/p/product-feed/google/'.str_repeat('1', 40).'.xml')->assertNotFound();
        }

        // 違うトークンを試し続けた接続元は、正しいトークンでも止まる。ほかの接続元は読める
        $this->shop($path)->assertStatus(429)->assertHeader('Retry-After');
        $this->shop($path, ip: '203.0.113.9')->assertOk();
    }

    /**
     * 店の商品: 種類の無い公開の商品（写真 2 枚・説明・品番）、種類のある商品（色とサイズ。1 つ売り切れ・からしは値段が違う）、
     * 在庫の無い商品、写真の無い商品、下書きの商品
     *
     * @return array<string, string>
     */
    private function catalog(): array
    {
        $seller = Seller::query()->firstOrFail();
        $location = Location::query()->firstOrFail();
        $listings = app(ListingService::class);
        $stock = app(StockService::class);

        $tote = $listings->create($seller, 'product', ['title' => '帆布 & <トート>', 'unit_amount' => 5400, 'sku' => 'TOTE-001', 'status' => ListingStatus::Active,
            'description' => "丈夫な帆布 & 革の持ち手 <b>太字ではない</b>\n2 行目"]);
        $stock->receive($tote, $location, 3);
        $this->photo($tote, 0);
        $this->photo($tote, 1);

        $apron = $listings->create($seller, 'product', ['title' => 'エプロン', 'unit_amount' => 4800, 'status' => ListingStatus::Active]);
        $variants = app(VariantService::class);
        $apron = $variants->define($apron, $apron->version, [['name' => '色', 'values' => ['墨', 'からし']], ['name' => 'サイズ', 'values' => ['M', 'L']]]);
        $ids = [];

        foreach ($apron->variants()->get() as $variant) {
            $label = $variant->label();
            $ids[$label] = $variant->id;

            if (str_starts_with($label, 'からし')) {
                $variants->update($variant, Listing::query()->findOrFail($apron->id)->version, ['unit_amount' => 5200]);
            }

            if ($label !== 'からし / L') {
                $stock->receive(ListingVariant::query()->findOrFail($variant->id), $location, 5);
            }
        }

        $this->photo($apron, 0);

        $sold = $listings->create($seller, 'product', ['title' => '在庫の無い品', 'unit_amount' => 1200, 'status' => ListingStatus::Active]);
        $this->photo($sold, 0);
        $photoless = $listings->create($seller, 'product', ['title' => '写真の無い品', 'unit_amount' => 900, 'status' => ListingStatus::Active]);
        $stock->receive($photoless, $location, 1);
        $draft = $listings->create($seller, 'product', ['title' => '下書きの品', 'unit_amount' => 900, 'status' => ListingStatus::Draft]);
        $this->photo($draft, 0);

        return ['tote' => $tote->id, 'apron' => $apron->id, 'sold' => $sold->id, 'photoless' => $photoless->id, 'draft' => $draft->id] + $ids;
    }

    private function photo(Listing $listing, int $position): void
    {
        ListingImage::create(['listing_id' => $listing->id, 'position' => $position, 'width' => 800, 'height' => 800, 'checksum' => str_repeat('a', 64)]);
    }

    private function use(): void
    {
        $this->inside('pfa', fn () => app(PluginStates::class)->set('product-feed', true, 'test'));
    }

    private function feedId(string $format): string
    {
        return $this->inside('pfa', static fn (): string => Feed::query()->where('format', $format)->value('id'));
    }

    /**
     * 管理画面で URL を作り、1 件の画面に出た URL を返す
     */
    private function issue(string $format): string
    {
        $id = $this->feedId($format);
        $this->assertContains($id, array_column($this->send('GET', self::BASE)->assertOk()->json('rows'), 'id'));
        $url = $this->send('POST', self::BASE."/{$id}/actions/issue")->assertOk()->json('record.facts.1.value');
        $this->assertIsString($url);

        return $url;
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    /**
     * ショップのホストで GET する（運営のセッションの Cookie を持ち越さない）
     *
     * @return TestResponse<Response>
     */
    private function shop(string $path, string $tenant = 'pfa', ?string $ip = null, ?string $cookie = null): TestResponse
    {
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withServerVariables(['REMOTE_ADDR' => $ip ?? '198.51.100.7']);

        if ($cookie !== null) {
            $this->withCookie(ShopPasswordCookie::NAME, $cookie);
        }

        $response = $this->get("http://{$tenant}.localhost{$path}");
        tenancy()->end();
        $this->withServerVariables([]);

        return $response;
    }

    /**
     * XML として読めること（読めなければ落ちる）と、行を id ごとの項目の値に
     *
     * @return array{0: DOMXPath, 1: array<string, array<string, mixed>>}
     */
    private function parse(string $xml): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml, LIBXML_NONET), 'XML として読めること');
        $this->assertSame('rss', $document->documentElement?->tagName);
        $this->assertSame('2.0', $document->documentElement->getAttribute('version'));
        $this->assertSame(FeedWriter::NAMESPACE, $document->documentElement->lookupNamespaceURI('g'));
        $xpath = new DOMXPath($document);
        $items = [];

        foreach ($xpath->query('/rss/channel/item') ?: [] as $item) {
            $this->assertInstanceOf(DOMElement::class, $item);
            $fields = [];

            foreach ($item->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                if (in_array($child->tagName, ['g:additional_image_link', 'additional_image_link'], true)) {
                    $fields[$child->tagName][] = $child->textContent;
                } else {
                    $fields[$child->tagName] = $child->textContent;
                }
            }

            // 必須の項目（両方の行き先）
            foreach (['g:id', 'g:title', 'g:description', 'g:link', 'g:image_link', 'g:availability', 'g:price', 'g:condition', 'g:brand'] as $required) {
                $this->assertNotSame('', $fields[$required] ?? '', "{$required} が要る");
            }

            $this->assertMatchesRegularExpression('/\A[0-9]+ JPY\z/', $fields['g:price']);
            $items[(string) $fields['g:id']] = $fields;
        }

        return [$xpath, $items];
    }
}
