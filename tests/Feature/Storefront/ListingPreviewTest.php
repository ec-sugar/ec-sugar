<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\OfferSide;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\ListingPreviewLink;
use App\Storefront\Apps\ConsentCookie;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 商品のプレビュー。管理画面が作る署名つきの期限つきのリンクで、その商品の画面だけを状態に関係なく開く。
 * 店の境界にかかるので、署名の書き換え・期限切れ・ほかの店・ほかの商品・準備中のショップまで確かめる
 */
final class ListingPreviewTest extends TestCase
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
        $this->tenant('lpa');
        $this->tenant('lpb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_preview_link_shows_a_draft_product_with_the_banner_and_noindex(): void
    {
        [$listingId, $imageId] = $this->inside('lpa', function (): array {
            $listing = $this->listing('まだ秘密の下書き', ListingStatus::Draft);

            return [$listing->id, $this->photo($listing)->id];
        });

        // ふだんのショップには出ない
        $this->page('lpa', "/products/{$listingId}")->assertNotFound()->assertDontSee('まだ秘密の下書き');

        $url = $this->issue('lpa', $listingId);
        $this->assertStringStartsWith(StorefrontUrls::base('lpa.localhost')."/preview/products/{$listingId}?expires=", $url);

        $response = $this->page('lpa', $url)->assertOk()
            ->assertSee('まだ秘密の下書き')
            ->assertSee('data-test="preview-banner"', false)
            ->assertSee('プレビュー（お客さんには見えていません）')
            ->assertViewHas('preview', true);
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // 署名つきの URL を次の画面（App のタグ）や外へ渡さない
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));

        // 写真は署名つきの URL で、その商品の写真だけ配る（共有のキャッシュに置かない）。署名の無い URL では見えない
        $photoUrl = $this->photoUrlIn($response, $imageId);
        $this->assertStringContainsString('token=', $photoUrl);
        $photo = $this->page('lpa', $photoUrl)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('no-store', (string) $photo->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $photo->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $photo->headers->get('Referrer-Policy'));
        $this->page('lpa', "/media/listings/{$imageId}/medium")->assertNotFound();

        // どのテーマでも、核の部品が帯を描く
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('lpa')->forceFill(['theme' => $theme])->save();
            $this->page('lpa', $url)->assertOk()->assertSee('data-test="preview-banner"', false)->assertSee('まだ秘密の下書き');
        }

        // ふだんの画面には帯が無い
        $this->inside('lpa', static fn () => Listing::query()->whereKey($listingId)->update(['status' => ListingStatus::Active]));
        $this->page('lpa', "/products/{$listingId}")->assertOk()->assertDontSee('data-test="preview-banner"', false)->assertViewHas('preview', false)
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_apps_are_not_injected_into_a_preview(): void
    {
        $listingId = $this->inside('lpa', function (): string {
            app(PluginStates::class)->set('tag-sample', true);
            app(PluginSettings::class)->update('tag-sample', ['tag_id' => 'TS-PREVIEW1']);

            return $this->listing('まだ秘密の下書き', ListingStatus::Draft)->id;
        });

        // 同意したお客さんのブラウザでも、プレビューには App のスクリプトも同意の帯も出さず、CSP も広げない
        $this->withCookie(ConsentCookie::NAME, 'analytics=1&ads=1');
        $response = $this->page('lpa', $this->issue('lpa', $listingId))->assertOk()->assertSee('data-test="preview-banner"', false);
        $this->assertStringNotContainsString('/apps/tag-sample/', (string) $response->getContent());
        $this->assertStringNotContainsString('TS-PREVIEW1', (string) $response->getContent());
        $this->assertStringNotContainsString('consent-banner', (string) $response->getContent());
        $this->assertSame(config('storefront.csp'), $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_changed_expired_or_borrowed_link_shows_nothing(): void
    {
        [$listingId, $otherId, $otherImageId] = $this->inside('lpa', function (): array {
            $listing = $this->listing('まだ秘密の下書き', ListingStatus::Draft);
            $other = $this->listing('別の下書き', ListingStatus::Draft);

            return [$listing->id, $other->id, $this->photo($other)->id];
        });
        $url = $this->issue('lpa', $listingId);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        $this->assertIsString($params['token'] ?? null);
        $this->assertIsString($params['expires'] ?? null);
        $token = $params['token'];
        $expires = $params['expires'];

        $refused = function (string $path, string $shop = 'lpa'): void {
            $response = $this->page($shop, $path)->assertNotFound();
            $this->assertStringNotContainsString('秘密の下書き', (string) $response->getContent(), $path);
            $this->assertStringNotContainsString('別の下書き', (string) $response->getContent(), $path);
            $this->assertStringNotContainsString('preview-banner', (string) $response->getContent(), $path);
        };

        // 署名の書き換え・署名なし・期限の書き換え（延ばす）
        $changed = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $refused("/preview/products/{$listingId}?expires={$expires}&token={$changed}");
        $refused("/preview/products/{$listingId}");
        $refused("/preview/products/{$listingId}?expires={$expires}");
        $refused('/preview/products/'.$listingId.'?expires='.((int) $expires + 3600).'&token='.$token);
        $refused("/preview/products/{$listingId}?expires[]=1&token[]=x");

        // ほかの商品へ広げない（その商品の署名で別の商品・別の商品の写真は開けない）
        $refused("/preview/products/{$otherId}?{$query}");
        $refused("/media/listings/{$otherImageId}/medium?{$query}");

        // ほかの店で作った署名（同じ商品の ID でも）は、この店では開けない
        $borrowed = ListingPreviewLink::query('lpb', $listingId, (int) $expires);
        $refused("/preview/products/{$listingId}?expires={$borrowed['expires']}&token={$borrowed['token']}");
        // この店の署名を、ほかの店のホストで使っても開けない（その店に商品が無いことも、署名が合わないことも同じ答え）
        $refused("/preview/products/{$listingId}?{$query}", 'lpb');

        // 署名が合っても、買取の商品（売る側でない）は描かない（ショップに商品の画面が無い）
        $buyId = $this->inside('lpa', static fn (): string => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'offer_side' => OfferSide::Buy,
            'title' => '秘密の下書きの買取', 'unit_amount' => 1000, 'status' => ListingStatus::Draft])->id);
        $refused((string) parse_url($this->issue('lpa', $buyId), PHP_URL_PATH).'?'.(string) parse_url($this->issue('lpa', $buyId), PHP_URL_QUERY));

        // 本物のリンクは開ける。期限（30 分）を過ぎたら開けない
        $this->page('lpa', $url)->assertOk()->assertSee('まだ秘密の下書き');
        $this->travel(ListingPreviewLink::MINUTES + 1)->minutes();
        $refused("/preview/products/{$listingId}?{$query}");
    }

    public function test_a_preparing_shop_opens_only_the_previewed_product(): void
    {
        $listingId = $this->inside('lpa', function (): string {
            app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'cli:test');

            return $this->listing('準備中の新商品', ListingStatus::Draft)->id;
        });
        $url = $this->issue('lpa', $listingId);

        $response = $this->page('lpa', $url)->assertOk()->assertSee('準備中の新商品')->assertSee('data-test="preview-banner"', false);
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

        // ほかの画面は閉じたまま（リンクを持っていても、関所の Cookie は渡さない）
        foreach (['/', '/products', "/products/{$listingId}", '/cart'] as $path) {
            $this->page('lpa', $path)->assertRedirect('http://lpa.localhost/password');
        }
        $this->assertNull($response->getCookie('sugar_shop_pass'));

        // 署名の合わないプレビューは、関所と同じく「準備中です」へ（中身を出さない）
        $closed = $this->page('lpa', "/preview/products/{$listingId}?expires=9999999999&token=".str_repeat('0', 64))
            ->assertRedirect('http://lpa.localhost/password');
        $this->assertStringNotContainsString('準備中の新商品', (string) $closed->getContent());
    }

    private function issue(string $shop, string $listingId): string
    {
        $link = ListingPreviewLink::issue(Tenant::query()->findOrFail($shop), $listingId);
        $this->assertNotNull($link);

        return $link['url'];
    }

    /**
     * @return TestResponse<Response>
     */
    private function page(string $shop, string $pathOrUrl): TestResponse
    {
        $response = $this->get(str_starts_with($pathOrUrl, 'http') ? $pathOrUrl : "http://{$shop}.localhost{$pathOrUrl}");
        tenancy()->end();

        return $response;
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function photoUrlIn(TestResponse $response, string $imageId): string
    {
        $this->assertSame(1, preg_match('#src="(/media/listings/'.$imageId.'/large\?[^"]+)"#', (string) $response->getContent(), $match));

        return html_entity_decode($match[1]);
    }

    private function listing(string $title, ListingStatus $status): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => $status]);
    }

    private function photo(Listing $listing): ListingImage
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lpv');
        file_put_contents($path, $this->jpeg(1200, 900));
        $this->files[] = $path;

        return app(ListingImageService::class)->add($listing, $path, null);
    }
}
