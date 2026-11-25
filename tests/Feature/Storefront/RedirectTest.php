<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Services\CategoryService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Section\Models\ShopPage;
use App\Core\Section\Services\ShopPageService;
use App\Core\Shop\Models\Redirect;
use App\Core\Shop\Services\RedirectService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 301 の転送（ADR 0061 の 10・11）。「見つかりません」の時だけ、同じショップの中へ。外へ飛ばさない・ループを作らない・上限・店の境界
 */
final class RedirectTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_an_old_url_that_is_not_found_moves_to_the_new_url_in_the_same_shop_only(): void
    {
        $this->tenant('rda');
        $this->tenant('rdb');
        $this->inside('rda', function (): void {
            $redirects = app(RedirectService::class);
            $redirects->add(['from' => 'https://old-shop.example/item/12/', 'to' => '/products?sort=new'], 'cli:test');
            $redirects->add(['from' => '/商品/ニット', 'to' => '/categories/knit'], 'cli:test');
            $redirects->add(['from' => '/item.html?id=3', 'to' => '/pages/about'], 'cli:test');
            // 今ある画面の URL を元にしても、画面が先（上書きしない）
            $redirects->add(['from' => '/products', 'to' => '/pages/about'], 'cli:test');
            $this->assertSame(['cli:test'], DomainEvent::query()->where('type', 'shop.redirect_added')->distinct()->pluck('actor')->all());
        });

        $this->page('rda', '/item/12')->assertStatus(301)->assertHeader('Location', 'http://rda.localhost/products?sort=new');
        $this->page('rda', '/item/12/')->assertStatus(301);
        $this->page('rda', '/%E5%95%86%E5%93%81/%E3%83%8B%E3%83%83%E3%83%88')->assertStatus(301)->assertHeader('Location', 'http://rda.localhost/categories/knit');
        $this->page('rda', '/item.html?id=3')->assertStatus(301)->assertHeader('Location', 'http://rda.localhost/pages/about');
        $this->page('rda', '/item.html?id=4')->assertNotFound();
        $this->page('rda', '/products')->assertOk();
        // GET と HEAD だけ（送る操作は転送しない）
        $this->assertNotSame(301, $this->post('http://rda.localhost/item/12')->status());
        tenancy()->end();
        // ほかの店の転送は効かない
        $this->page('rdb', '/item/12')->assertNotFound();
    }

    public function test_targets_outside_the_shop_are_refused(): void
    {
        $this->tenant('rdc');
        $this->inside('rdc', function (): void {
            $redirects = app(RedirectService::class);

            foreach (['//evil.example/x', '/\\evil.example', 'https://evil.example/', 'javascript:alert(1)', 'evil.example', '', "/a\nb", '/a\\b', "/\t/evil.example"] as $to) {
                try {
                    $redirects->add(['from' => '/old', 'to' => $to], 'cli:test');
                    $this->fail('断られなかった: '.json_encode($to));
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('to', $e->errors(), (string) json_encode($to));
                }
            }

            foreach (['//evil.example/x', 'old', '/', "/a\nb", '/a b'] as $from) {
                try {
                    $redirects->add(['from' => $from, 'to' => '/products'], 'cli:test');
                    $this->fail('断られなかった: '.json_encode($from));
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('from', $e->errors(), (string) json_encode($from));
                }
            }

            // 日本語の行き先は % の形に直して持つ
            $this->assertSame('/pages/%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B', $redirects->add(['from' => '/news', 'to' => '/pages/お知らせ'], 'cli:test')->to_path);
            $this->assertSame(0, Redirect::query()->where('to_path', 'like', '//%')->count());

            // アプリの検査をすり抜けても、DB が外への行き先を断る
            foreach (['//evil.example', '/\\evil.example', 'https://evil.example'] as $to) {
                try {
                    DB::transaction(static fn () => DB::table('redirects')->insert(['id' => strtolower((string) Str::ulid()), 'from_path' => '/x'.Str::random(4), 'to_path' => $to, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]));
                    $this->fail('DB が断らなかった: '.$to);
                } catch (QueryException $e) {
                    $this->assertStringContainsString('redirects_to_path', $e->getMessage());
                }
            }
        });
    }

    public function test_chains_and_loops_are_not_made(): void
    {
        $this->tenant('rdd');
        $this->inside('rdd', function (): void {
            $redirects = app(RedirectService::class);
            $redirects->add(['from' => '/a', 'to' => '/b'], 'cli:test');
            // 行き先が別の転送の元なら、その行き先へ寄せる
            $this->assertSame('/b', $redirects->add(['from' => '/c', 'to' => '/a'], 'cli:test')->to_path);
            // 新しい元を行き先にしていた転送は付け替える（/a → /b、/c → /b のまま、/b → /d を足すと両方 /d へ）
            $redirects->add(['from' => '/b', 'to' => '/d'], 'cli:test');
            $this->assertSame(['/a' => '/d', '/b' => '/d', '/c' => '/d'], Redirect::query()->orderBy('from_path')->pluck('to_path', 'from_path')->all());

            // 自分へ戻るループは断る
            foreach ([['/d', '/a'], ['/e', '/e'], ['/e/', '/e']] as [$from, $to]) {
                try {
                    $redirects->add(['from' => $from, 'to' => $to], 'cli:test');
                    $this->fail("断られなかった: {$from} → {$to}");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('to', $e->errors());
                }
            }

            // 同じ元は 2 つ持たない
            $this->expectException(ValidationException::class);
            $redirects->add(['from' => '/a/', 'to' => '/f'], 'cli:test');
        });
    }

    public function test_the_number_of_redirects_has_a_limit(): void
    {
        $this->tenant('rde');
        $this->inside('rde', function (): void {
            $now = now();
            DB::table('redirects')->insert(array_map(static fn (int $i): array => [
                'id' => strtolower((string) Str::ulid()), 'from_path' => "/old/{$i}", 'to_path' => '/products', 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now,
            ], range(1, Redirect::MAX)));

            try {
                app(RedirectService::class)->add(['from' => '/one-more', 'to' => '/products'], 'cli:test');
                $this->fail('上限を超えて足せた');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('from', $e->errors());
            }

            // slug の変更は、上限に達していても通す（転送だけ足さない）
            $categories = app(CategoryService::class);
            $category = $categories->create(['name' => 'ニット', 'slug' => 'knit']);
            $this->assertSame('knits', $categories->update($category, ['slug' => 'knits'])->slug);
            $this->assertSame(Redirect::MAX, Redirect::query()->count());
        });
    }

    public function test_changing_a_category_or_page_slug_moves_the_old_url(): void
    {
        $this->tenant('rdf');
        $this->inside('rdf', function (): void {
            $categories = app(CategoryService::class);
            $category = $categories->create(['name' => 'ニット', 'slug' => 'knit']);
            $categories->update($category, ['slug' => 'knits'], 'operator:1');
            $categories->update($category->refresh(), ['slug' => 'sweaters'], 'operator:1');
            $pages = app(ShopPageService::class);
            $page = $pages->create(['title' => '私たち', 'slug' => 'about']);
            $pages->update($page->id, ['status' => ShopPage::PUBLISHED]);
            $pages->update($page->id, ['slug' => 'about-us'], 'operator:1');

            // 鎖にならない（knit も knits も sweaters へ）
            $this->assertSame([
                '/categories/knit' => '/categories/sweaters',
                '/categories/knits' => '/categories/sweaters',
                '/pages/about' => '/pages/about-us',
            ], Redirect::query()->orderBy('from_path')->pluck('to_path', 'from_path')->all());
            $this->assertSame(['auto'], Redirect::query()->distinct()->pluck('source')->all());

            // 元の slug へ戻すと、その URL は今ある画面になるので、そこからの転送は消え、逆向きができる
            $categories->update($category->refresh(), ['slug' => 'knit'], 'operator:1');
            $this->assertSame([
                '/categories/knits' => '/categories/knit',
                '/categories/sweaters' => '/categories/knit',
                '/pages/about' => '/pages/about-us',
            ], Redirect::query()->orderBy('from_path')->pluck('to_path', 'from_path')->all());
        });

        $this->page('rdf', '/categories/sweaters')->assertStatus(301)->assertHeader('Location', 'http://rdf.localhost/categories/knit');
        $this->page('rdf', '/categories/knit')->assertOk();
        $this->page('rdf', '/pages/about')->assertStatus(301)->assertHeader('Location', 'http://rdf.localhost/pages/about-us');
        $this->page('rdf', '/pages/about-us')->assertOk();
    }

    /**
     * @return TestResponse<Response>
     */
    private function page(string $tenant, string $path): TestResponse
    {
        $response = $this->get("http://{$tenant}.localhost{$path}");
        tenancy()->end();

        return $response;
    }
}
