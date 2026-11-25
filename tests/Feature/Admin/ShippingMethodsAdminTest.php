<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Shop\Models\ShippingMethod;
use App\Core\Shop\Shipping\Regions;
use App\Storefront\Postal\Prefectures;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 設定 › 配送の送料の表（ADR 0064）と、商品の「配送の方法」「送料無料」。
 * 見るのは閲覧、変えるのは運用。表はまとめて置き換え、47 県がそろわない・範囲の外・重なる名前・ほかの店の ID は断る。DB の制約が 2 枚目
 */
final class ShippingMethodsAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->tenant('sma');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_table_is_replaced_with_regional_rates_and_a_second_method(): void
    {
        $this->enrolledOperator();
        // 新しい店は「通常便」1 つ・全部 0 円（移行の形）
        $first = $this->send('GET', '/api/tenants/sma/shipping-methods')->assertOk()
            ->assertJsonCount(1, 'methods')->assertJsonPath('methods.0.name', '通常便')->assertJsonPath('methods.0.free_over', null)
            ->assertJsonCount(11, 'regions')->assertJsonPath('limits.fee', 100000)->json('methods.0');
        $this->assertSame(Regions::PREFECTURES, array_keys($first['rates']));
        // ショップの住所の選択肢と同じ 47 県
        $this->assertSame(Prefectures::ALL, Regions::PREFECTURES);
        // 地域は 47 県を 1 回ずつ（山梨は関東など、並びは JIS の順と違ってよい）
        $this->assertEqualsCanonicalizing(Regions::PREFECTURES, array_merge(...array_values(Regions::ALL)));
        $this->assertCount(47, array_merge(...array_values(Regions::ALL)));

        $rates = Regions::flat(800);
        $rates['北海道'] = 1500;
        $rates['沖縄県'] = 2000;
        $saved = $this->send('PUT', '/api/tenants/sma/shipping-methods', ['methods' => [
            ['id' => $first['id'], 'name' => '通常便', 'free_over' => 5000, 'rates' => $rates],
            ['id' => null, 'name' => 'クール便', 'free_over' => null, 'rates' => Regions::flat(1200)],
        ]])->assertOk()->assertJsonCount(2, 'methods')->assertJsonPath('methods.0.rates.沖縄県', 2000)->assertJsonPath('methods.1.name', 'クール便')->json('methods');

        // 並びを入れ替え・名前を取り替えても一意の制約に当たらない。送られなかった方法は消し、その商品はいちばん上の方法に戻る
        $listing = $this->inside('sma', fn (): string => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '冷凍のケーキ', 'unit_amount' => 3000, 'status' => 'active', 'shipping_method_id' => $saved[1]['id']])->id);
        $this->send('PUT', '/api/tenants/sma/shipping-methods', ['methods' => [
            ['id' => $saved[1]['id'], 'name' => '通常便', 'free_over' => null, 'rates' => Regions::flat(1200)],
            ['id' => $saved[0]['id'], 'name' => 'クール便', 'free_over' => 5000, 'rates' => $rates],
        ]])->assertOk()->assertJsonPath('methods.0.id', $saved[1]['id'])->assertJsonPath('methods.0.name', '通常便');
        $this->send('PUT', '/api/tenants/sma/shipping-methods', ['methods' => [['id' => $saved[0]['id'], 'name' => '通常便', 'free_over' => 5000, 'rates' => $rates]]])->assertOk()->assertJsonCount(1, 'methods');

        $this->inside('sma', function () use ($listing, $saved): void {
            $this->assertNull(Listing::query()->findOrFail($listing)->shipping_method_id);
            $this->assertFalse(ShippingMethod::query()->whereKey($saved[1]['id'])->exists());
            $this->assertSame(0, DB::table('shipping_rates')->where('method_id', $saved[1]['id'])->count());
            // 変えたことは記録に（actor は運営）。同じ表の保存は記録しない
            $this->assertSame(3, DomainEvent::query()->where('type', 'shop.shipping_methods_updated')->count());
            $this->assertStringStartsWith('operator:', (string) DomainEvent::query()->where('type', 'shop.shipping_methods_updated')->value('actor'));
        });
        $this->send('PUT', '/api/tenants/sma/shipping-methods', ['methods' => [['id' => $saved[0]['id'], 'name' => '通常便', 'free_over' => 5000, 'rates' => $rates]]])->assertOk();
        $this->inside('sma', fn () => $this->assertSame(3, DomainEvent::query()->where('type', 'shop.shipping_methods_updated')->count()));
        $this->assertSame(4, AuditEvent::query()->where('type', 'operator.shipping_changed')->where('tenant_id', 'sma')->count());
    }

    public function test_wrong_tables_are_refused_and_the_database_is_the_second_wall(): void
    {
        $this->enrolledOperator();
        $id = $this->send('GET', '/api/tenants/sma/shipping-methods')->json('methods.0.id');
        $method = static fn (array $over = []): array => [...['id' => $id, 'name' => '通常便', 'free_over' => null, 'rates' => Regions::flat(800)], ...$over];
        $missing = Regions::flat(800);
        unset($missing['沖縄県']);
        $extra = [...Regions::flat(800), '架空県' => 1];

        foreach ([
            'methods' => ['methods' => []],
            'methods ' => ['methods' => array_fill(0, 11, $method(['id' => null]))],
            'methods.0.rates' => ['methods' => [$method(['rates' => $missing])]],
            'methods.0.rates ' => ['methods' => [$method(['rates' => $extra])]],
            'methods.0.rates.東京都' => ['methods' => [$method(['rates' => [...Regions::flat(800), '東京都' => -1]])]],
            'methods.0.rates.東京都 ' => ['methods' => [$method(['rates' => [...Regions::flat(800), '東京都' => 100001]])]],
            'methods.0.rates.東京都  ' => ['methods' => [$method(['rates' => [...Regions::flat(800), '東京都' => 800.5]])]],
            'methods.0.rates.東京都   ' => ['methods' => [$method(['rates' => [...Regions::flat(800), '東京都' => '8e2']])]],
            'methods.0.rates.東京都    ' => ['methods' => [$method(['rates' => [...Regions::flat(800), '東京都' => true]])]],
            'methods.0.free_over' => ['methods' => [$method(['free_over' => 0])]],
            'methods.0.free_over ' => ['methods' => [$method(['free_over' => 10000001])]],
            'methods.0.name' => ['methods' => [$method(['name' => '  '])]],
            'methods.0.name ' => ['methods' => [$method(['name' => str_repeat('あ', 31)])]],
            'methods.0.name  ' => ['methods' => [$method(['name' => "改\n行"])]],
            'methods.1.name' => ['methods' => [$method(), $method(['id' => null, 'name' => ' 通常便 '])]],
            'methods.0.id' => ['methods' => [$method(['id' => '01jbq0000000000000000000zz'])]],
        ] as $field => $input) {
            $this->send('PUT', '/api/tenants/sma/shipping-methods', $input)->assertStatus(422)->assertJsonValidationErrors(trim($field));
        }

        // ちょうど上限は通る（10 の方法・¥100,000・¥10,000,000・30 文字）
        $ten = [$method(['rates' => Regions::flat(100000), 'free_over' => 10000000, 'name' => str_repeat('あ', 30)])];
        for ($i = 2; $i <= 10; $i++) {
            $ten[] = $method(['id' => null, 'name' => "便{$i}"]);
        }
        $this->send('PUT', '/api/tenants/sma/shipping-methods', ['methods' => $ten])->assertOk()->assertJsonCount(10, 'methods');

        // サービスをすり抜けても DB が断る（負の額・知らない県・範囲の外の無料の線）
        $this->inside('sma', function () use ($id): void {
            foreach ([
                fn () => DB::table('shipping_rates')->where('method_id', $id)->where('prefecture', '東京都')->update(['fee' => -1]),
                fn () => DB::table('shipping_rates')->insert(['method_id' => $id, 'prefecture' => '架空県', 'fee' => 1]),
                fn () => DB::table('shipping_methods')->where('id', $id)->update(['free_over' => 0]),
            ] as $write) {
                try {
                    DB::transaction($write);
                    $this->fail('DB が断らなかった');
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
    }

    public function test_reading_needs_read_and_changing_needs_operate(): void
    {
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'sma', 'read', 'test');
        $id = $this->send('GET', '/api/tenants/sma/shipping-methods')->assertOk()->json('methods.0.id');
        $this->send('PUT', '/api/tenants/sma/shipping-methods', ['methods' => [['id' => $id, 'name' => '通常便', 'free_over' => null, 'rates' => Regions::flat(1)]]])->assertForbidden();

        $access->grant($staff, 'sma', 'operate', 'test');
        $this->send('PUT', '/api/tenants/sma/shipping-methods', ['methods' => [['id' => $id, 'name' => '通常便', 'free_over' => null, 'rates' => Regions::flat(1)]]])->assertOk();
    }

    public function test_a_product_chooses_its_method_and_free_shipping(): void
    {
        $this->enrolledOperator();
        $this->tenant('smb');
        $methods = $this->send('PUT', '/api/tenants/sma/shipping-methods', ['methods' => [
            ['id' => $this->send('GET', '/api/tenants/sma/shipping-methods')->json('methods.0.id'), 'name' => '通常便', 'free_over' => null, 'rates' => Regions::flat(800)],
            ['id' => null, 'name' => 'クール便', 'free_over' => null, 'rates' => Regions::flat(1200)],
        ]])->json('methods');
        // ほかの店の方法は、その店で足したもの（テストの店の DB は型から写すので、移行が作る最初の方法の ID は店どうしで同じになる）
        $other = $this->send('PUT', '/api/tenants/smb/shipping-methods', ['methods' => [
            ['id' => $this->send('GET', '/api/tenants/smb/shipping-methods')->json('methods.0.id'), 'name' => '通常便', 'free_over' => null, 'rates' => Regions::flat(800)],
            ['id' => null, 'name' => '別の店の便', 'free_over' => null, 'rates' => Regions::flat(900)],
        ]])->assertOk()->json('methods.1.id');
        $seller = $this->inside('sma', fn (): string => (string) Seller::query()->value('id'));

        $this->send('GET', '/api/tenants/sma/listing-options')->assertOk()->assertJsonPath('shipping_methods.1', ['id' => $methods[1]['id'], 'name' => 'クール便']);
        $listing = $this->send('POST', '/api/tenants/sma/listings', ['seller_id' => $seller, 'kind' => 'product', 'title' => 'アイス', 'unit_amount' => 2000, 'shipping_method_id' => $methods[1]['id'], 'free_shipping' => true])
            ->assertCreated()->assertJsonPath('listing.shipping_method_id', $methods[1]['id'])->assertJsonPath('listing.free_shipping', true)->json('listing');

        // ほかの店の方法・形の違う ID・真偽でない値は断る
        $this->send('PATCH', "/api/tenants/sma/listings/{$listing['id']}", ['version' => $listing['version'], 'shipping_method_id' => $other])->assertStatus(422)->assertJsonValidationErrors('shipping_method_id');
        $this->send('PATCH', "/api/tenants/sma/listings/{$listing['id']}", ['version' => $listing['version'], 'shipping_method_id' => '../x'])->assertStatus(422)->assertJsonValidationErrors('shipping_method_id');
        $this->send('PATCH', "/api/tenants/sma/listings/{$listing['id']}", ['version' => $listing['version'], 'free_shipping' => 'yes'])->assertStatus(422)->assertJsonValidationErrors('free_shipping');

        // 空に戻す（いちばん上の方法）。変えたことは商品の記録に
        $this->send('PATCH', "/api/tenants/sma/listings/{$listing['id']}", ['version' => $listing['version'], 'shipping_method_id' => null, 'free_shipping' => false])
            ->assertOk()->assertJsonPath('listing.shipping_method_id', null)->assertJsonPath('listing.free_shipping', false);
        $this->inside('sma', function () use ($listing): void {
            $changes = DomainEvent::query()->where('type', 'listing.updated')->where('aggregate_id', $listing['id'])->sole()->payload['changes'];
            $this->assertEqualsCanonicalizing(['shipping_method_id', 'free_shipping'], array_keys($changes));
        });
    }
}
