<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use EcSugar\PluginCatalogSort\CatalogSortBrowse;
use EcSugar\PluginCatalogSort\CatalogSortItems;
use EcSugar\PluginCatalogSort\Models\CatalogSortItem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「並び順と絞り込み」。店が商品一覧の並びを手で決め（おすすめ順）、お客さんが価格の帯・タグ・在庫ありで絞り込む。
 *
 * 確かめること: 使っていない店では管理画面にもショップにも出ない・並びが効く・絞り込みが効く・
 * おかしな入力（知らない値・長すぎる値・列の名前・配列）はただ無視する・店が出さない設定にしたら出ない・
 * ほかの店の並びとタグは混ざらない・タグと商品の入力は断る
 */
final class CatalogSortAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BASE = '/api/tenants/csa/apps/catalog-sort/records';

    /** @var array<string, string> 商品の名前 => ID */
    private array $listings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->tenant('csa');
        $this->listings = $this->inside('csa', function (): array {
            $location = Location::query()->firstOrFail();
            $stock = app(StockService::class);
            $out = [];

            // 値段と在庫の違う 4 点（タオルは売り切れ）
            foreach ([['ひのきの箸', 900, 3], ['帆布のトートバッグ', 4900, 2], ['陶器のマグ', 2500, 5], ['白いタオル', 1200, 0]] as [$title, $amount, $quantity]) {
                $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => ListingStatus::Active]);

                if ($quantity > 0) {
                    $stock->receive($listing, $location, $quantity);
                }

                $out[$title] = $listing->id;
            }

            return $out;
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_an_unused_app_changes_nothing_in_the_admin_or_the_shop(): void
    {
        $this->enrolledOperator();
        // 使っていない店でも表はあり、並びを置いても効かない
        $this->inside('csa', fn () => CatalogSortItem::query()->create(['listing_id' => $this->listings['白いタオル'], 'position' => 1, 'tags' => ['新作']]));

        $this->send('GET', '/api/tenants/csa/app-pages')->assertOk()->assertExactJson(['pages' => []]);
        $this->send('GET', self::BASE)->assertNotFound();
        $this->send('POST', self::BASE, ['values' => ['listing' => $this->listings['陶器のマグ']]])->assertNotFound();

        // ショップの一覧は今までどおり（並べ替えは 3 つ、絞り込みは出ない、並びは新着順）
        $list = $this->page('/products')->assertOk();
        $list->assertDontSee('data-test="list-filters"', false);
        $list->assertDontSee('data-test="sort-shop"', false);
        $this->assertSame(['白いタオル', '陶器のマグ', '帆布のトートバッグ', 'ひのきの箸'], $this->titles($list));

        $this->enable();
        $this->send('GET', '/api/tenants/csa/app-pages')->assertOk()->assertJsonFragment(['plugin' => 'catalog-sort', 'title' => '並び順と絞り込み']);
        $shown = $this->page('/products')->assertOk();
        $shown->assertSee('data-test="list-filters"', false);
        $shown->assertSee('data-test="sort-shop"', false);
    }

    public function test_the_shop_decides_the_order_and_the_list_follows_it(): void
    {
        $this->enable();
        [$owner, $key] = $this->enrolledOperator();

        $mug = $this->add('陶器のマグ');
        $bag = $this->add('帆布のトートバッグ');

        // 一覧（管理画面）は並びの順。記録にはタグの文を入れない
        $this->assertSame([$mug, $bag], array_column($this->send('GET', self::BASE)->assertOk()->json('rows'), 'id'));
        $created = $this->inside('csa', fn () => DomainEvent::query()->where('type', 'catalog_sort.created')->get());
        $this->assertCount(2, $created);
        $this->assertSame($owner->actor(), $created[0]->actor);
        $this->assertEquals(['listing_id' => $this->listings['陶器のマグ'], 'tags' => 0], $created[0]->payload);

        // ショップ: はじめから「おすすめ順」（設定の既定）。決めた 2 点が先頭で、残りは新着順
        $this->assertSame(['陶器のマグ', '帆布のトートバッグ', '白いタオル', 'ひのきの箸'], $this->titles($this->page('/products')));
        // 新着順も今までどおり選べる
        $this->assertSame(['白いタオル', '陶器のマグ', '帆布のトートバッグ', 'ひのきの箸'], $this->titles($this->page('/products?sort=new')));

        // 下へ・上へで入れ替わり、ショップの並びも変わる
        $this->send('POST', self::BASE.'/'.$mug.'/actions/move_down')->assertOk();
        $this->assertSame(['帆布のトートバッグ', '陶器のマグ', '白いタオル', 'ひのきの箸'], $this->titles($this->page('/products')));
        $this->send('POST', self::BASE.'/'.$mug.'/actions/move_up')->assertOk();
        $this->assertSame(['陶器のマグ', '帆布のトートバッグ', '白いタオル', 'ひのきの箸'], $this->titles($this->page('/products')));
        // 端の商品には、その向きの操作が無い（押しても 404）
        $this->send('POST', self::BASE.'/'.$mug.'/actions/move_up')->assertNotFound();
        $this->assertSame(2, $this->inside('csa', fn () => DomainEvent::query()->where('type', 'catalog_sort.moved')->count()));

        // 「はじめから」を切ると、既定は新着順のまま（おすすめ順は選べる）
        $this->settings(['order_first' => false]);
        $this->assertSame(['白いタオル', '陶器のマグ', '帆布のトートバッグ', 'ひのきの箸'], $this->titles($this->page('/products')));
        $this->assertSame(['陶器のマグ', '帆布のトートバッグ', '白いタオル', 'ひのきの箸'], $this->titles($this->page('/products?sort=shop')));

        // 一覧から外すと、ショップの並びは新着順に戻る（商品は残る。取り消せない操作なので、直前の再確認の後だけ）
        $this->send('POST', self::BASE.'/'.$mug.'/actions/remove')->assertStatus(423);
        $this->reconfirm($owner, $key);
        $this->send('POST', self::BASE.'/'.$mug.'/actions/remove')->assertOk();
        $this->assertSame(1, $this->inside('csa', fn () => CatalogSortItem::query()->count()));
        $this->assertSame(4, $this->inside('csa', fn () => Listing::query()->count()));
        $this->assertSame(['帆布のトートバッグ', '白いタオル', '陶器のマグ', 'ひのきの箸'], $this->titles($this->page('/products?sort=shop')));
    }

    public function test_customers_filter_by_price_tag_and_stock(): void
    {
        $this->enable();
        $this->enrolledOperator();
        $this->add('ひのきの箸', '新作, ギフト');
        $this->add('帆布のトートバッグ', 'ギフト');
        $this->add('白いタオル', '新作');

        // 価格の帯（既定の区切り 1000・3000・5000・10000）
        $cheap = $this->page('/products?price=b0')->assertOk();
        $this->assertSame(['ひのきの箸'], $this->titles($cheap));
        $this->assertSame(['白いタオル', '陶器のマグ'], $this->titles($this->page('/products?price=b1&sort=new')));
        $this->assertSame(['帆布のトートバッグ'], $this->titles($this->page('/products?price=b2')));
        $this->assertSame([], $this->titles($this->page('/products?price=b4')));

        // タグ（URL に出るのは名前から作る短い印）
        $gift = CatalogSortBrowse::key('ギフト');
        $new = CatalogSortBrowse::key('新作');
        $this->assertSame(['ひのきの箸', '帆布のトートバッグ'], $this->titles($this->page("/products?tag={$gift}")));
        $this->assertSame(['ひのきの箸', '白いタオル'], $this->titles($this->page("/products?tag={$new}")));

        // 在庫あり（売り切れのタオルは出ない）
        $this->assertSame(['陶器のマグ', '帆布のトートバッグ', 'ひのきの箸'], $this->titles($this->page('/products?stock=1&sort=new')));
        // 重ねられる（新作 かつ 在庫あり）
        $this->assertSame(['ひのきの箸'], $this->titles($this->page("/products?tag={$new}&stock=1")));

        // 絞り込みは、並べ替えとページ送りの URL にも残る
        $filtered = $this->page("/products?tag={$gift}&sort=price_desc")->assertOk();
        $this->assertSame(['帆布のトートバッグ', 'ひのきの箸'], $this->titles($filtered));
        $filtered->assertSee('tag='.$gift, false);
        // 選んでいる所に印が付き、同じものを押すと外れる URL になる
        $filtered->assertSee('data-test="filter-tag-'.$gift.'"', false);
        $this->assertStringNotContainsString("tag={$gift}", $this->linkFor($filtered, 'filter-tag-'.$gift));
    }

    public function test_odd_filter_input_is_ignored_and_never_reaches_the_query(): void
    {
        $this->enable();
        $this->enrolledOperator();
        $this->add('ひのきの箸', '新作');
        // この店の既定は「おすすめ順」（決めた 1 点が先頭、残りは新着順）
        $all = ['ひのきの箸', '白いタオル', '陶器のマグ', '帆布のトートバッグ'];

        foreach ([
            '/products?price=nope',                                  // 知らない帯
            '/products?price='.str_repeat('b', 300),                 // 長すぎる値
            '/products?tag=listings.unit_amount',                    // 列の名前
            '/products?tag='.rawurlencode("' OR 1=1 --"),            // SQL のつもりの値
            '/products?tag='.rawurlencode('新作'),                    // タグの名前そのまま（印でないと受けない）
            '/products?stock=yes',                                   // 1 以外
            '/products?price[]=b0&tag[]=x&stock[]=1',                // 配列
            '/products?price=b0&price=b2',                           // 同じ引数を 2 つ（後ろが勝つのは PHP の決まり。b2 は 1 点）
        ] as $path) {
            $response = $this->page($path)->assertOk();
            $titles = $this->titles($response);
            $this->assertSame($path === '/products?price=b0&price=b2' ? ['帆布のトートバッグ'] : $all, $titles, $path);
        }

        // 知らない並べ替えは既定（この店は「おすすめ順」）に落ちる
        $this->assertSame($all, $this->titles($this->page('/products?sort=title;drop')));
    }

    public function test_filters_the_shop_turns_off_are_neither_shown_nor_applied(): void
    {
        $this->enable();
        $this->enrolledOperator();
        $this->add('ひのきの箸', '新作');
        $new = CatalogSortBrowse::key('新作');
        $all = ['ひのきの箸', '白いタオル', '陶器のマグ', '帆布のトートバッグ'];

        $this->settings(['price_filter' => false, 'tag_filter' => false, 'stock_filter' => false]);
        $off = $this->page('/products')->assertOk();
        $off->assertDontSee('data-test="list-filters"', false);
        // 出さない絞り込みは、URL で指しても効かない
        $this->assertSame($all, $this->titles($this->page("/products?price=b0&tag={$new}&stock=1")));

        // 価格だけ出す設定にすると、価格の帯だけが出る（区切りも店が決められる）
        $this->settings(['price_filter' => true, 'price_bands' => '2000']);
        $one = $this->page('/products')->assertOk();
        $one->assertSee('data-test="filter-price-b0"', false);
        $one->assertDontSee('data-test="filter-tag-'.$new.'"', false);
        $one->assertDontSee('data-test="filter-stock-1"', false);
        $this->assertSame(['白いタオル', 'ひのきの箸'], $this->titles($this->page('/products?price=b0&sort=new')));
        $this->assertSame(['陶器のマグ', '帆布のトートバッグ'], $this->titles($this->page('/products?price=b1&sort=new')));
        $this->assertSame($all, $this->titles($this->page("/products?tag={$new}")));
    }

    public function test_another_shop_sees_neither_the_order_nor_the_tags(): void
    {
        $this->tenant('csb');
        $theirs = $this->inside('csb', fn (): string => Listing::create([
            'seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'B の店の湯のみ', 'unit_amount' => 800, 'status' => ListingStatus::Active,
        ])->id);

        foreach (['csa', 'csb'] as $shop) {
            $this->inside($shop, fn () => app(PluginStates::class)->set('catalog-sort', true, 'test'));
        }

        [$owner, $key] = $this->enrolledOperator();
        $this->reconfirm($owner, $key);
        $mine = $this->add('陶器のマグ', '新作');

        // B の店の経路から A の店の記録は無いのと同じ（読む・直す・操作）
        $other = '/api/tenants/csb/apps/catalog-sort/records';
        $this->send('GET', $other)->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', "{$other}/{$mine}")->assertNotFound();
        $this->send('PUT', "{$other}/{$mine}", ['values' => ['listing' => $theirs]])->assertNotFound();
        $this->send('POST', "{$other}/{$mine}/actions/remove")->assertNotFound();

        // A の店の記録に B の店の商品は選べない（選択肢にも無い）
        $this->send('PUT', self::BASE.'/'.$mine, ['values' => ['listing' => $theirs]])->assertUnprocessable()->assertJsonValidationErrors(['values.listing']);
        $this->assertNotContains($theirs, array_column($this->send('GET', self::BASE)->json('form.fields.0.options'), 0));

        // B の店のショップには、A の店のタグも並びも出ない（B の店の商品だけ）
        $theirList = $this->page('/products', 'csb')->assertOk();
        $theirList->assertDontSee('data-test="filter-tag-'.CatalogSortBrowse::key('新作').'"', false);
        $this->assertSame(['B の店の湯のみ'], $this->titles($theirList));
        $this->assertSame($this->listings['陶器のマグ'], $this->inside('csa', fn () => CatalogSortItem::query()->findOrFail($mine)->listing_id));
    }

    public function test_the_app_checks_the_product_and_the_tags(): void
    {
        $this->enable();
        $this->enrolledOperator();
        $mug = $this->add('陶器のマグ', '新作');

        // 商品は要る・形が違う・この店に無い
        $this->send('POST', self::BASE, ['values' => ['tags' => '新作']])->assertUnprocessable()->assertJsonValidationErrors(['values.listing']);
        $this->send('POST', self::BASE, ['values' => ['listing' => 'not-an-id']])->assertUnprocessable()->assertJsonValidationErrors(['values.listing']);
        $this->send('POST', self::BASE, ['values' => ['listing' => '01jbq0000000000000000000zz']])->assertUnprocessable()->assertJsonValidationErrors(['values.listing']);
        // 同じ商品は 1 回だけ
        $this->send('POST', self::BASE, ['values' => ['listing' => $this->listings['陶器のマグ']]])->assertUnprocessable()->assertJsonValidationErrors(['values.listing']);

        // タグ: 多すぎる・長すぎる・記号・欄の長さ
        $bag = $this->listings['帆布のトートバッグ'];
        $this->send('POST', self::BASE, ['values' => ['listing' => $bag, 'tags' => 'a,b,c,d,e,f']])->assertUnprocessable()->assertJsonValidationErrors(['values.tags']);
        $this->send('POST', self::BASE, ['values' => ['listing' => $bag, 'tags' => str_repeat('あ', CatalogSortItems::TAG_MAX + 1)]])->assertUnprocessable()->assertJsonValidationErrors(['values.tags']);
        $this->send('POST', self::BASE, ['values' => ['listing' => $bag, 'tags' => '<script>']])->assertUnprocessable()->assertJsonValidationErrors(['values.tags']);
        $this->send('POST', self::BASE, ['values' => ['listing' => $bag, 'tags' => str_repeat('あ,', 80)]])->assertUnprocessable()->assertJsonValidationErrors(['values.tags']);
        $this->assertSame(1, $this->inside('csa', fn () => CatalogSortItem::query()->count()));

        // 同じタグを 2 回書いたら 1 つに。全角のカンマでも区切る
        $this->send('PUT', self::BASE.'/'.$mug, ['values' => ['listing' => $this->listings['陶器のマグ'], 'tags' => '新作、新作, ギフト']])->assertOk();
        $this->assertSame(['新作', 'ギフト'], $this->inside('csa', fn () => CatalogSortItem::query()->findOrFail($mug)->tags));
        $updated = $this->inside('csa', fn () => DomainEvent::query()->where('type', 'catalog_sort.updated')->sole());
        $this->assertEquals(['changed' => ['tags'], 'listing_id' => $this->listings['陶器のマグ'], 'tags' => 2], $updated->payload);
    }

    /**
     * 店で App を使うにする
     */
    private function enable(): void
    {
        $this->inside('csa', fn () => app(PluginStates::class)->set('catalog-sort', true, 'test'));
    }

    /**
     * App の設定を変える
     *
     * @param  array<string, bool|string>  $values
     */
    private function settings(array $values): void
    {
        $this->inside('csa', fn () => app(PluginSettings::class)->update('catalog-sort', $values, 'test'));
    }

    /**
     * 管理画面から商品を並びに足す
     */
    private function add(string $title, string $tags = ''): string
    {
        $id = $this->send('POST', self::BASE, ['values' => ['listing' => $this->listings[$title], 'tags' => $tags]])->assertCreated()->json('id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * @return TestResponse<Response>
     */
    private function page(string $path, string $shop = 'csa'): TestResponse
    {
        $response = $this->get("http://{$shop}.localhost{$path}");
        tenancy()->end();

        return $response;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function titles(TestResponse $response): array
    {
        preg_match_all('/data-test="product-card".*?<span class="[^"]*leading-normal[^"]*">([^<]+)<\/span>/s', (string) $response->getContent(), $m);

        return array_map(static fn (string $t): string => html_entity_decode($t), $m[1]);
    }

    /**
     * その印の付いたリンクの行き先
     *
     * @param  TestResponse<Response>  $response
     */
    private function linkFor(TestResponse $response, string $test): string
    {
        preg_match('/<a href="([^"]*)"[^>]*data-test="'.preg_quote($test, '/').'"/s', (string) $response->getContent(), $m);
        $this->assertNotEmpty($m, "{$test} のリンクが無い");

        return html_entity_decode($m[1]);
    }
}
