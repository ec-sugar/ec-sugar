<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Shop\Models\ShopSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Models\Tenant;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * 店頭の土台（ADR 0018）。画面は既定のテーマが描き、テーマは店頭が渡すデータだけを出す。
 */
final class StorefrontTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<Tenant> */
    private array $tenants = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); // ビルドの成果物に頼らない
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->tenants as $tenant) {
            $tenant->delete();
        }

        parent::tearDown();
    }

    public function test_the_top_shows_only_published_fixed_price_products_of_this_shop(): void
    {
        $this->tenant('sfa', '<b>古着</b>の店');
        $this->tenant('sfb', '別の店');

        $this->inside('sfa', function (): void {
            $seller = Seller::query()->firstOrFail();
            $store = Location::query()->firstOrFail();
            $stocks = app(StockService::class);

            $plenty = $this->listing($seller, '在庫のあるコート', 18000, ListingStatus::Active);
            $stocks->receive($plenty, $store, 5);
            $few = $this->listing($seller, '残りわずかのニット', 8800, ListingStatus::Active);
            $stocks->receive($few, $store, 2);
            $this->listing($seller, '売り切れのスニーカー', 1650, ListingStatus::Active);
            $this->listing($seller, '下書きのシャツ', 3300, ListingStatus::Draft);
            $this->listing($seller, '終了したマフラー', 12000, ListingStatus::Archived);
        });
        $this->inside('sfb', function (): void {
            $this->listing(Seller::query()->firstOrFail(), '別の店の商品', 999, ListingStatus::Active);
        });

        $page = $this->get('http://sfa.localhost/')->assertOk();

        $page->assertSee('在庫のあるコート')->assertSee('¥18,000')
            ->assertSee('残りわずかのニット')->assertSee('残り 2 点')
            ->assertSee('売り切れのスニーカー')->assertSee('売り切れ')
            ->assertDontSee('下書きのシャツ')->assertDontSee('終了したマフラー')->assertDontSee('別の店の商品');
        $this->assertSame(3, substr_count((string) $page->getContent(), 'data-test="product-card"'));

        // 店の名前の HTML は文字として出る（テーマは {{ }} で必ずエスケープする）
        $page->assertSee('&lt;b&gt;古着&lt;/b&gt;の店', false)->assertDontSee('<b>古着</b>', false);

        // 在庫の数そのものは出さない（5 個あることは分からない）
        $page->assertDontSee('残り 5 点');
    }

    public function test_storefront_responses_carry_the_strict_headers_even_when_refusing(): void
    {
        $this->tenant('sfh', 'ヘッダの店');

        foreach ([
            [$this->get('http://sfh.localhost/'), 200],
            [$this->get('http://sfh.localhost/legal/commerce'), 200],
            [$this->get('http://sfh.localhost/nope'), 404],
            [$this->get('http://sfh.localhost/?q=%E3%5F'), 400],
            [$this->get('http://sfh.localhost/?q=a%00b'), 400],
        ] as [$response, $status]) {
            $response->assertStatus($status);
            $this->assertSame(config('storefront.csp'), $response->headers->get('Content-Security-Policy'), (string) $status);
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
            $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

            // 断った応答もテーマの画面（店の名前と、トップへ戻る道がある）
            if ($status !== 200) {
                $response->assertSee('data-test="error-page"', false)->assertSee('ヘッダの店')->assertSee("エラー {$status}");
            }
        }

        $csp = (string) config('storefront.csp');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe', $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_the_legal_page_shows_only_well_formed_items(): void
    {
        $tenant = $this->tenant('sfl', '表記の店');

        $this->get('http://sfl.localhost/legal/commerce')->assertOk()->assertSee('data-test="legal-empty"', false);
        // テストの中ではリクエストの後もテナントの文脈が残り、次のリクエストが古いテナントの情報を使い続ける（本番は毎回作り直す）
        tenancy()->end();

        // 表記は店の設定。暗号化した列に、形の崩れた行が入っていても出さない
        tenancy()->initialize($tenant);
        app(ShopSettingsService::class)->setLegal(['items' => [['label' => '販売業者', 'value' => '株式会社 表記']]]);
        $settings = ShopSettings::query()->sole();
        $settings->legal = [...$settings->legal ?? [], ['label' => 'こわれた項目'], 'ただの文字', ['label' => ['x'], 'value' => 'y']];
        $settings->save();
        tenancy()->end();

        // 中央のテナントの行（前の置き場）にあっても出さない
        $tenant->setAttribute('legal', [['label' => '前の保存先', 'value' => 'x']]);
        $tenant->save();

        $this->get('http://sfl.localhost/legal/commerce')->assertOk()
            ->assertSee('株式会社 表記')
            ->assertDontSee('こわれた項目')->assertDontSee('ただの文字')->assertDontSee('前の保存先');
    }

    public function test_the_central_and_admin_hosts_are_not_storefronts(): void
    {
        $this->getJson('http://localhost/')->assertOk()->assertJson(['scope' => 'central']);
        $this->get('http://nope.localhost/')->assertNotFound();
        $this->assertFalse(tenancy()->initialized);
    }

    private function listing(Seller $seller, string $title, int $amount, ListingStatus $status): Listing
    {
        return Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => $status]);
    }

    private function tenant(string $id, string $name): Tenant
    {
        $tenant = Tenant::create(['id' => $id, 'name' => $name]);
        $tenant->domains()->create(['domain' => "{$id}.localhost"]);
        $this->tenants[] = $tenant;

        return $tenant;
    }

    private function inside(string $id, Closure $check): void
    {
        tenancy()->initialize(Tenant::query()->findOrFail($id));

        try {
            $check();
        } finally {
            tenancy()->end();
        }
    }
}
