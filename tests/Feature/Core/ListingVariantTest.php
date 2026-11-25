<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InsufficientStockException;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingOption;
use App\Core\Listing\Models\ListingOptionValue;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\StaleListingException;
use App\Core\Listing\Services\VariantService;
use App\Core\Transaction\LineDraft;
use App\Models\Tenant;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 商品の種類（ADR 0036）。種類が商品に属すること・値がその軸のものであることを DB が守り、在庫は種類ごと、取引の行に種類の名前と品番が残る。
 */
final class ListingVariantTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<Tenant> */
    private array $tenants = [];

    protected function tearDown(): void
    {
        foreach ($this->tenants as $tenant) {
            $tenant->delete();
        }

        parent::tearDown();
    }

    public function test_every_listing_gets_one_plain_variant_that_carries_the_sku(): void
    {
        $this->inside('lva', function (Seller $seller): void {
            $listing = app(ListingService::class)->create($seller, 'product', ['title' => 'マグカップ', 'unit_amount' => 1800, 'sku' => 'MUG-1']);

            $this->assertSame(1, $listing->variants()->count());
            $variant = $listing->soleVariant();
            $this->assertNotNull($variant);
            $this->assertSame('MUG-1', $variant->sku);
            $this->assertSame('MUG-1', $listing->sku);
            $this->assertSame($seller->id, DB::table('listing_variants')->where('id', $variant->id)->value('seller_id'));
            $this->assertSame(1800, $variant->price($listing));
            $this->assertSame('', $variant->label());

            // 品番の変更は種類へ（記録にも残る）
            $updated = app(ListingService::class)->update($listing, $listing->version, ['sku' => 'MUG-2']);
            $this->assertSame('MUG-2', $updated->sku);
            $this->assertSame(2, $updated->version);

            // 同じ出品者の中で品番は重ならない（DB が断る）。別の出品者なら同じ品番でよい
            $this->expectsUnique(fn () => app(ListingService::class)->create($seller, 'product', ['title' => '別のマグ', 'unit_amount' => 1, 'sku' => 'MUG-2']));
            $other = Seller::create(['slug' => 'other', 'name' => '別の販売者']);
            $this->assertSame('MUG-2', app(ListingService::class)->create($other, 'product', ['title' => '別のマグ', 'unit_amount' => 1, 'sku' => 'MUG-2'])->sku);
        });
    }

    public function test_variant_values_must_belong_to_that_listings_axis_and_combinations_are_unique(): void
    {
        $this->inside('lvb', function (Seller $seller): void {
            [$apron, $red, $blue, $m] = $this->apron($seller);
            $mug = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'マグ', 'unit_amount' => 1000]);

            // 赤 / M は作れる
            $redM = ListingVariant::create(['listing_id' => $apron->id, 'value_1' => $red->id, 'value_2' => $m->id, 'sku' => 'APR-R-M']);
            $this->assertSame('赤 / M', $redM->label());

            // 同じ組み合わせは 2 つ作れない
            $this->expectsCheck(fn () => ListingVariant::create(['listing_id' => $apron->id, 'value_1' => $red->id, 'value_2' => $m->id]), unique: true);
            // 軸の番が違う値（サイズの欄に色）は断る
            $this->expectsCheck(fn () => ListingVariant::create(['listing_id' => $apron->id, 'value_1' => $m->id]));
            // 別の商品の値は断る
            $this->expectsCheck(fn () => ListingVariant::create(['listing_id' => $mug->id, 'value_1' => $blue->id]));
            // 2 番目の軸だけの値は断る（軸は順に埋める）
            $this->expectsCheck(fn () => ListingVariant::create(['listing_id' => $apron->id, 'value_2' => $m->id]));
            // 出品者は商品から写す（渡した値は使わない）
            $this->assertSame($seller->id, DB::table('listing_variants')->where('id', $redM->id)->value('seller_id'));
        });
    }

    public function test_stock_is_kept_per_variant_and_cannot_point_at_another_listings_variant(): void
    {
        $this->inside('lvc', function (Seller $seller): void {
            [$apron, $red, $blue, $m] = $this->apron($seller);
            $store = Location::query()->where('seller_id', $seller->id)->firstOrFail();
            $redM = ListingVariant::create(['listing_id' => $apron->id, 'value_1' => $red->id, 'value_2' => $m->id]);
            $blueM = ListingVariant::create(['listing_id' => $apron->id, 'value_1' => $blue->id, 'value_2' => $m->id]);
            $stock = app(StockService::class);

            $stock->receive($redM, $store, 2);
            $stock->receive($blueM, $store, 5);
            $stock->reserve($redM, $store, 2);
            $this->assertSame(0, $redM->stocks()->firstOrFail()->available());
            $this->assertSame(5, $blueM->stocks()->firstOrFail()->available());

            try {
                $stock->reserve($redM, $store, 1);
                $this->fail('売り切れの種類を確保できてしまった');
            } catch (InsufficientStockException) {
                $this->addToAssertionCount(1);
            }

            // 種類のある商品を、種類を選ばずに在庫の操作に渡すと断る
            try {
                $stock->receive($apron, $store, 1);
                $this->fail('種類を選ばずに在庫を動かせてしまった');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }

            // 別の商品の種類を指す在庫の行は DB が断る
            $mug = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'マグ', 'unit_amount' => 1000]);
            $this->expectsCheck(fn () => InventoryStock::create(['listing_id' => $mug->id, 'variant_id' => $redM->id, 'location_id' => $store->id, 'quantity' => 1, 'reserved' => 0]));
        });
    }

    public function test_order_lines_copy_the_variant_name_price_and_sku(): void
    {
        $this->inside('lvd', function (Seller $seller): void {
            [$apron, $red, , $m] = $this->apron($seller);
            $store = Location::query()->where('seller_id', $seller->id)->firstOrFail();
            $redM = ListingVariant::create(['listing_id' => $apron->id, 'value_1' => $red->id, 'value_2' => $m->id, 'sku' => 'APR-R-M', 'unit_amount' => 5200]);

            $line = LineDraft::fromVariant($apron, $redM, 2, 1000, $store);
            $this->assertSame('リネンのエプロン（赤 / M）', $line->description);
            $this->assertSame(5200, $line->unitAmount);
            $this->assertSame('APR-R-M', $line->sku);
            $this->assertSame($redM->id, $line->variantId);
            $this->assertSame($line->toArray(), LineDraft::fromArray($line->toArray())->toArray());

            // 種類のある商品は、種類を選ばずに明細にできない。止めた種類は取引に入らない（TransactionService が断る）
            try {
                LineDraft::fromListing($apron, 1, 1000, $store);
                $this->fail('種類を選ばずに明細にできてしまった');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }

            // 価格が空の種類は商品の価格
            $redM->update(['unit_amount' => null, 'status' => VariantStatus::Stopped]);
            $this->assertSame(4800, LineDraft::fromVariant($apron, $redM->refresh(), 1, 1000)->unitAmount);
        });
    }

    public function test_the_sku_of_a_listing_with_variants_is_set_per_variant(): void
    {
        $this->inside('lve', function (Seller $seller): void {
            [$apron, $red] = $this->apron($seller);
            ListingVariant::create(['listing_id' => $apron->id, 'value_1' => $red->id]);
            $apron->refresh();

            $this->assertNull($apron->soleVariant());
            $this->assertNull($apron->sku);

            try {
                app(ListingService::class)->update($apron, $apron->version, ['sku' => 'X']);
                $this->fail('種類のある商品に商品の品番を入れられてしまった');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('sku', $e->errors());
            }
        });
    }

    public function test_defining_options_makes_the_combinations_and_keeps_the_variants_that_stay(): void
    {
        $this->inside('lvf', function (Seller $seller): void {
            $service = app(VariantService::class);
            $store = Location::query()->where('seller_id', $seller->id)->firstOrFail();
            $apron = app(ListingService::class)->create($seller, 'product', ['title' => 'エプロン', 'unit_amount' => 4800, 'sku' => 'APR']);

            $apron = $service->define($apron, 1, [['name' => '色', 'values' => ['赤', '青']], ['name' => 'サイズ', 'values' => ['M', 'L']]]);
            $this->assertSame(2, $apron->version);
            $labels = $apron->variants()->get()->map(static fn (ListingVariant $v): string => $v->label())->all();
            $this->assertSame(['赤 / M', '赤 / L', '青 / M', '青 / L'], $labels);
            $this->assertNull($apron->soleVariant());

            // 赤 / M に品番と在庫を入れ、軸を作り直しても残る。無くなった組み合わせ（L）は消える
            $redM = $apron->variants()->firstOrFail();
            $redM = $service->update($redM, 2, ['sku' => 'APR-R-M', 'unit_amount' => 5200]);
            app(StockService::class)->receive($redM, $store, 3);
            $apron = $service->define($apron->refresh(), 3, [['name' => '色', 'values' => ['赤', '青', '生成り']], ['name' => 'サイズ', 'values' => ['M']]]);
            $kept = $apron->variants()->get()->keyBy(static fn (ListingVariant $v): string => $v->label());
            $this->assertSame(['赤 / M', '青 / M', '生成り / M'], $kept->keys()->all());
            $this->assertSame($redM->id, $kept['赤 / M']->id);
            $this->assertSame('APR-R-M', $kept['赤 / M']->sku);
            $this->assertSame(3, $kept['赤 / M']->stocks()->firstOrFail()->quantity);

            // 注文の途中の品がある種類は消せない
            app(StockService::class)->reserve($kept['赤 / M'], $store, 1);

            try {
                $service->define($apron, $apron->version, [['name' => '色', 'values' => ['青']]]);
                $this->fail('確保中の在庫がある種類を消せてしまった');
            } catch (ValidationException) {
                $this->assertSame('APR-R-M', ListingVariant::query()->whereKey($redM->id)->value('sku'));
            }

            // 古いバージョンからの保存は断る
            $this->expectException(StaleListingException::class);
            $service->define($apron, 1, []);
        });
    }

    public function test_the_plain_variant_can_be_handed_to_a_chosen_combination_when_options_are_first_added(): void
    {
        $this->inside('lvc', function (Seller $seller): void {
            $service = app(VariantService::class);
            $store = Location::query()->where('seller_id', $seller->id)->firstOrFail();
            $tote = app(ListingService::class)->create($seller, 'product', ['title' => 'トート', 'unit_amount' => 5800, 'sku' => 'TOTE']);
            $plain = $tote->soleVariant();
            $this->assertNotNull($plain);
            app(StockService::class)->receive($plain, $store, 7);
            app(StockService::class)->reserve($plain, $store, 2);

            // 渡す先が新しい組み合わせに無い・軸が空なら断り、何も変えない
            foreach ([[[['name' => '色', 'values' => ['黒']]], ['白']], [[], []]] as [$options, $carry]) {
                try {
                    $service->define($tote->refresh(), 1, $options, null, $carry);
                    $this->fail('組み合わせに無い渡す先を受け付けた');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('carry_to', $e->errors());
                }
            }
            $this->assertSame(1, $tote->refresh()->version);

            // 確保中の品があっても、渡すなら消えないので通る。品番・在庫（確保の分も）・行そのものが選んだ組み合わせへ移る
            $tote = $service->define($tote, 1, [['name' => '色', 'values' => ['生成り', '黒']], ['name' => 'サイズ', 'values' => ['M', 'L']]], null, ['黒', ' L ']);
            $variants = $tote->variants()->get()->keyBy(static fn (ListingVariant $v): string => $v->label());
            $this->assertSame(['生成り / M', '生成り / L', '黒 / M', '黒 / L'], $variants->keys()->all());
            $this->assertSame($plain->id, $variants['黒 / L']->id);
            $this->assertSame('TOTE', $variants['黒 / L']->sku);
            $stock = $variants['黒 / L']->stocks()->firstOrFail();
            $this->assertSame([7, 2], [$stock->quantity, $stock->reserved]);
            $this->assertNull($variants['生成り / M']->sku);
            $this->assertSame(0, (int) InventoryStock::query()->where('variant_id', $variants['生成り / M']->id)->sum('quantity'));
            $this->assertSame(0, ListingVariant::onlyTrashed()->where('listing_id', $tote->id)->count());

            // 渡せるのは軸の無い商品だけ（もう軸がある商品で渡す先を言っても断る）
            try {
                $service->define($tote, $tote->version, [['name' => '色', 'values' => ['黒']]], null, ['黒']);
                $this->fail('軸のある商品で渡す先を受け付けた');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('carry_to', $e->errors());
            }
        });
    }

    public function test_options_and_variant_changes_are_checked(): void
    {
        $this->inside('lvg', function (Seller $seller): void {
            $service = app(VariantService::class);
            $apron = app(ListingService::class)->create($seller, 'product', ['title' => 'エプロン', 'unit_amount' => 4800]);
            $mug = app(ListingService::class)->create($seller, 'product', ['title' => 'マグ', 'unit_amount' => 1000]);

            foreach ([
                '軸が 4 つ' => [['name' => 'a', 'values' => ['1']], ['name' => 'b', 'values' => ['1']], ['name' => 'c', 'values' => ['1']], ['name' => 'd', 'values' => ['1']]],
                '同じ軸の名前' => [['name' => '色', 'values' => ['赤']], ['name' => '色', 'values' => ['青']]],
                '同じ値' => [['name' => '色', 'values' => ['赤', '赤']]],
                '値が無い' => [['name' => '色', 'values' => []]],
                '向きを変える印' => [['name' => '色', 'values' => ["赤\u{202E}"]]],
                '組み合わせが多すぎる' => [['name' => 'a', 'values' => range('a', 'k')], ['name' => 'b', 'values' => range('a', 'k')]],
            ] as $case => $options) {
                try {
                    $service->define($apron, $apron->version, $options);
                    $this->fail("断られなかった: {$case}");
                } catch (ValidationException) {
                    $this->assertSame(1, $apron->refresh()->version, $case);
                }
            }

            $variant = $apron->variants()->firstOrFail();

            // 別の商品の写真・負の価格は断る
            $photo = ListingImage::create(['listing_id' => $mug->id, 'position' => 0, 'width' => 1, 'height' => 1, 'checksum' => str_repeat('a', 64)]);
            $this->assertRefusedChange(fn () => $service->update($variant, 1, ['image_id' => $photo->id]), 'image_id');
            $this->assertRefusedChange(fn () => $service->update($variant, 1, ['unit_amount' => -1]), 'unit_amount');

            $stopped = $service->update($variant, 1, ['status' => 'stopped']);
            $this->assertSame(VariantStatus::Stopped, $stopped->status);
            $this->assertSame(2, $apron->refresh()->version);
        });
    }

    /**
     * @param  Closure(): mixed  $work
     */
    private function assertRefusedChange(Closure $work, string $field): void
    {
        try {
            $work();
            $this->fail("断られなかった: {$field}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    /**
     * 色（赤・青）× サイズ（M）の軸を持つエプロン。軸の無い最初の種類は消しておく（種類を作り直した形）
     *
     * @return array{0: Listing, 1: ListingOptionValue, 2: ListingOptionValue, 3: ListingOptionValue}
     */
    private function apron(Seller $seller): array
    {
        $apron = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'リネンのエプロン', 'unit_amount' => 4800, 'status' => ListingStatus::Active]);
        $apron->variants()->delete();
        $color = ListingOption::create(['listing_id' => $apron->id, 'position' => 1, 'name' => '色']);
        $size = ListingOption::create(['listing_id' => $apron->id, 'position' => 2, 'name' => 'サイズ']);
        $red = ListingOptionValue::create(['option_id' => $color->id, 'position' => 1, 'label' => '赤']);
        $blue = ListingOptionValue::create(['option_id' => $color->id, 'position' => 2, 'label' => '青']);
        $m = ListingOptionValue::create(['option_id' => $size->id, 'position' => 1, 'label' => 'M']);

        return [$apron, $red, $blue, $m];
    }

    /**
     * @param  Closure(): mixed  $work
     */
    private function expectsCheck(Closure $work, bool $unique = false): void
    {
        try {
            DB::transaction($work);
            $this->fail($unique ? '同じ組み合わせを作れてしまった' : 'DB の決まりをすり抜けた');
        } catch (QueryException $e) {
            $this->assertContains($e->getCode(), $unique ? ['23505'] : ['23514', '23503', '23505']);
        }
    }

    /**
     * @param  Closure(): mixed  $work
     */
    private function expectsUnique(Closure $work): void
    {
        $this->expectsCheck($work, unique: true);
    }

    private function inside(string $id, Closure $work): void
    {
        $tenant = Tenant::create(['id' => $id, 'name' => "店 {$id}"]);
        $this->tenants[] = $tenant;
        tenancy()->initialize($tenant);

        try {
            $work(Seller::query()->where('slug', 'default')->firstOrFail());
        } finally {
            tenancy()->end();
        }
    }
}
