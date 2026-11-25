<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面で商品の種類（色・サイズ）を編集する（ADR 0036）。変えるのは核の VariantService。
 */
final class VariantAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private Operator $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_defining_options_lists_the_variants_and_each_can_be_priced_numbered_and_stocked(): void
    {
        $this->tenant('vaa');
        [$this->operator] = $this->enrolledOperator();
        $listing = $this->create('vaa', ['title' => 'T シャツ', 'unit_amount' => 3000, 'sku' => 'TEE']);
        $path = "/api/tenants/vaa/listings/{$listing['id']}";

        // 軸の無い商品は、名前の無い種類が 1 つ（品番はそこにある）
        $this->assertSame([], $listing['options']);
        $this->assertSame(1, $listing['variant_count']);
        $this->assertSame(['', 'TEE'], [$listing['variants'][0]['name'], $listing['variants'][0]['sku']]);

        $defined = $this->send('PUT', "{$path}/options", ['version' => 1, 'options' => [
            ['name' => '色', 'values' => ['赤', '青']],
            ['name' => 'サイズ', 'values' => ['S', 'M']],
        ]])->assertOk()->assertJsonPath('listing.version', 2)->assertJsonPath('listing.variant_count', 4)
            ->assertJsonPath('listing.options.0.name', '色')->assertJsonPath('listing.options.1.values.1.label', 'M')
            ->json('listing');
        $this->assertSame(['赤 / S', '赤 / M', '青 / S', '青 / M'], array_column($defined['variants'], 'name'));
        $this->assertSame([null, null, null, null], array_column($defined['variants'], 'unit_amount'));
        $this->assertSame('active', $defined['variants'][0]['status']);

        // 種類の価格と品番を変える。商品のバージョンが進む
        $redM = $defined['variants'][1];
        $this->send('PATCH', "{$path}/variants/{$redM['id']}", ['version' => 2, 'unit_amount' => 3300, 'sku' => 'TEE-RED-M', 'status' => 'stopped'])->assertOk()
            ->assertJsonPath('listing.version', 3)
            ->assertJsonPath('listing.variants.1.unit_amount', 3300)
            ->assertJsonPath('listing.variants.1.sku', 'TEE-RED-M')
            ->assertJsonPath('listing.variants.1.status', 'stopped')
            ->assertJsonPath('listing.variants.0.unit_amount', null);
        // 価格を空に戻すと商品の価格で売る
        $this->send('PATCH', "{$path}/variants/{$redM['id']}", ['version' => 3, 'unit_amount' => null])->assertOk()->assertJsonPath('listing.variants.1.unit_amount', null);

        // 種類を選んで入庫する。種類の欄と、商品の欄（拠点ごとの合計）に出る
        $locationId = $defined['stocks'][0]['location_id'];
        $after = $this->send('POST', "{$path}/stock/receive", ['location_id' => $locationId, 'variant_id' => $redM['id'], 'quantity' => 4])->assertOk()->json('listing');
        $this->assertSame(4, $after['variants'][1]['stocks'][0]['quantity']);
        $this->assertSame(0, $after['variants'][0]['stocks'][0]['quantity']);
        $this->assertSame(4, $after['stocks'][0]['quantity']);
        // 軸のある商品は、種類を選ばずには入庫できない
        $this->send('POST', "{$path}/stock/receive", ['location_id' => $locationId, 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('variant_id');

        // 一覧にも種類の数が出る
        $this->send('GET', '/api/tenants/vaa/listings')->assertOk()->assertJsonPath('listings.0.variant_count', 4)->assertJsonPath('listings.0.stock.quantity', 4);

        $this->inside('vaa', function () use ($redM): void {
            $this->assertSame(4, InventoryStock::query()->where('variant_id', $redM['id'])->sole()->quantity);
            // 変えた人がイベントに残る
            $this->assertSame(
                ['operator:'.$this->operator->id],
                DomainEvent::query()->whereIn('type', ['listing.variants_defined', 'listing.variant_updated'])->pluck('actor')->unique()->values()->all(),
            );
        });
    }

    public function test_first_options_can_take_over_the_plain_sku_and_stock(): void
    {
        $this->tenant('vac');
        [$this->operator] = $this->enrolledOperator();
        $listing = $this->create('vac', ['title' => 'トート', 'unit_amount' => 5800, 'sku' => 'TOTE']);
        $path = "/api/tenants/vac/listings/{$listing['id']}";
        $plain = $listing['variants'][0];
        $this->send('POST', "{$path}/stock/receive", ['location_id' => $listing['stocks'][0]['location_id'], 'quantity' => 5])->assertOk();

        // 新しい組み合わせに無い渡す先は 422、形の違う値も 422
        $options = [['name' => '色', 'values' => ['生成り', '黒']]];
        $this->send('PUT', "{$path}/options", ['version' => 1, 'options' => $options, 'carry_to' => ['白']])->assertStatus(422)->assertJsonValidationErrors('carry_to');
        $this->send('PUT', "{$path}/options", ['version' => 1, 'options' => $options, 'carry_to' => 'black'])->assertStatus(422)->assertJsonValidationErrors('carry_to');

        $defined = $this->send('PUT', "{$path}/options", ['version' => 1, 'options' => $options, 'carry_to' => ['黒']])->assertOk()->json('listing');
        $this->assertSame(['生成り', '黒'], array_column($defined['variants'], 'name'));
        $black = $defined['variants'][1];
        $this->assertSame([$plain['id'], 'TOTE', 5], [$black['id'], $black['sku'], $black['stocks'][0]['quantity']]);
    }

    public function test_another_listings_variant_is_not_found_and_a_stale_version_is_a_conflict(): void
    {
        $this->tenant('vab');
        [$this->operator] = $this->enrolledOperator();
        $mug = $this->create('vab', ['title' => 'マグ', 'unit_amount' => 1200]);
        $tee = $this->create('vab', ['title' => 'T シャツ', 'unit_amount' => 3000, 'sku' => 'TEE']);
        $teeVariant = $tee['variants'][0]['id'];

        // 別の商品の種類は「無い」（マグの経路から T シャツの種類は変えられない）
        $this->send('PATCH', "/api/tenants/vab/listings/{$mug['id']}/variants/{$teeVariant}", ['version' => 1, 'unit_amount' => 1])->assertNotFound();
        $this->inside('vab', fn () => $this->assertNull(ListingVariant::query()->findOrFail($teeVariant)->unit_amount));

        // 古いバージョンは 409（先に保存した人が勝つ）
        $mugPath = "/api/tenants/vab/listings/{$mug['id']}";
        $this->send('PUT', "{$mugPath}/options", ['version' => 1, 'options' => [['name' => '色', 'values' => ['白', '黒']]]])->assertOk();
        $this->send('PUT', "{$mugPath}/options", ['version' => 1, 'options' => [['name' => '色', 'values' => ['緑']]]])->assertStatus(409)->assertJsonPath('current_version', 2);
        $this->send('PATCH', "{$mugPath}/variants/{$this->send('GET', $mugPath)->json('listing.variants.0.id')}", ['version' => 1, 'sku' => 'MUG-W'])->assertStatus(409);

        // 品番は出品者の中で重ならない（T シャツの TEE と同じ品番は 422）。形も商品の品番と同じ決まり
        $white = $this->send('GET', $mugPath)->json('listing.variants.0.id');
        $this->send('PATCH', "{$mugPath}/variants/{$white}", ['version' => 2, 'sku' => 'TEE'])->assertStatus(422)->assertJsonValidationErrors('sku');
        $this->send('PATCH', "{$mugPath}/variants/{$white}", ['version' => 2, 'sku' => '白 マグ'])->assertStatus(422)->assertJsonValidationErrors('sku');
        // 軸は 3 つまで
        $this->send('PUT', "{$mugPath}/options", ['version' => 2, 'options' => array_fill(0, 4, ['name' => '色', 'values' => ['白']])])->assertStatus(422)->assertJsonValidationErrors('options');
        $this->send('GET', $mugPath)->assertJsonPath('listing.version', 2)->assertJsonPath('listing.variants.0.sku', null);
    }

    public function test_viewers_cannot_change_variants(): void
    {
        $this->tenant('vac');
        [$this->operator] = $this->enrolledOperator();
        $listing = $this->create('vac', ['title' => 'マグ', 'unit_amount' => 1200]);
        $variantId = $listing['variants'][0]['id'];

        // オーナーはログアウトし、閲覧だけのスタッフでログインし直す
        $this->send('POST', '/auth/logout')->assertNoContent();
        [$staff] = $this->enrolledOperator('viewer@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'vac', 'read', 'test');

        $path = "/api/tenants/vac/listings/{$listing['id']}";
        $this->send('GET', $path)->assertOk()->assertJsonPath('listing.variants.0.id', $variantId);
        $this->send('PUT', "{$path}/options", ['version' => 1, 'options' => [['name' => '色', 'values' => ['白']]]])->assertForbidden();
        $this->send('PATCH', "{$path}/variants/{$variantId}", ['version' => 1, 'unit_amount' => 1])->assertForbidden();

        $this->inside('vac', function () use ($variantId): void {
            $this->assertSame(1, ListingVariant::query()->count());
            $this->assertNull(ListingVariant::query()->findOrFail($variantId)->unit_amount);
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function create(string $tenant, array $fields): array
    {
        $sellerId = $this->inside($tenant, fn (): string => (string) Seller::query()->where('slug', 'default')->value('id'));
        $listing = $this->send('POST', "/api/tenants/{$tenant}/listings", ['seller_id' => $sellerId, 'kind' => 'product'] + $fields)->assertCreated()->json('listing');
        $this->assertIsArray($listing);

        return $listing;
    }
}
