<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Catalog\Models\Category;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Media\Models\ShopMedia;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Party\Models\Party;
use App\Core\Sample\Models\SampleMark;
use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 入れたときのサンプル。`shop:sample` で雑貨のお店の見本を入れ、管理画面のホームからまとめて消す。
 * 入れる: 写真つきの商品 8 点（種類とオプションの見本を含む）・カテゴリ・テーマの中身・特商法の表記・送料。お客さんと注文は入れない。流し直しても増えない。
 * 消す: オーナーだけ・直前の再確認・監査に残す。印の付いた物だけを消し、お店が足した物・注文に使われた商品・書き換えた物は残す
 */
final class ShopSamplesTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use OperatorSession;
    use WithTenants;

    private Filesystem $disk;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->disk = FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_the_samples_go_in_once_with_photos_variants_and_addons_but_no_customers_or_orders(): void
    {
        $this->tenant('ssa');
        $this->sample('ssa')->expectsOutputToContain('サンプルを入れました')->assertSuccessful();

        $this->inside('ssa', function (): void {
            $listings = Listing::query()->get();
            $this->assertCount(8, $listings);

            foreach ($listings as $listing) {
                $this->assertStringContainsString('（サンプル）', $listing->title);
                $this->assertSame(ListingStatus::Active, $listing->status);
                $this->assertSame('note', $listing->description_blocks[0]['type'], '説明の先頭にサンプルの知らせ');
                $this->assertTrue(ListingImage::query()->where('listing_id', $listing->id)->exists(), "{$listing->title} に写真");
            }

            $tote = Listing::query()->where('title', '帆布のトートバッグ（サンプル）')->sole();
            $this->assertSame(6, $tote->variants()->count());
            $this->assertSame(2, ListingAddon::query()->where('listing_id', $tote->id)->count());
            $this->assertSame(3, ListingImage::query()->where('listing_id', $tote->id)->count(), '色ごとの写真');
            $this->assertSame(['cloth', 'interior', 'kitchen'], Category::query()->orderBy('slug')->pluck('slug')->all());

            // テーマの中身（角砂糖）は公開まで済み、写真はショップの写真に
            $published = app(ThemeContentService::class)->published('default', app(ThemeRegistry::class)->get('default')->schema());
            $this->assertSame('SAMPLE ZAKKA SHOP', $published['hero']['values']['eyebrow']);
            $this->assertTrue(ShopMedia::query()->whereKey($published['hero']['values']['image'])->exists());
            $this->assertCount(3, ShopMedia::query()->get());
            $this->assertStringContainsString('（サンプル）', app(ShopSettingsService::class)->legal()[0]['value']);
            // 送料は「通常便」1 つを全国一律に（ADR 0064）
            $methods = app(ShippingMethods::class)->all();
            $this->assertSame([[800], 5000], [array_values(array_unique($methods[0]['rates'])), $methods[0]['free_over']]);

            // お客さんと注文は入れない。書き込みはすべて記録に残る
            $this->assertSame(0, Party::query()->count());
            $this->assertSame(0, Transaction::query()->count());
            $this->assertTrue(DomainEvent::query()->where('actor', 'cli:shop-sample')->where('type', 'listing.created')->exists());
            $this->assertSame(16, SampleMark::query()->active()->count(), '商品 8・カテゴリ 3・写真 3・テーマの中身・表記');
        });

        // ショップ（角砂糖）にそのまま出る
        $this->get('http://ssa.localhost/')->assertOk()->assertSee('SAMPLE ZAKKA SHOP')->assertSee('帆布のトートバッグ（サンプル）')->assertSee('data-test="hero-image"', false);

        // 流し直しても増えない
        $this->sample('ssa')->expectsOutputToContain('もう入っています')->assertSuccessful();
        $this->inside('ssa', function (): void {
            $this->assertSame(8, Listing::query()->count());
            $this->assertSame(3, ShopMedia::query()->count());
            $this->assertSame(3, Category::query()->count());
        });
    }

    /**
     * よくある失敗: もう商品のある店（動いているショップ）には、--force が無いと入れない
     */
    public function test_a_shop_with_its_own_products_is_refused_without_force(): void
    {
        $this->tenant('ssb');
        $this->inside('ssb', fn () => app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => '自分の商品', 'unit_amount' => 1000], 'test'));

        $this->sample('ssb')->expectsOutputToContain('--force')->assertFailed();
        $this->inside('ssb', fn () => $this->assertSame(['自分の商品'], Listing::query()->pluck('title')->all()));

        $this->sample('ssb', force: true)->assertSuccessful();
        $this->inside('ssb', fn () => $this->assertSame(9, Listing::query()->count()));
    }

    public function test_removing_takes_only_marked_samples_and_keeps_what_the_shop_added_ordered_or_changed(): void
    {
        $this->tenant('ssc');
        $this->sample('ssc')->assertSuccessful();
        [$owner, $authenticator] = $this->enrolledOperator();

        $kept = $this->inside('ssc', function (): array {
            $seller = Seller::query()->firstOrFail();
            $kitchen = Category::query()->where('slug', 'kitchen')->sole();
            // お店が足した商品（サンプルのカテゴリに入れた）とショップの写真
            $own = app(ListingService::class)->create($seller, 'product', ['title' => '自分のマグ', 'unit_amount' => 3000, 'status' => ListingStatus::Active], 'test');
            app(CategoryService::class)->assign($own, [$kitchen->id], null, 'test');
            $ownMedia = app(ShopMediaService::class)->add($this->file($this->jpeg(800, 600)), '自分の写真', 'test');
            // 注文に使われたサンプル（取引の行が指す）
            $coaster = Listing::query()->where('title', '麻のコースター 4 枚組（サンプル）')->sole();
            app(TransactionService::class)->open('sale', $seller, null, [LineDraft::fromListing($coaster, 1, 1000, Location::query()->firstOrFail())], actor: 'test');
            // お店が書き換えたサンプル
            $spoon = Listing::query()->where('title', '真鍮のティースプーン（サンプル）')->sole();
            app(ListingService::class)->update($spoon, $spoon->version, ['title' => 'うちの真鍮のスプーン'], 'test');
            // 消えるサンプルの写真のファイル
            $vase = Listing::query()->where('title', '一輪挿し（サンプル）')->sole();
            $vaseImage = ListingImage::query()->where('listing_id', $vase->id)->sole();

            return ['own' => $own->id, 'media' => $ownMedia->id, 'coaster' => $coaster->id, 'spoon' => $spoon->id, 'vase' => $vase->id, 'vase_image' => $vaseImage->id];
        });
        $this->assertTrue($this->disk->exists("tenants/ssc/listings/{$kept['vase']}/{$kept['vase_image']}-large.webp"));

        $this->send('GET', '/api/tenants/ssc/overview')->assertOk()->assertJsonPath('samples.listings', 8)->assertJsonPath('samples.content', true);
        // 直前の再確認が無ければ消さない
        $this->send('DELETE', '/api/tenants/ssc/samples')->assertStatus(423);
        $this->inside('ssc', fn () => $this->assertSame(9, Listing::query()->count()));

        $this->reconfirm($owner, $authenticator);
        $response = $this->send('DELETE', '/api/tenants/ssc/samples')->assertOk()->assertJsonPath('samples', null);
        $this->assertEqualsCanonicalizing([
            ['type' => 'listing', 'title' => '麻のコースター 4 枚組（サンプル）', 'reason' => 'ordered'],
            ['type' => 'listing', 'title' => 'うちの真鍮のスプーン', 'reason' => 'edited'],
            ['type' => 'category', 'title' => 'キッチン', 'reason' => 'in_use'],
        ], $response->json('kept'));
        // 消したのは商品 6・カテゴリ 2・写真 3・テーマの中身・表記
        $this->assertSame(13, $response->json('removed'));

        $this->inside('ssc', function () use ($kept, $owner): void {
            // 残る: お店の商品とその写真・注文に使われたサンプル・書き換えたサンプル・お店の商品が入っているカテゴリ
            $this->assertEqualsCanonicalizing([$kept['own'], $kept['coaster'], $kept['spoon']], Listing::query()->pluck('id')->all());
            $this->assertSame(['kitchen'], Category::query()->pluck('slug')->all());
            $this->assertSame([$kept['media']], ShopMedia::query()->pluck('id')->all());
            $this->assertSame(1, Transaction::query()->count());
            // 消えた: 印の付いたほかの商品（ゴミ箱へ）とその写真・テーマの中身・特商法の表記の見本。送料は残す
            $this->assertNotNull(Listing::withTrashed()->find($kept['vase'])?->deleted_at);
            $this->assertFalse(ListingImage::query()->where('listing_id', $kept['vase'])->exists());
            // 区画はどれも空（決まった並びのテーマなので、区画そのものは宣言の順に空の値で並ぶ）
            $after = app(ThemeContentService::class)->published('default', app(ThemeRegistry::class)->get('default')->schema());
            $this->assertSame([], array_filter(array_map(static fn (array $s): array => array_filter([...$s['values'], ...$s['items']], static fn (mixed $v): bool => $v !== null && $v !== []), $after)));
            $this->assertSame([], app(ShopSettingsService::class)->legal());
            $this->assertSame(800, app(ShippingMethods::class)->all()[0]['rates']['沖縄県']);
            $this->assertSame(0, SampleMark::query()->active()->count());
            // 記録には運営の actor と数だけ
            $event = DomainEvent::query()->where('type', 'shop.samples_removed')->sole();
            $this->assertSame($owner->actor(), $event->actor);
            $this->assertSame(['kept' => 3, 'removed' => 13], $event->payload);
        });
        $this->assertFalse($this->disk->exists("tenants/ssc/listings/{$kept['vase']}/{$kept['vase_image']}-large.webp"), '消した商品の写真のファイルも消える');

        $audit = AuditEvent::query()->where('type', 'tenant.samples_removed')->where('tenant_id', 'ssc')->sole();
        $this->assertSame([$owner->id, 'ssc', 13, 3], [$audit->operator_id, $audit->tenant_id, $audit->data['removed'], $audit->data['kept']]);

        // 2 回目は何もしない。流し直しても、消したサンプルは戻らない
        $this->send('DELETE', '/api/tenants/ssc/samples')->assertOk()->assertJsonPath('removed', 0);
        $this->sample('ssc', force: true)->expectsOutputToContain('もう入っています')->assertSuccessful();
        $this->inside('ssc', fn () => $this->assertSame(3, Listing::query()->count()));
    }

    public function test_samples_the_shop_reworked_stay_theme_content_legal_and_their_photos(): void
    {
        $this->tenant('ssd');
        $this->sample('ssd')->assertSuccessful();
        [$owner, $authenticator] = $this->enrolledOperator();

        $this->inside('ssd', function (): void {
            // お店がテーマの中身の見出しを変え、特商法の表記を書いた
            $content = app(ThemeContentService::class);
            $schema = app(ThemeRegistry::class)->get('default')->schema();
            $version = $content->current('default');
            $this->assertNotNull($version);
            $sections = $content->draft($version, $schema);
            $sections['hero']['values']['heading'] = 'うちの店';
            $content->saveDraft('default', $schema, $version->id, $version->revision, $sections, 'test');
            app(ShopSettingsService::class)->setLegal(['items' => [['label' => '販売業者', 'value' => '株式会社うちの店']]], 'test');
        });

        $this->reconfirm($owner, $authenticator);
        $response = $this->send('DELETE', '/api/tenants/ssd/samples')->assertOk();
        // テーマの中身が使う写真とカテゴリも残る
        $this->assertSame(['theme_version', 'shop_settings', 'shop_media', 'shop_media', 'shop_media', 'category', 'category', 'category'], array_column($response->json('kept'), 'type'));

        $this->inside('ssd', function (): void {
            $this->assertSame(0, Listing::query()->count(), '商品は消える');
            $this->assertSame(3, ShopMedia::query()->count(), 'テーマの中身が使う写真は残る');
            $this->assertSame(3, Category::query()->count(), 'テーマの中身が指すカテゴリは残る');
            $version = app(ThemeContentService::class)->current('default');
            $this->assertNotNull($version);
            $this->assertSame('うちの店', app(ThemeContentService::class)->draft($version, app(ThemeRegistry::class)->get('default')->schema())['hero']['values']['heading'] ?? null);
            $this->assertSame([['label' => '販売業者', 'value' => '株式会社うちの店']], app(ShopSettingsService::class)->legal());
        });
    }

    public function test_only_the_owner_can_remove_samples(): void
    {
        $this->tenant('sse');
        $this->sample('sse')->assertSuccessful();
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'sse', 'money', 'test');
        $this->reconfirm($staff, $authenticator);

        // スタッフは残っていることは見えるが、いちばん強い段階でも消せない
        $this->send('GET', '/api/tenants/sse/overview')->assertOk()->assertJsonPath('samples.listings', 8);
        $this->send('DELETE', '/api/tenants/sse/samples')->assertForbidden();
        $this->inside('sse', function (): void {
            $this->assertSame(8, Listing::query()->count());
            $this->assertSame(16, SampleMark::query()->active()->count());
        });
        $this->assertFalse(AuditEvent::query()->where('type', 'tenant.samples_removed')->where('tenant_id', 'sse')->exists());
    }

    private function sample(string $tenant, bool $force = false): PendingCommand
    {
        $command = $this->artisan('shop:sample', ['id' => $tenant] + ($force ? ['--force' => true] : []));
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sample-test-');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }
}
