<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Enums\LocationKind;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * お届け先の入力を待つ受渡（贈り物の受け取り待ち。ADR 0055 の 1）。お届け先が入るまで発送・完了にせず、
 * お届け先は 1 回だけ、発送の前に、暗号化して入れる。イベントに個人情報を入れない
 */
final class GiftFulfillmentTest extends TestCase
{
    use DatabaseTruncation;

    public function test_a_shipment_without_an_address_waits_until_the_recipient_fills_it_in(): void
    {
        $this->inTenant('gft', function (): void {
            [, $shipment] = $this->giftOrder();
            $service = app(FulfillmentService::class);
            $this->assertTrue($shipment->awaitingAddress());

            // お届け先が無いうちは発送も完了もできない
            foreach ([static fn () => $service->ship($shipment, 'yamato', '1234'), static fn () => $service->complete($shipment)] as $move) {
                try {
                    $move();
                    $this->fail('お届け先の無い配送が進んだ');
                } catch (TransactionException $e) {
                    $this->assertStringContainsString('お届け先が決まっていない', $e->getMessage());
                }
            }
            $this->assertSame(FulfillmentStatus::Pending, $shipment->refresh()->status);

            // 足りないお届け先・知らない項目は断る
            foreach ([['name' => '鈴木 一郎', 'zip' => '060-0000'], ['name' => 'a', 'zip' => '1', 'prefecture' => 'b', 'city' => 'c', 'line1' => 'd', 'email' => 'x@example.com']] as $bad) {
                try {
                    $service->setAddress($shipment, $bad, 'gift');
                    $this->fail('足りない・知らない項目のお届け先が入った');
                } catch (TransactionException) {
                    $this->addToAssertionCount(1);
                }
            }

            $set = $service->setAddress($shipment, ['name' => '鈴木 一郎', 'zip' => '060-0000', 'prefecture' => '北海道', 'city' => '札幌市中央区', 'line1' => '北一条 1', 'line2' => '', 'phone' => '011-000-0000'], 'gift');
            $this->assertFalse($set->awaitingAddress());
            $this->assertSame('鈴木 一郎', $set->refresh()->address['name'] ?? null);
            $this->assertStringNotContainsString('鈴木', (string) DB::table('fulfillments')->where('id', $shipment->id)->value('address'));
            $event = DomainEvent::query()->where('type', 'fulfillment.address_set')->sole();
            $this->assertSame(['fulfillment_id' => $shipment->id], $event->payload);

            // 2 回目は断る（受け取りのリンクを知った別の人が書き換えない）
            try {
                $service->setAddress($shipment, ['name' => '別の人', 'zip' => '1', 'prefecture' => 'x', 'city' => 'y', 'line1' => 'z'], 'gift');
                $this->fail('お届け先を書き換えられた');
            } catch (TransactionException) {
                $this->assertSame('鈴木 一郎', $shipment->refresh()->address['name'] ?? null);
            }

            // お届け先が入れば、いつもどおり発送できる
            $this->assertSame(FulfillmentStatus::Shipped, $service->ship($shipment, 'yamato', '1234-5678')->status);
        });
    }

    public function test_a_cancelled_gift_cannot_receive_an_address(): void
    {
        $this->inTenant('gfc', function (): void {
            [$sale, $shipment] = $this->giftOrder();
            app(FulfillmentService::class)->cancel($shipment, 'test');
            app(TransactionService::class)->transition($sale, Phase::Cancel, 'test');

            try {
                app(FulfillmentService::class)->setAddress($shipment, ['name' => '鈴木 一郎', 'zip' => '060-0000', 'prefecture' => '北海道', 'city' => '札幌市', 'line1' => '1'], 'gift');
                $this->fail('取り消した贈り物にお届け先が入った');
            } catch (TransactionException) {
                $this->assertNull(Fulfillment::query()->findOrFail($shipment->id)->address);
            }
        });
    }

    /**
     * @return array{0: Transaction, 1: Fulfillment}
     */
    private function giftOrder(): array
    {
        $seller = Seller::query()->where('slug', 'default')->firstOrFail();
        $store = Location::query()->where('seller_id', $seller->id)->where('kind', LocationKind::Store)->firstOrFail();
        $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '焼き菓子の詰め合わせ', 'unit_amount' => 3000, 'status' => ListingStatus::Active]);
        app(StockService::class)->receive($listing, $store, 3);
        $sale = app(TransactionService::class)->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, $store)], actor: 'test');
        $line = $sale->lines()->where('line_type', LineType::Item)->firstOrFail();

        return [$sale, app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => 1], $store, null, 'test')];
    }

    private function inTenant(string $id, Closure $test): void
    {
        $tenant = Tenant::create(['id' => $id, 'name' => "テスト {$id}"]);

        try {
            tenancy()->initialize($tenant);
            $test();
        } finally {
            tenancy()->end();
            $tenant->delete();
        }
    }
}
