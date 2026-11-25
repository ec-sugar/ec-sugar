<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Inventory\Enums\LocationKind;
use App\Core\Inventory\Enums\UnitStatus;
use App\Core\Inventory\Models\InventoryUnit;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InsufficientStockException;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\OfferSide;
use App\Core\Listing\Enums\Pricing;
use App\Core\Listing\ListingKindDefinition;
use App\Core\Listing\ListingKinds;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use InvalidArgumentException;
use Tests\TestCase;

final class ListingAndInventoryTest extends TestCase
{
    use DatabaseTruncation;

    public function test_tenant_gets_a_default_seller_and_store_and_can_manage_stock(): void
    {
        $tenant = Tenant::create(['id' => 'lst', 'name' => 'リスト商店']);

        try {
            tenancy()->initialize($tenant);

            // テナント作成時のシードで既定の出品者と拠点ができている
            $seller = Seller::query()->where('slug', 'default')->firstOrFail();
            $this->assertSame('リスト商店', $seller->name);
            $store = Location::query()->where('seller_id', $seller->id)->firstOrFail();
            $this->assertSame(LocationKind::Store, $store->kind);

            // product の既定（売る側・固定価格）が埋まる
            $listing = Listing::create([
                'seller_id' => $seller->id,
                'kind' => 'product',
                'title' => '中古レコード 盤面 A',
                'unit_amount' => 3500,
                'sku' => 'REC-0001',
            ]);
            $this->assertSame(OfferSide::Sell, $listing->offer_side);
            $this->assertSame(Pricing::Fixed, $listing->pricing);
            $this->assertSame(ListingStatus::Draft, $listing->status);
            $this->assertSame('JPY', $listing->currency);
            $this->assertSame(3500, $listing->unit_amount);

            // 数量在庫: 入庫 → 確保 → 出庫、足りなければ例外
            $stock = app(StockService::class);
            $stock->receive($listing, $store, 5);
            $stock->reserve($listing, $store, 2);
            $this->assertSame(5, $listing->stocks()->firstOrFail()->quantity);
            $this->assertSame(3, $listing->stocks()->firstOrFail()->available());

            try {
                $stock->reserve($listing, $store, 4);
                $this->fail('利用可能数を超えて確保できてしまった');
            } catch (InsufficientStockException) {
                $this->addToAssertionCount(1);
            }

            $stock->commit($listing, $store, 2);
            $this->assertSame(3, $listing->stocks()->firstOrFail()->quantity);
            $this->assertSame(0, $listing->stocks()->firstOrFail()->reserved);

            // 個体在庫: 受け入れ直後は出品物に紐づかなくてよい
            $unit = InventoryUnit::create(['location_id' => $store->id, 'identifier' => 'SN-001']);
            $this->assertSame(UnitStatus::Received, $unit->status);
            $this->assertNull($unit->listing_id);
            $unit->update(['listing_id' => $listing->id, 'status' => UnitStatus::Available, 'condition' => 'A']);
            $this->assertSame(1, $listing->units()->count());
        } finally {
            tenancy()->end();
            $tenant->delete();
        }
    }

    public function test_unknown_listing_kind_is_rejected_and_plugins_can_register_kinds(): void
    {
        $kinds = app(ListingKinds::class);

        $this->assertTrue($kinds->has('product'));
        $this->assertFalse($kinds->has('buyback_offer'));

        $this->expectException(InvalidArgumentException::class);
        $kinds->get('buyback_offer');
    }

    public function test_registering_a_kind_twice_is_an_error(): void
    {
        $kinds = app(ListingKinds::class);
        $kinds->register(new ListingKindDefinition('test_kind', OfferSide::Buy, Pricing::Quoted, 'テスト'));
        $this->assertSame(OfferSide::Buy, $kinds->get('test_kind')->offerSide);

        $this->expectException(InvalidArgumentException::class);
        $kinds->register(new ListingKindDefinition('test_kind', OfferSide::Sell, Pricing::Fixed, '重複'));
    }

    public function test_fixed_price_listing_requires_amount(): void
    {
        $tenant = Tenant::create(['id' => 'lst2', 'name' => 'L2']);

        try {
            tenancy()->initialize($tenant);
            $seller = Seller::query()->firstOrFail();

            $this->expectException(InvalidArgumentException::class);
            Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '値段なし']);
        } finally {
            tenancy()->end();
            $tenant->delete();
        }
    }
}
