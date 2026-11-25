<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店頭の商品の写真。公開中の商品の写真だけを、セッションを持たない経路で配る。
 */
final class StorefrontMediaTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_photos_of_published_products_are_served_without_cookies_and_cached(): void
    {
        $this->tenant('sma');
        $imageId = $this->inside('sma', fn (): string => $this->photo($this->listing('白いシャツ', ListingStatus::Active), 2000, 1000)->id);

        $response = $this->get("http://sma.localhost/media/listings/{$imageId}/medium")->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', (string) config('storefront.csp'));
        $this->assertSame([800, 400], array_slice((array) getimagesizefromstring((string) $response->getContent()), 0, 2));
        $this->assertSame([1600, 800], array_slice((array) getimagesizefromstring((string) $this->get("http://sma.localhost/media/listings/{$imageId}/large")->getContent()), 0, 2));

        // セッションを始めない（Cookie を返さない）ので、共有のキャッシュに載せられる
        $this->assertNoCookies($response);
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=86400', $cache);

        // 同じ写真は 304（中身を送り直さない）。大きさが違えば別のもの
        $etag = (string) $response->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $notModified = $this->withHeaders(['If-None-Match' => $etag])->get("http://sma.localhost/media/listings/{$imageId}/medium")->assertStatus(304);
        $this->assertSame('', (string) $notModified->getContent());
        $this->withHeaders(['If-None-Match' => $etag])->get("http://sma.localhost/media/listings/{$imageId}/large")->assertOk();
    }

    public function test_photos_that_are_not_public_are_not_found(): void
    {
        $this->tenant('smb');
        $this->tenant('smc');
        [$draft, $archived, $removed, $published] = $this->inside('smb', function (): array {
            $draft = $this->photo($this->listing('下書きのコート', ListingStatus::Draft), 20, 20);
            $archivedListing = $this->listing('終了したニット', ListingStatus::Active);
            $archived = $this->photo($archivedListing, 20, 20);
            $archivedListing->update(['status' => ListingStatus::Archived]);
            $removed = $this->photo($this->listing('写真を消した靴', ListingStatus::Active), 20, 20);
            app(ListingImageService::class)->remove($removed);
            $published = $this->photo($this->listing('公開中の帽子', ListingStatus::Active), 20, 20);

            return [$draft->id, $archived->id, $removed->id, $published->id];
        });
        $other = $this->inside('smc', fn (): string => $this->photo($this->listing('別の店のバッグ', ListingStatus::Active), 20, 20)->id);
        tenancy()->end();

        foreach ([
            '下書きの商品' => "http://smb.localhost/media/listings/{$draft}/medium",
            '終了した商品' => "http://smb.localhost/media/listings/{$archived}/medium",
            '消した写真' => "http://smb.localhost/media/listings/{$removed}/medium",
            '別の店の写真（ID を知っていても）' => "http://smb.localhost/media/listings/{$other}/medium",
            '中央のホスト' => "http://localhost/media/listings/{$published}/medium",
            '決まっていない大きさ' => "http://smb.localhost/media/listings/{$published}/original",
            '道をさかのぼる' => "http://smb.localhost/media/listings/{$published}/..%2F..%2F..%2F.env",
            'ULID でない' => 'http://smb.localhost/media/listings/1%20or%201=1/medium',
        ] as $name => $url) {
            $response = $this->get($url);
            $this->assertSame(404, $response->status(), $name);
            tenancy()->end();
        }

        $this->get("http://smb.localhost/media/listings/{$published}/medium")->assertOk();
    }

    public function test_the_top_shows_the_first_photo_with_its_alt_text(): void
    {
        $this->tenant('smd');
        [$first, $untitled] = $this->inside('smd', function (): array {
            $shirt = $this->listing('白いシャツ', ListingStatus::Active);
            $first = $this->photo($shirt, 1200, 1500, '"><script>alert(1)</script>正面');
            $this->photo($shirt, 1200, 1500, '後ろ姿');
            $untitled = $this->photo($this->listing('名前が代わりの文字になるパンツ', ListingStatus::Active), 600, 750);
            $this->listing('写真の無いベルト', ListingStatus::Active);

            return [$first->id, $untitled->id];
        });

        $page = $this->get('http://smd.localhost/')->assertOk();
        $html = (string) $page->getContent();

        // 1 枚目だけ。中くらいは長い辺を 800 に縮めたもの（縦長なら幅 640）。縦横を入れて、読み込み中に並びがずれないようにする
        $this->assertStringContainsString('src="/media/listings/'.$first.'/medium"', $html);
        $this->assertStringContainsString('srcset="/media/listings/'.$first.'/medium 640w, /media/listings/'.$first.'/large 1200w"', $html);
        $this->assertStringContainsString('width="1200" height="1500"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringNotContainsString('後ろ姿', $html);
        // 代わりの文字はエスケープして出す
        $this->assertStringContainsString('alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;正面"', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        // 代わりの文字が無ければ商品名。小さい写真は 1 つの大きさだけ
        $this->assertStringContainsString('alt="名前が代わりの文字になるパンツ"', $html);
        $this->assertStringContainsString('srcset="/media/listings/'.$untitled.'/large 600w"', $html);
        // 写真の無い商品は、写真の代わりの絵（既定のテーマは角砂糖）
        $page->assertSee('data-test="no-photo"', false);
        // 横から出るカートの雛形（template の中の src の無い img）は数えない
        $this->assertSame(2, substr_count($html, '<img src='));
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertNoCookies(TestResponse $response): void
    {
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Set-Cookie'));
    }

    private function listing(string $title, ListingStatus $status): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => $status]);
    }

    private function photo(Listing $listing, int $width, int $height, ?string $alt = null): ListingImage
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sfm');
        file_put_contents($path, $this->jpeg($width, $height));
        $this->files[] = $path;

        return app(ListingImageService::class)->add($listing, $path, $alt);
    }
}
