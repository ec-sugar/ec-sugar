<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\OfferSide;
use App\Core\Listing\Enums\Pricing;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 商品の画面の「プレビュー」と「ショップで見る」。プレビューのリンクは閲覧の段階から作れ、与えていない店では作れない。
 * 「ショップで見る」の URL は、ショップに出ている商品だけに付く
 */
final class ListingPreviewAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('lva');
        $this->tenant('lvb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_staff_who_can_read_get_a_preview_link_and_others_are_refused(): void
    {
        $draftId = $this->inside('lva', fn (): string => $this->listing('下書きの新商品', ListingStatus::Draft)->id);
        $otherShopId = $this->inside('lvb', fn (): string => $this->listing('別の店の商品', ListingStatus::Draft)->id);

        // ログインしていなければ断る
        $this->postJson("http://admin.localhost/api/tenants/lva/listings/{$draftId}/preview")->assertUnauthorized();

        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');

        // 与えていない店では作れない（あるかどうかも見せない）
        $this->send('POST', "/api/tenants/lva/listings/{$draftId}/preview")->assertNotFound()->assertJsonMissingPath('url');

        // 閲覧の段階から作れる（30 分のリンク。その店のショップのホスト）
        app(OperatorAccess::class)->grant($staff, 'lva', 'read', 'test');
        $response = $this->send('POST', "/api/tenants/lva/listings/{$draftId}/preview")->assertOk();
        $url = (string) $response->json('url');
        $this->assertStringStartsWith(StorefrontUrls::base('lva.localhost')."/preview/products/{$draftId}?expires=", $url);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), strtotime((string) $response->json('expires_at')), 5);
        // どの商品のリンクを作ったかを監査ログに
        $issued = AuditEvent::query()->where('type', 'operator.listing_preview_issued')->where('operator_id', $staff->id)->latest('sequence')->firstOrFail();
        $this->assertSame([$draftId, 'lva'], [$issued->data['listing_id'], $issued->tenant_id]);

        // ほかの店の商品の ID・無い商品では作れない
        $this->send('POST', "/api/tenants/lva/listings/{$otherShopId}/preview")->assertNotFound();
        $this->send('POST', "/api/tenants/lvb/listings/{$otherShopId}/preview")->assertNotFound();

        // 作ったリンクで、ショップに下書きの商品の画面が開く
        $page = $this->get($url)->assertOk()->assertSee('下書きの新商品')->assertSee('data-test="preview-banner"', false);
        tenancy()->end();
        $this->assertSame('noindex, nofollow', $page->headers->get('X-Robots-Tag'));
    }

    public function test_the_shop_url_is_given_only_for_products_in_the_shop(): void
    {
        [$activeId, $draftId] = $this->inside('lva', fn (): array => [
            $this->listing('販売中の商品', ListingStatus::Active)->id,
            $this->listing('下書きの商品', ListingStatus::Draft)->id,
        ]);
        $this->enrolledOperator();

        $this->send('GET', "/api/tenants/lva/listings/{$activeId}")->assertOk()
            ->assertJsonPath('listing.shop_url', StorefrontUrls::base('lva.localhost')."/products/{$activeId}");
        $this->send('GET', "/api/tenants/lva/listings/{$draftId}")->assertOk()
            ->assertJsonPath('listing.shop_url', null)->assertJsonPath('listing.previewable', true);

        // 「ショップで見る」の URL は、ショップの商品の画面そのもの
        $this->get(StorefrontUrls::base('lva.localhost')."/products/{$activeId}")->assertOk()->assertSee('販売中の商品');
        tenancy()->end();
    }

    public function test_only_products_for_sale_with_a_fixed_price_can_be_previewed(): void
    {
        [$buyId, $quotedId] = $this->inside('lva', fn (): array => [
            Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'offer_side' => OfferSide::Buy, 'title' => '買取の受付', 'unit_amount' => 1000, 'status' => ListingStatus::Draft])->id,
            Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'pricing' => Pricing::Quoted, 'title' => '見積もりの品', 'status' => ListingStatus::Draft])->id,
        ]);
        $this->enrolledOperator();

        foreach ([$buyId, $quotedId] as $id) {
            $this->send('GET', "/api/tenants/lva/listings/{$id}")->assertOk()->assertJsonPath('listing.previewable', false);
            $this->send('POST', "/api/tenants/lva/listings/{$id}/preview")->assertUnprocessable()->assertJsonMissingPath('url');
        }
    }

    private function listing(string $title, ListingStatus $status): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 3300, 'status' => $status]);
    }
}
