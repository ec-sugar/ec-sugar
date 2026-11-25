<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Media\Models\ShopMedia;
use App\Core\Section\Services\ShopPageService;
use App\Core\Shop\Models\Redirect;
use App\Core\Shop\Models\ShopSeo;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の「検索と共有」（ADR 0061）: 設定 › SEO（トップの題と説明・共有の写真・転送）と、商品・カテゴリ・ページの題と説明。
 * 入力の誤りは 422、閲覧の段階は変えられない、ほかの店の写真は選べない
 */
final class SeoAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_seo_settings_and_redirects_through_the_api(): void
    {
        $this->tenant('sga', '雑貨の店');
        $this->tenant('sgb');
        $mine = $this->inside('sga', fn (): string => $this->media()->id);
        $theirs = $this->inside('sgb', fn (): string => $this->media()->id);
        $this->enrolledOperator();

        $this->send('GET', '/api/tenants/sga/seo')->assertOk()
            ->assertJsonPath('settings', ['home_title' => null, 'home_description' => null, 'share_image_id' => null])
            ->assertJsonPath('shop_name', '雑貨の店')
            ->assertJsonPath('limits.title_guide', 30)
            ->assertJsonPath('redirects', []);

        $this->send('PUT', '/api/tenants/sga/seo', ['home_title' => '  暮らしの道具  ', 'home_description' => '', 'share_image_id' => $mine])->assertOk()
            ->assertJsonPath('settings', ['home_title' => '暮らしの道具', 'home_description' => null, 'share_image_id' => $mine]);

        // 入力の誤り: 改行・長すぎ・ほかの店の写真・欄が無い
        $this->send('PUT', '/api/tenants/sga/seo', ['home_title' => "1 行目\n2 行目", 'home_description' => null, 'share_image_id' => null])->assertStatus(422)->assertJsonValidationErrors('home_title');
        $this->send('PUT', '/api/tenants/sga/seo', ['home_title' => null, 'home_description' => str_repeat('あ', 201), 'share_image_id' => null])->assertStatus(422)->assertJsonValidationErrors('home_description');
        $this->send('PUT', '/api/tenants/sga/seo', ['home_title' => null, 'home_description' => null, 'share_image_id' => $theirs])->assertStatus(422)->assertJsonValidationErrors('share_image_id');
        $this->send('PUT', '/api/tenants/sga/seo', ['home_title' => null])->assertStatus(422);
        $this->inside('sga', fn () => $this->assertSame($mine, ShopSeo::query()->value('share_image_id')));

        // 共有の写真に使っている写真は消せない
        $this->send('DELETE', "/api/tenants/sga/shop-media/{$mine}")->assertStatus(422)->assertJsonValidationErrors('media')
            ->assertJsonFragment(['media' => ['この写真は設定の「SEO」の共有の写真で使われているため削除できません。先にそちらで別の写真に変更するか、写真を外してください。']]);

        // 転送: 足す・外への行き先は断る・消す
        $created = $this->send('POST', '/api/tenants/sga/seo/redirects', ['from' => '/item/1', 'to' => '/products'])->assertCreated()
            ->assertJsonPath('redirects.0.from', '/item/1')->assertJsonPath('redirects.0.to', '/products')->assertJsonPath('redirects.0.source', 'manual');
        $this->send('POST', '/api/tenants/sga/seo/redirects', ['from' => '/item/2', 'to' => '//evil.example'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->send('POST', '/api/tenants/sga/seo/redirects', ['from' => '/item/1', 'to' => '/cart'])->assertStatus(422)->assertJsonValidationErrors('from');
        $id = (string) $created->json('redirects.0.id');
        // ほかの店の ID では消せない
        $this->send('DELETE', "/api/tenants/sgb/seo/redirects/{$id}")->assertStatus(422);
        $this->send('DELETE', "/api/tenants/sga/seo/redirects/{$id}")->assertOk()->assertJsonPath('redirects', []);

        $this->inside('sga', function (): void {
            $this->assertSame(['shop.seo_updated', 'shop.redirect_added', 'shop.redirect_removed'], DomainEvent::query()->whereIn('type', ['shop.seo_updated', 'shop.redirect_added', 'shop.redirect_removed'])->orderBy('sequence')->pluck('type')->all());
            $this->assertStringStartsWith('operator:', (string) DomainEvent::query()->where('type', 'shop.seo_updated')->value('actor'));
        });
    }

    public function test_only_operate_level_changes_seo(): void
    {
        $this->tenant('sgc');
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'sgc', 'read', 'test');

        $this->send('GET', '/api/tenants/sgc/seo')->assertOk();
        $this->send('PUT', '/api/tenants/sgc/seo', ['home_title' => 'x', 'home_description' => null, 'share_image_id' => null])->assertForbidden();
        $this->send('POST', '/api/tenants/sgc/seo/redirects', ['from' => '/a', 'to' => '/b'])->assertForbidden();
        $this->inside('sgc', fn () => $this->assertSame(0, Redirect::query()->count()));
    }

    public function test_products_categories_and_pages_take_a_search_title_and_description(): void
    {
        $this->tenant('sgd');
        [$listingId, $categoryId, $pageId] = $this->inside('sgd', fn (): array => [
            Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 9900, 'status' => ListingStatus::Active])->id,
            app(CategoryService::class)->create(['name' => 'トップス', 'slug' => 'tops'])->id,
            app(ShopPageService::class)->create(['title' => '私たち', 'slug' => 'about'])->id,
        ]);
        $this->enrolledOperator();

        $this->send('PATCH', "/api/tenants/sgd/listings/{$listingId}", ['version' => 1, 'seo_title' => '冬のニット', 'seo_description' => '暖かい'])->assertOk()
            ->assertJsonPath('listing.seo_title', '冬のニット')->assertJsonPath('listing.seo_description', '暖かい');
        $this->send('PATCH', "/api/tenants/sgd/listings/{$listingId}", ['version' => 2, 'seo_title' => "改行\nあり"])->assertStatus(422)->assertJsonValidationErrors('seo_title');
        $this->send('PATCH', "/api/tenants/sgd/listings/{$listingId}", ['version' => 2, 'seo_description' => str_repeat('あ', 201)])->assertStatus(422)->assertJsonValidationErrors('seo_description');
        // 空にすると自動へ戻る
        $this->send('PATCH', "/api/tenants/sgd/listings/{$listingId}", ['version' => 2, 'seo_title' => ''])->assertOk()->assertJsonPath('listing.seo_title', null);

        $this->send('PATCH', "/api/tenants/sgd/categories/{$categoryId}", ['seo_title' => 'トップスの一覧', 'seo_description' => "\u{202E}逆向き"])->assertStatus(422)->assertJsonValidationErrors('seo_description');
        $this->send('PATCH', "/api/tenants/sgd/categories/{$categoryId}", ['seo_title' => 'トップスの一覧'])->assertOk()->assertJsonPath('categories.0.seo_title', 'トップスの一覧');

        $this->send('PATCH', "/api/tenants/sgd/shop-pages/{$pageId}", ['seo_description' => '店の話'])->assertOk()->assertJsonPath('pages.0.seo_description', '店の話');
        $this->send('PATCH', "/api/tenants/sgd/shop-pages/{$pageId}", ['seo_title' => str_repeat('a', 71)])->assertStatus(422)->assertJsonValidationErrors('seo_title');
        // slug を変えると、古い URL からの転送が自動で入る
        $this->send('PATCH', "/api/tenants/sgd/shop-pages/{$pageId}", ['slug' => 'about-us'])->assertOk();
        $this->send('GET', '/api/tenants/sgd/seo')->assertOk()->assertJsonPath('redirects.0.from', '/pages/about')->assertJsonPath('redirects.0.to', '/pages/about-us')->assertJsonPath('redirects.0.source', 'auto');
    }

    private function media(): ShopMedia
    {
        return ShopMedia::create(['alt' => null, 'width' => 1200, 'height' => 630, 'checksum' => str_repeat('b', 64), 'bytes' => 100]);
    }
}
