<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 既定のテーマ（角砂糖。ADR 0028 で区画の形に）。トップは商品より先にどんな店かを伝え、区画が空でも整って見える。
 */
final class DefaultThemeTest extends TestCase
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

    public function test_the_top_tells_what_the_shop_is_before_its_products_even_with_empty_settings(): void
    {
        $this->tenant('dta', 'さとう菓子店');
        $this->inside('dta', function (): void {
            for ($i = 1; $i <= 10; $i++) {
                $this->travel(1)->minutes();
                $this->listing("焼き菓子 {$i}", ListingStatus::Active);
            }
            $this->listing('下書きのタルト', ListingStatus::Draft);
        });
        $this->travelBack();

        $page = $this->get('http://dta.localhost/')->assertOk();
        tenancy()->end();
        $html = (string) $page->getContent();

        // 写真の帯 → ショップの説明 → 新着の順
        $page->assertSeeInOrder(['data-test="hero"', 'data-test="hero-heading"', 'data-test="hero-body"', 'data-test="product-card"', 'data-test="see-all"'], false);
        // 設定が空: 写真の代わりに角砂糖の積み木、見出しは店の名前、文は既定の一言。お知らせ・カテゴリ・約束の区画は出さない
        $hero = $this->section($html, 'data-test="hero"', '</section>');
        $this->assertStringNotContainsString('<img', $hero);
        $this->assertStringContainsString('viewBox="0 0 400 300"', $hero);
        $this->assertStringContainsString('>さとう菓子店</h1>', $html);
        $page->assertSee('オンラインストアの商品です。価格はすべて税込みです。');
        foreach (['announcement', 'categories', 'promises', 'intro-photos', 'intro-eyebrow'] as $absent) {
            $page->assertDontSee('data-test="'.$absent.'"', false);
        }
        // 何も選んでいなければ Powered by を出す
        $page->assertSee('data-test="powered-by"', false);

        // 新着は 8 点まで、新しい順。下書きは出さない。続きは一覧へ
        $this->assertSame(8, substr_count($html, 'data-test="product-card"'));
        $page->assertSeeInOrder(['焼き菓子 10', '焼き菓子 9', '焼き菓子 3']);
        $page->assertDontSee('>焼き菓子 2</span>', false)->assertDontSee('>焼き菓子 1</span>', false)->assertDontSee('下書きのタルト');
        $this->assertMatchesRegularExpression('#<a href="http://dta\.localhost/products"[^>]*data-test="see-all"#', $html);

        // ヘッダの検索は一覧へ q を GET で送る。カートが空なら数の印は隠す
        $this->assertMatchesRegularExpression('#<form action="http://dta\.localhost/products" method="get" role="search"#', $html);
        $this->assertStringContainsString('name="q" maxlength="100"', $html);
        $this->assertMatchesRegularExpression('#class="[^"]*\bhidden\b[^"]*"[^>]*data-test="cart-count"#', $html);
    }

    public function test_sections_are_shown_as_text_and_the_hero_photo_is_not_delayed(): void
    {
        $this->tenant('dtb');
        $this->inside('dtb', function (): void {
            $this->listing('マドレーヌ', ListingStatus::Active);
            $media = app(ShopMediaService::class);
            $this->publish([
                'announcement' => ['values' => ['text' => '<script>alert(1)</script>送料無料']],
                'hero' => ['values' => [
                    'image' => $media->add($this->image(1600, 900), null, 'test')->id,
                    'eyebrow' => '<em>SMALL</em> SHOP',
                    'heading' => "<b>ひとつずつ</b>、\nていねいに。",
                    'body' => '<img src=x onerror=alert(1)>焼き菓子の店です。',
                    'photo_a' => $media->add($this->image(800, 600), '焼き上がり" onload="alert(1)', 'test')->id,
                    'photo_b' => $media->add($this->image(600, 600), null, 'test')->id,
                ]],
                'promises' => ['items' => [
                    ['icon' => 'shipping', 'title' => '<i>送料</i>'],
                    ['icon' => 'secure', 'title' => 'カード情報は店に残りません', 'body' => '決済代行の画面で'],
                    ['body' => '見出しの無いお約束は出さない'],
                ]],
                'footer' => ['values' => ['powered' => 'hide']],
            ]);
        });

        $page = $this->get('http://dtb.localhost/')->assertOk();
        tenancy()->end();
        $html = (string) $page->getContent();

        // 区画の文は、HTML を文字として出す
        $page->assertSee('<script>alert(1)</script>送料無料')->assertSee('<b>ひとつずつ</b>、')->assertSee('<i>送料</i>')->assertSee('<em>SMALL</em> SHOP');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<b>ひとつずつ', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('" onload="alert(1)', $html);

        // ヒーローの写真は最初に見えるので、遅らせずに先に読む。飾りなので代わりの文字は空
        $hero = $this->section($html, 'data-test="hero"', '</section>');
        $this->assertMatchesRegularExpression('#<img src="/media/shop/[0-9a-z]{26}/large" srcset="[^"]+"#', $hero);
        $this->assertStringContainsString('fetchpriority="high"', $hero);
        $this->assertStringContainsString('alt=""', $hero);
        $this->assertStringNotContainsString('loading="lazy"', $hero);
        $this->assertStringNotContainsString('viewBox="0 0 400 300"', $hero);

        // 説明の写真は 2 枚重ねて、店の人の説明を代わりの文字に
        $photos = $this->section($html, 'data-test="intro-photos"', '</div>');
        $this->assertSame(2, substr_count($photos, '<img '));
        $this->assertStringContainsString('alt="焼き上がり&quot; onload=&quot;alert(1)"', $photos);

        // 約束は見出しのあるものだけ、文が無い行は見出しだけ
        $promises = $this->section($html, 'data-test="promises"', '</ul>');
        $this->assertSame(2, substr_count($promises, '<li'));
        $this->assertStringContainsString('決済代行の画面で', $promises);
        $this->assertStringNotContainsString('見出しの無いお約束', $html);

        // Powered by は「出さない」を選んだので出ない（何も選んでいなければ出る。上のテスト）
        $page->assertDontSee('data-test="powered-by"', false);
    }

    public function test_an_empty_shop_says_so_and_does_not_link_to_an_empty_list(): void
    {
        $this->tenant('dtc');

        $page = $this->get('http://dtc.localhost/')->assertOk();

        $page->assertSee('まだ商品がありません。');
        $page->assertDontSee('data-test="product-card"', false)->assertDontSee('data-test="see-all"', false);
    }

    /**
     * 横から出るカートは核の部品（resources/storefront/cart.js）が埋める。読む印が 1 つでも欠けると、ページを移る形に戻ってしまう
     */
    public function test_the_cart_drawer_carries_every_marker_the_core_script_reads(): void
    {
        $this->tenant('dtd');

        $html = (string) $this->get('http://dtd.localhost/')->assertOk()->getContent();
        $drawer = $this->section($html, '<dialog data-cart-drawer', '</dialog>');

        foreach ([
            'data-cart-drawer-lines', 'data-cart-line-template', 'data-cart-drawer-message', 'data-cart-drawer-subtotal',
            'data-cart-drawer-note', 'data-cart-drawer-close', 'data-field="title"', 'data-field="link"', 'data-field="photo"',
            'data-field="quantity"', 'data-field="total"', 'data-field="notice"',
        ] as $marker) {
            $this->assertStringContainsString($marker, $drawer, $marker);
        }
        // 知らせは読み上げる。カートの数とリンクの印はヘッダに
        $this->assertMatchesRegularExpression('#role="status" data-cart-drawer-message#', $drawer);
        $this->assertStringContainsString('data-cart-link', $html);
        $this->assertStringContainsString('data-cart-count', $html);
    }

    private function section(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, $start);
        $rest = substr($html, $from);

        return substr($rest, 0, (int) strpos($rest, $end));
    }

    /**
     * 既定のテーマの区画の値を下書きに入れて公開する（テナント文脈の中で呼ぶ）
     *
     * @param  array<string, mixed>  $sections
     */
    private function publish(array $sections): void
    {
        $service = app(ThemeContentService::class);
        $schema = app(ThemeRegistry::class)->get('default')->schema();
        $version = $service->saveDraft('default', $schema, null, 0, $sections, 'test');
        $service->publish('default', $schema, $version->id, $version->revision, 'test');
    }

    private function listing(string $title, ListingStatus $status): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 1200, 'status' => $status]);
    }

    private function image(int $width, int $height): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dth');
        file_put_contents($path, $this->jpeg($width, $height));
        $this->files[] = $path;

        return $path;
    }
}
