<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Plugin\Services\PluginDataPurge;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Plugin\Services\StockComponents;
use App\Storefront\Cart\CartException;
use App\Storefront\Cart\CartService;
use Carbon\CarbonImmutable;
use EcSugar\PluginBundle\BundleComponents;
use EcSugar\PluginBundle\BundleStock;
use EcSugar\PluginBundle\Models\BundleComponent;
use EcSugar\PluginPreorder\PreorderListings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「セット商品」。セットの中身（商品 × 1 セットあたりの数）が、
 * 核の在庫の口（StockComponentsProvider）と見せ方の口（ListingVisibilityProvider）でどう効くかを確かめる:
 * 保存と読み出し・数の範囲（DB の規則も）・**入れ子を断る**（3 方向）・作れるセットの数が 0 なら一覧から隠れる・
 * 確保されている分は引く・構成の品を後から消した場合・App を止めた店では効かない（店の境界）・データを消す口。
 *
 * セットの在庫は構成の品から引く（核の LineStock。区切り 1 の BundleStockTest が押さえる・解く・出庫するを確かめている）。
 * ショップに出すために、セットの商品そのものにも在庫を入れておく（この数は売れても減らない。README の「気をつけること」）
 */
final class BundleAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    /** セットの商品 */
    private string $bundle;

    private string $bundleVariant;

    /** @var array<string, string> 品の名前 => 商品の ID */
    private array $listings = [];

    /** @var array<string, string> 品の名前 => 種類の ID */
    private array $variants = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->tenant('bnd');
        $this->inside('bnd', function (): void {
            app(PluginStates::class)->set('bundle', true, 'test');
            // 構成の品: コーヒー 10 個・カップ 4 個
            $this->product('coffee', 'コーヒー豆 200g', 'COFFEE-200G', 10);
            $this->product('cup', 'マグカップ（白）', 'CUP-WHITE', 4);
            // セットの商品。自分の在庫は「ショップに出す上限の数」で、売れても減らない
            [$this->bundle, $this->bundleVariant] = $this->product('bundle', 'おうちカフェセット', 'GIFT-CAFE', 999);
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_owner_sets_the_parts_on_the_product_and_sees_the_bundle_on_the_app_page(): void
    {
        [$owner, $key] = $this->enrolledOperator();
        $panel = "/api/tenants/bnd/listings/{$this->bundle}/apps/bundle";
        $records = '/api/tenants/bnd/apps/bundle/records';

        // 形の違う書き方・1 未満の数・知らない品番は、欄の名前つきで断る
        foreach (['COFFEE-200G', 'COFFEE-200G*0', 'COFFEE-200G*1000', 'COFFEE-200G*2;CUP-WHITE*1'] as $wrong) {
            $this->send('PUT', $panel, ['values' => ['parts' => $wrong]])->assertUnprocessable()->assertJsonValidationErrors(['values.parts']);
        }
        $this->send('PUT', $panel, ['values' => ['parts' => 'NOT-A-SKU*1']])->assertUnprocessable()->assertJsonValidationErrors(['values.parts']);
        $this->assertSame(0, $this->rows());

        // 決めると、欄の案内が今の中身と作れる数を出す（コーヒー 10 ÷ 2 = 5、カップ 4 ÷ 2 = 2 の小さい方）
        $this->send('PUT', $panel, ['values' => ['parts' => 'COFFEE-200G*2, CUP-WHITE*2']])->assertOk()
            ->assertJsonPath('app.note', 'セットの中身: コーヒー豆 200g ×2、マグカップ（白） ×2。今の在庫で 2 セット販売できます。'
                .'売れるたびに引くのは構成の品の在庫で、この商品の在庫は使いません。');

        // 読み直すと同じ書き方で戻る
        $this->send('GET', "/api/tenants/bnd/listings/{$this->bundle}/apps")->assertOk()
            ->assertJsonPath('apps.0.plugin', 'bundle')
            ->assertJsonPath('apps.0.values.parts', 'COFFEE-200G*2, CUP-WHITE*2');

        // 記録は商品・種類の ID と数だけ（商品の名前は入れない）
        $set = $this->inside('bnd', fn (): DomainEvent => DomainEvent::query()->where('type', 'bundle.set')->sole());
        $this->assertSame([$owner->actor(), $this->bundle], [$set->actor, $set->payload['listing_id']]);
        // 記録の鍵の並びは核がそろえる（中身だけを比べる）
        $this->assertEquals([['variant_id' => $this->variants['coffee'], 'quantity' => 2], ['variant_id' => $this->variants['cup'], 'quantity' => 2]], $set->payload['parts']);
        $this->assertStringNotContainsString('コーヒー', json_encode($set->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // App の画面: セット 1 件（商品へのリンク・構成の品の数・作れる数）
        $rows = $this->send('GET', $records)->assertOk()->assertJsonPath('meta.total', 1);
        $rows->assertJsonPath('rows.0.id', $this->bundle)
            ->assertJsonPath('rows.0.cells.listing', ['label' => 'おうちカフェセット', 'kind' => 'listing', 'id' => $this->bundle])
            ->assertJsonPath('rows.0.cells.parts', 2)
            ->assertJsonPath('rows.0.cells.sets', 2)
            ->assertJsonPath('rows.0.cells.state.label', '販売できる');
        $this->send('GET', $records.'?filter=unbuildable')->assertOk()->assertJsonPath('meta.total', 0);

        // 1 件の画面には構成の品の表が出る
        $this->send('GET', "{$records}/{$this->bundle}")->assertOk()
            ->assertJsonPath('record.tables.0.rows.0.cells.sku', 'COFFEE-200G')
            ->assertJsonPath('record.tables.0.rows.0.cells.quantity', 2)
            ->assertJsonPath('record.tables.0.rows.0.cells.free', 10)
            ->assertJsonPath('record.tables.0.rows.0.cells.sets', 5);

        // セットをやめるのは取り消せない操作なので、直前の再確認の前は 423
        $this->send('POST', "{$records}/{$this->bundle}/actions/clear")->assertStatus(423);
        $this->assertSame(2, $this->rows());

        // 再確認の後に中身が消え、商品はふつうの商品に戻る
        $this->reconfirm($owner, $key);
        $this->send('POST', "{$records}/{$this->bundle}/actions/clear")->assertOk();
        $this->assertSame(0, $this->rows());
        $this->assertSame(['bundle.set', 'bundle.cleared'],
            $this->inside('bnd', fn (): array => DomainEvent::query()->whereIn('type', ['bundle.set', 'bundle.cleared'])->orderBy('sequence')->pluck('type')->all()));

        // 欄を空にして保存しても、セットをやめられる
        $this->send('PUT', $panel, ['values' => ['parts' => 'COFFEE-200G*2']])->assertOk();
        $this->assertSame(1, $this->rows());
        $this->send('PUT', $panel, ['values' => ['parts' => null]])->assertOk()
            ->assertJsonPath('app.values.parts', null);
        $this->assertSame(0, $this->rows());
    }

    public function test_a_count_below_one_is_refused_by_the_app_and_by_the_database(): void
    {
        $this->inside('bnd', function (): void {
            // App は 1 未満を断る（欄の形の決まりの前に、書き方を読む所でも断る）
            foreach (['COFFEE-200G*0', 'COFFEE-200G*-1'] as $wrong) {
                try {
                    $this->save($wrong);
                    $this->fail("1 未満の数で通った（{$wrong}）");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('parts', $e->errors());
                }
            }

            $this->assertSame(0, BundleComponent::query()->count());

            // 規則は DB でも守る（表に直に入れても断る）
            try {
                BundleComponent::query()->create(['listing_id' => $this->bundle, 'part_listing_id' => $this->listings['coffee'],
                    'variant_id' => $this->variants['coffee'], 'quantity' => 0]);
                $this->fail('DB が 0 個の構成を受け入れた');
            } catch (QueryException $e) {
                $this->assertStringContainsString('bundle_components_quantity_range', $e->getMessage());
            }
        });
    }

    public function test_a_bundle_cannot_contain_another_bundle_or_itself(): void
    {
        $this->inside('bnd', function (): void {
            $this->save('COFFEE-200G*2, CUP-WHITE*1');

            // 1. 自分自身は構成の品にできない
            $this->refused('GIFT-CAFE*1', 'この商品そのもの');

            // 2. 構成の品に、別のセットの商品は入れられない（セットの中にセット）
            [$other, $otherVariant] = $this->product('other', '贈りものセット', 'GIFT-OTHER', 10);
            $this->refused('GIFT-CAFE*1', 'セットの中にセット', $other);

            // 3. ほかのセットの構成に入っている商品は、セットにできない（逆向きの入れ子）
            $this->refused('GIFT-OTHER*1', 'ほかのセットの構成', $this->listings['coffee']);

            // どの断りでも行は増えていない（最初のセットの 2 行だけ）
            $this->assertSame(2, BundleComponent::query()->count());
            $this->assertSame(0, BundleComponent::query()->whereIn('listing_id', [$other, $this->listings['coffee']])->count());

            // 入れ子は DB の引き金でも断る（表に直に入れても通らない）
            try {
                BundleComponent::query()->create(['listing_id' => $other, 'part_listing_id' => $this->bundle,
                    'variant_id' => $this->bundleVariant, 'quantity' => 1]);
                $this->fail('DB が入れ子を受け入れた');
            } catch (QueryException $e) {
                $this->assertStringContainsString('セットの中にセット', $e->getMessage());
            }

            // 自分自身も DB の規則で断る（まだどのセットにも関わっていない商品で確かめる）
            try {
                BundleComponent::query()->create(['listing_id' => $other, 'part_listing_id' => $other,
                    'variant_id' => $otherVariant, 'quantity' => 1]);
                $this->fail('DB が自分自身の構成を受け入れた');
            } catch (QueryException $e) {
                $this->assertStringContainsString('bundle_components_not_self', $e->getMessage());
            }
        });
    }

    /**
     * 在庫を見ずに売る商品（送らない種類・発売日の前の予約の商品）は、セットにできない。
     * 核はそういう商品を在庫を押さえずに注文にするので、セットにすると構成の品が引かれずに売れてしまう
     */
    public function test_a_product_sold_without_drawing_stock_cannot_become_a_bundle(): void
    {
        $this->inside('bnd', function (): void {
            $seller = Seller::query()->value('id');

            // 1. 送らない種類（ダウンロード）の商品はセットにできない
            app(PluginStates::class)->set('downloads', true, 'test');
            $ebook = Listing::create(['seller_id' => $seller, 'kind' => 'download', 'title' => '淹れ方の手引き（PDF）', 'unit_amount' => 500, 'status' => 'active'])->id;
            $this->refused('COFFEE-200G*1', '在庫を使わない種類', $ebook);

            // 2. 発売日の前の予約の商品はセットにできない
            app(PluginStates::class)->set('preorder', true, 'test');
            [$preorder] = $this->product('preorder', '新豆の先行セット', 'GIFT-NEW', 0);
            $release = CarbonImmutable::now(PreorderTerms::ZONE)->addDays(10)->format('Y-m-d');
            app(PreorderListings::class)->save(Listing::query()->findOrFail($preorder),
                ['enabled' => true, 'release_date' => $release, 'ends_on' => null, 'capacity' => null], 'test');
            $this->refused('COFFEE-200G*1', '予約販売の受付中', $preorder);

            // どちらの断りでも行は増えていない
            $this->assertSame(0, BundleComponent::query()->whereIn('listing_id', [$ebook, $preorder])->count());

            // 予約販売をやめれば、同じ商品をセットにできる
            app(PreorderListings::class)->save(Listing::query()->findOrFail($preorder),
                ['enabled' => false, 'release_date' => null, 'ends_on' => null, 'capacity' => null], 'test');
            $this->save('COFFEE-200G*1', $preorder);
            $this->assertSame(1, BundleComponent::query()->where('listing_id', $preorder)->count());

            // 先にセットにしてから予約販売にした商品（App からは止められない）も、セットをやめることはいつでもできる
            app(PreorderListings::class)->save(Listing::query()->findOrFail($preorder),
                ['enabled' => true, 'release_date' => $release, 'ends_on' => null, 'capacity' => null], 'test');
            $this->refused('COFFEE-200G*2', '予約販売の受付中', $preorder);
            $this->save('', $preorder);
            $this->assertSame(0, BundleComponent::query()->where('listing_id', $preorder)->count());
        });
    }

    public function test_a_bundle_that_cannot_make_one_set_leaves_the_shop(): void
    {
        $this->inside('bnd', fn () => $this->save('COFFEE-200G*2, CUP-WHITE*1'));

        // 作れるうちはふだんどおり出て、カートにも入る
        $this->shop('/products')->assertOk()->assertSee('おうちカフェセット');
        $this->shop("/products/{$this->bundle}")->assertOk()->assertSee('data-test="add-to-cart"', false);
        $this->assertNull($this->cartRefusal());

        // カップが売り切れると、1 セットも作れないのでショップから外れる（商品の画面は 404、カートにも入らない）
        $this->inside('bnd', function (): void {
            app(StockService::class)->adjust(ListingVariant::query()->findOrFail($this->variants['cup']), $this->location(), -4);
            $this->assertSame([$this->bundle], BundleStock::unbuildable());
        });
        $this->shop('/products')->assertOk()->assertDontSee('おうちカフェセット');
        $this->shop("/products/{$this->bundle}")->assertNotFound();
        $this->assertNotNull($this->cartRefusal());

        // 入荷すると、また出る
        $this->inside('bnd', fn () => app(StockService::class)->receive(ListingVariant::query()->findOrFail($this->variants['cup']), $this->location(), 1));
        $this->shop('/products')->assertOk()->assertSee('おうちカフェセット');
        $this->assertSame(1, $this->inside('bnd', fn (): int => BundleStock::sets([$this->bundle])[$this->bundle]));
    }

    public function test_stock_already_reserved_for_other_orders_does_not_count(): void
    {
        $this->inside('bnd', function (): void {
            // 1 セットにコーヒー 3 個。在庫 10 のうち 8 個がほかの注文に確保されているので、空きは 2 個 → 1 セットも作れない
            $this->save('COFFEE-200G*3');
            app(StockService::class)->reserve(ListingVariant::query()->findOrFail($this->variants['coffee']), $this->location(), 8);

            $this->assertSame(0, BundleStock::sets([$this->bundle])[$this->bundle]);
            $this->assertSame([$this->bundle], BundleStock::unbuildable());
        });

        $this->shop("/products/{$this->bundle}")->assertNotFound();
        $this->assertNotNull($this->cartRefusal());

        // 確保が解ければ 3 セット作れる（10 ÷ 3）
        $this->inside('bnd', function (): void {
            app(StockService::class)->release(ListingVariant::query()->findOrFail($this->variants['coffee']), $this->location(), 8);
            $this->assertSame(3, BundleStock::sets([$this->bundle])[$this->bundle]);
        });
        $this->shop("/products/{$this->bundle}")->assertOk()->assertSee('data-test="add-to-cart"', false);
    }

    public function test_a_part_deleted_or_unpublished_later_takes_the_bundle_out_of_the_shop(): void
    {
        $this->inside('bnd', fn () => $this->save('COFFEE-200G*2, CUP-WHITE*1'));

        // 構成の品を下書きに戻すと、セットは出ない（在庫はあるのに売れない品なので）
        $this->inside('bnd', fn () => Listing::query()->whereKey($this->listings['cup'])->update(['status' => 'draft']));
        $this->shop('/products')->assertOk()->assertDontSee('おうちカフェセット');
        $this->inside('bnd', fn () => Listing::query()->whereKey($this->listings['cup'])->update(['status' => 'active']));
        $this->shop('/products')->assertOk()->assertSee('おうちカフェセット');

        // 構成の品そのものを消す（ゴミ箱へ）と、セットは出ない・商品の画面は 404・カートにも入らない
        $this->inside('bnd', function (): void {
            $cup = Listing::query()->findOrFail($this->listings['cup']);
            app(ListingService::class)->delete($cup, $cup->version, 'operator:test');
            // 中身の行は残る（商品を戻せばまた売れる）が、作れるセットは 0
            $this->assertSame(2, BundleComponent::query()->count());
            $this->assertSame([$this->bundle], BundleStock::unbuildable());
        });
        $this->shop('/products')->assertOk()->assertDontSee('おうちカフェセット');
        $this->shop("/products/{$this->bundle}")->assertNotFound();
        $this->assertNotNull($this->cartRefusal(), '構成の品を消したセットがカートに入った');
    }

    public function test_a_shop_that_turned_the_app_off_neither_hides_the_bundle_nor_draws_the_parts(): void
    {
        $this->inside('bnd', function (): void {
            $this->save('COFFEE-200G*2, CUP-WHITE*1');
            // カップを売り切れにする（使っている間は、セットはショップから外れる）
            app(StockService::class)->adjust(ListingVariant::query()->findOrFail($this->variants['cup']), $this->location(), -4);
        });
        $this->shop("/products/{$this->bundle}")->assertNotFound();

        // 止めると、セットはふつうの商品（自分の在庫 999）として売れ、構成の品も答えない
        $this->inside('bnd', function (): void {
            app(PluginStates::class)->set('bundle', false, 'test');
            $this->assertSame([], app(StockComponents::class)->of($this->bundle), '止めた店で構成の品を答えている');
        });
        $this->shop("/products/{$this->bundle}")->assertOk()->assertSee('data-test="add-to-cart"', false);
        $this->assertNull($this->cartRefusal());

        // 中身の行は残る（使うに戻せば元に戻る）
        $this->assertSame(2, $this->rows());
        $this->inside('bnd', function (): void {
            app(PluginStates::class)->set('bundle', true, 'test');
            $this->assertEquals([$this->variants['coffee'] => 2, $this->variants['cup'] => 1], app(StockComponents::class)->of($this->bundle));
        });
    }

    public function test_purging_removes_only_this_shops_rows(): void
    {
        $this->inside('bnd', fn () => $this->save('COFFEE-200G*2'));

        // もう 1 つの店にも同じ形のセットを作る
        $this->tenant('bne');
        $other = $this->inside('bne', function (): array {
            app(PluginStates::class)->set('bundle', true, 'test');
            [$partListing, $partVariant] = $this->product('other-coffee', 'コーヒー豆 200g', 'COFFEE-200G', 5);
            [$bundle] = $this->product('other-bundle', 'よそのセット', 'GIFT-OTHER', 99);
            BundleComponent::query()->create(['listing_id' => $bundle, 'part_listing_id' => $partListing, 'variant_id' => $partVariant, 'quantity' => 1]);

            return [$bundle, BundleComponent::query()->count()];
        });
        $this->assertSame(1, $other[1]);

        // 止めてから消す（使っている間は核が断る）
        $purged = $this->inside('bnd', function (): array {
            app(PluginStates::class)->set('bundle', false, 'test');

            return app(PluginDataPurge::class)->purge('bundle', 'operator:test')->rows;
        });
        $this->assertSame(['bundle_components' => 1], $purged);
        $this->assertSame(0, $this->rows());

        // よその店の行は残っている（店の DB が別）
        $this->assertSame(1, $this->inside('bne', fn (): int => BundleComponent::query()->count()));
    }

    /**
     * セットの中身を決める（店の中で呼ぶ）
     */
    private function save(string $parts, ?string $bundleId = null): void
    {
        app(BundleComponents::class)->save(Listing::query()->findOrFail($bundleId ?? $this->bundle), $parts, 'operator:test');
    }

    /**
     * 断られること（店の中で呼ぶ）
     */
    private function refused(string $parts, string $because, ?string $bundleId = null): void
    {
        try {
            $this->save($parts, $bundleId);
            $this->fail("断られるはずの中身が通った（{$parts}）");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($because, implode('', $e->errors()['parts'] ?? []));
        }
    }

    /**
     * 構成の品の行の数（店の外から数える）
     */
    private function rows(): int
    {
        return $this->inside('bnd', fn (): int => BundleComponent::query()->count());
    }

    /**
     * 品を 1 つ作る（店の中で呼ぶ）。戻すのは [商品の ID, 種類の ID]
     *
     * @return array{0: string, 1: string}
     */
    private function product(string $key, string $title, string $sku, int $stock): array
    {
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 1800, 'status' => 'active']);
        $variant = ListingVariant::query()->where('listing_id', $listing->id)->firstOrFail();
        $variant->fill(['sku' => $sku])->save();

        if ($stock > 0) {
            app(StockService::class)->receive($listing, $this->location(), $stock);
        }

        $this->listings[$key] = $listing->id;
        $this->variants[$key] = $variant->id;

        return [$listing->id, $variant->id];
    }

    /**
     * 店の拠点（店の中で呼ぶ）
     */
    private function location(): Location
    {
        return Location::query()->orderBy('created_at')->firstOrFail();
    }

    /**
     * セットをカートに 1 点入れてみて、断られた文（入れられたら null）
     */
    private function cartRefusal(): ?string
    {
        return $this->inside('bnd', function (): ?string {
            $carts = app(CartService::class);
            [$cart] = $carts->create();

            try {
                $carts->add($cart, $this->bundleVariant, 1);

                return null;
            } catch (CartException $e) {
                return $e->getMessage();
            }
        });
    }

    /**
     * ショップの画面を開く（店の文脈に入ったまま戻るので閉じる）
     *
     * @return TestResponse<Response>
     */
    private function shop(string $path): TestResponse
    {
        $response = $this->get("http://bnd.localhost{$path}");
        tenancy()->end();

        return $response;
    }
}
