<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Enums\LocationKind;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\SellerStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の商品と在庫。テナントの中は EnterTenant を通る（ADR 0014）。
 */
final class ListingAdminTest extends TestCase
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

    public function test_options_and_the_list_with_filters(): void
    {
        $this->tenant('loa');
        $this->signIn();
        [$sellerId, $locationId] = $this->inside('loa', fn (): array => [Seller::query()->value('id'), Location::query()->value('id')]);

        $this->send('GET', '/api/tenants/loa/listing-options')->assertOk()
            ->assertJsonPath('sellers.0.id', $sellerId)
            ->assertJsonPath('sellers.0.locations.0.id', $locationId)
            ->assertJsonPath('kinds.0.kind', 'product')
            ->assertJsonPath('statuses', ['draft', 'active', 'archived']);

        $this->create('loa', ['title' => '青いマグカップ', 'unit_amount' => 1200, 'sku' => 'MUG-BLUE', 'status' => 'active']);
        $this->create('loa', ['title' => '100% 綿のタオル', 'unit_amount' => 800, 'sku' => 'TOWEL-1']);
        $this->create('loa', ['title' => '赤いマグカップ', 'unit_amount' => 1200, 'sku' => 'MUG-RED']);

        $this->send('GET', '/api/tenants/loa/listings')->assertOk()->assertJsonCount(3, 'listings')->assertJsonPath('meta.total', 3);
        // ブラウザと同じく、日本語は % で符号化して送る
        // 同じ秒に作った行の並びは決まらないので、順番は問わない
        $this->assertEqualsCanonicalizing(['MUG-RED', 'MUG-BLUE'], $this->send('GET', '/api/tenants/loa/listings?q='.rawurlencode('マグ'))->json('listings.*.sku'));
        $this->assertSame(['MUG-BLUE'], $this->send('GET', '/api/tenants/loa/listings?status=active')->json('listings.*.sku'));
        $this->assertSame(['TOWEL-1'], $this->send('GET', '/api/tenants/loa/listings?q=towel')->json('listings.*.sku'));
        // LIKE の記号は文字として扱う（% で全件にならない）
        $this->assertSame(['TOWEL-1'], $this->send('GET', '/api/tenants/loa/listings?q=%25')->json('listings.*.sku'));
        $this->assertSame([], $this->send('GET', '/api/tenants/loa/listings?q=_')->json('listings'));

        // 状態ごとの件数（状態の絞り込みは効かせず、検索語は効かせる）
        $this->send('GET', '/api/tenants/loa/listings?status=active')->assertOk()
            ->assertJsonPath('counts', ['all' => 3, 'draft' => 2, 'active' => 1, 'archived' => 0]);
        $this->send('GET', '/api/tenants/loa/listings?q='.rawurlencode('マグ'))->assertJsonPath('counts.all', 2)->assertJsonPath('counts.draft', 1);

        // 並べ替え。在庫の無い商品は 0 と同じく少ない側に来る
        $this->inside('loa', function (): void {
            $stocks = app(StockService::class);
            $location = Location::query()->firstOrFail();
            $stocks->receive(Listing::query()->where('title', '赤いマグカップ')->firstOrFail(), $location, 9);
            $stocks->receive(Listing::query()->where('title', '100% 綿のタオル')->firstOrFail(), $location, 2);
        });
        $this->assertSame(['MUG-RED', 'TOWEL-1', 'MUG-BLUE'], $this->send('GET', '/api/tenants/loa/listings?sort=-stock')->json('listings.*.sku'));
        $this->assertSame(['MUG-BLUE', 'TOWEL-1', 'MUG-RED'], $this->send('GET', '/api/tenants/loa/listings?sort=stock')->json('listings.*.sku'));
        $this->assertSame('TOWEL-1', $this->send('GET', '/api/tenants/loa/listings?sort=unit_amount')->json('listings.0.sku'));
        $this->assertSame('TOWEL-1', $this->send('GET', '/api/tenants/loa/listings?sort=title')->json('listings.0.sku'));

        // 並べ替えは決まった候補だけ（列の名前や SQL を混ぜられない）
        foreach (['id', 'stock_quantity', '-', 'title;drop table listings', 'title desc'] as $sort) {
            $this->send('GET', '/api/tenants/loa/listings?sort='.rawurlencode($sort))->assertStatus(422)->assertJsonValidationErrors('sort');
        }

        $this->send('GET', '/api/tenants/loa/listings?per_page=101')->assertStatus(422);
        $this->send('GET', '/api/tenants/loa/listings?q='.str_repeat('a', 101))->assertStatus(422);
        $this->send('GET', '/api/tenants/loa/listings?status=deleted')->assertStatus(422);
        $this->send('GET', '/api/tenants/loa/listings?seller_id=1')->assertStatus(422);
    }

    public function test_broken_characters_are_refused_at_the_door_instead_of_crashing(): void
    {
        $this->tenant('lbr');
        $this->signIn();
        $sellerId = $this->inside('lbr', fn (): string => (string) Seller::query()->value('id'));

        // 不正な UTF-8 と NUL 文字。そのまま DB に届くと 500 になる
        $this->send('GET', '/api/tenants/lbr/listings?q=%E3%5F')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->send('GET', '/api/tenants/lbr/listings?q=a%00b')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->send('GET', '/api/tenants/lbr/listings?%FF=1')->assertStatus(422)->assertJsonValidationErrors('input');

        $base = ['seller_id' => $sellerId, 'kind' => 'product', 'unit_amount' => 100];
        // 末尾の NUL は Laravel の TrimStrings が空白と一緒に削るので、途中に入れる
        $this->send('POST', '/api/tenants/lbr/listings', $base + ['title' => "名\0前"])->assertStatus(422)->assertJsonValidationErrors('title');
        // 不正な UTF-8 は JSON に符号化できないので、生のまま送る（攻撃者はそうできる）。フォーム形式と、壊れたバイト入りの JSON
        $raw = fn (array $server, array $form = [], ?string $content = null) => $this->withCookie((string) config('admin.session_cookie'), (string) $this->sessionId)
            ->call('POST', 'http://admin.localhost/api/tenants/lbr/listings', $form, [], [], $server + ['HTTP_ACCEPT' => 'application/json'], $content);
        $raw([], $base + ['title' => 'ok', 'description' => "\xC3\x28"])->assertStatus(422)->assertJsonValidationErrors('description');
        $raw(['CONTENT_TYPE' => 'application/json'], [], '{"seller_id":"'.$sellerId.'","kind":"product","unit_amount":1,"title":"'."\xC3\x28".'"}')->assertStatus(422);
        $this->send('POST', '/api/tenants/lbr/listings', $base + ['title' => 'ok', 'nested' => [['deep' => "a\0b"]]])->assertStatus(422)->assertJsonValidationErrors('nested.0.deep');

        // 深すぎる入れ子も断る
        $deep = 'x';
        for ($i = 0; $i < 20; $i++) {
            $deep = [$deep];
        }
        $this->send('POST', '/api/tenants/lbr/listings', $base + ['title' => 'ok', 'deep' => $deep])->assertStatus(422);

        $this->inside('lbr', fn () => $this->assertSame(0, Listing::query()->count()));

        // 正しい日本語・絵文字は通る
        $this->send('POST', '/api/tenants/lbr/listings', $base + ['title' => '抹茶🍵セット', 'description' => "1 行目\n2 行目"])->assertCreated()
            ->assertJsonPath('listing.title', '抹茶🍵セット');
    }

    public function test_creating_validates_the_input_and_records_who_did_it(): void
    {
        $this->tenant('lcr');
        $this->signIn();
        $sellerId = $this->inside('lcr', fn (): string => (string) Seller::query()->value('id'));
        $base = ['seller_id' => $sellerId, 'kind' => 'product', 'title' => '商品', 'unit_amount' => 1000];

        $invalid = [
            'title' => ['title' => null],
            'title ' => ['title' => '   '],
            'title  ' => ['title' => str_repeat('あ', 201)],
            'unit_amount' => ['unit_amount' => -1],
            'unit_amount ' => ['unit_amount' => 1_000_000_000_001],
            'unit_amount  ' => ['unit_amount' => 1.5],
            'sku' => ['sku' => '../etc'],
            'sku ' => ['sku' => str_repeat('A', 65)],
            'kind' => ['kind' => 'weapon'],
            'status' => ['status' => 'deleted'],
            'seller_id' => ['seller_id' => 'not-a-ulid'],
            'seller_id ' => ['seller_id' => strtolower((string) Str::ulid())],
            'description' => ['description' => str_repeat('a', 5001)],
            // 固定価格の商品に金額が無い（核の規則）
            'listing' => ['unit_amount' => null],
        ];

        foreach ($invalid as $field => $override) {
            $this->send('POST', '/api/tenants/lcr/listings', array_merge($base, $override))
                ->assertStatus(422)->assertJsonValidationErrors(trim($field));
        }

        $this->inside('lcr', fn () => $this->assertSame(0, Listing::query()->count()));

        $created = $this->send('POST', '/api/tenants/lcr/listings', $base + ['sku' => 'A-1', 'description' => '説明'])->assertCreated();
        $created->assertJsonPath('listing.version', 1)->assertJsonPath('listing.status', 'draft')->assertJsonPath('listing.stocks.0.quantity', 0);

        // 同じ出品者で品番の重複は 422（500 にしない）
        $this->send('POST', '/api/tenants/lcr/listings', $base + ['sku' => 'A-1'])->assertStatus(422)->assertJsonValidationErrors('sku');

        $this->inside('lcr', function () use ($created): void {
            $event = DomainEvent::query()->where('type', 'listing.created')->sole();
            $this->assertSame('operator:'.$this->operator->id, $event->actor);
            $this->assertSame($created->json('listing.id'), $event->aggregate_id);
            $this->assertSame('商品', $event->payload['title'] ?? null);
        });
    }

    /**
     * 税率は商品ごとの区分（ADR 0063）。率そのものは受け取らず、区分から核が決める。誤った区分は欄の名前つきで 422
     */
    public function test_the_tax_class_is_chosen_per_product_and_the_rate_comes_from_the_core(): void
    {
        $this->tenant('ltx');
        $this->signIn();

        $this->send('GET', '/api/tenants/ltx/listing-options')->assertOk()->assertJsonPath('tax_classes', [
            ['value' => 'standard', 'label' => '標準 10%', 'rate_bp' => 1000],
            ['value' => 'reduced', 'label' => '軽減 8%（食品・飲み物）', 'rate_bp' => 800],
        ]);

        // 書かなければ標準。率（tax_rate_bp）を送っても使わない
        $tote = $this->create('ltx', ['title' => 'トート', 'unit_amount' => 1100, 'tax_rate_bp' => 0]);
        $this->assertSame(['standard', 1000], [$tote['tax_class'], $tote['tax_rate_bp']]);

        $cookie = $this->create('ltx', ['title' => 'クッキー', 'unit_amount' => 1080, 'tax_class' => 'reduced']);
        $this->assertSame(['reduced', 800], [$cookie['tax_class'], $cookie['tax_rate_bp']]);

        foreach (['zero', '8', 'REDUCED', ''] as $wrong) {
            $this->send('POST', '/api/tenants/ltx/listings', ['seller_id' => $cookie['seller_id'], 'kind' => 'product', 'title' => '誤り', 'unit_amount' => 100, 'tax_class' => $wrong])
                ->assertStatus(422)->assertJsonValidationErrors('tax_class');
            $this->send('PATCH', "/api/tenants/ltx/listings/{$tote['id']}", ['version' => 1, 'tax_class' => $wrong])
                ->assertStatus(422)->assertJsonValidationErrors(['tax_class' => '税率']);
        }

        // 直すと記録に前と後が残り、一覧にも率が出る
        $this->send('PATCH', "/api/tenants/ltx/listings/{$tote['id']}", ['version' => 1, 'tax_class' => 'reduced'])->assertOk()
            ->assertJsonPath('listing.tax_class', 'reduced')->assertJsonPath('listing.tax_rate_bp', 800)->assertJsonPath('listing.version', 2);
        $this->inside('ltx', function () use ($tote): void {
            $event = DomainEvent::query()->where('type', 'listing.updated')->where('aggregate_id', $tote['id'])->sole();
            $this->assertEqualsCanonicalizing(['from' => 'standard', 'to' => 'reduced'], $event->payload['changes']['tax_class']);
            $this->assertSame('standard', DomainEvent::query()->where('type', 'listing.created')->where('aggregate_id', $tote['id'])->sole()->payload['tax_class']);
        });
        $this->assertSame([800, 800], array_column($this->send('GET', '/api/tenants/ltx/listings')->assertOk()->json('listings'), 'tax_rate_bp'));
    }

    public function test_editing_rejects_the_later_save_when_two_people_edit_at_once(): void
    {
        $this->tenant('led');
        $this->signIn();
        $listing = $this->create('led', ['title' => '元の名前', 'unit_amount' => 1000]);
        $path = "/api/tenants/led/listings/{$listing['id']}";

        // A と B が同じバージョン 1 を読んで編集する。先に保存した A が通り、B は 409
        $this->send('PATCH', $path, ['version' => 1, 'title' => 'A の名前', 'unit_amount' => 1100])->assertOk()->assertJsonPath('listing.version', 2);
        $this->send('PATCH', $path, ['version' => 1, 'title' => 'B の名前'])->assertStatus(409)->assertJsonPath('current_version', 2);

        $this->send('GET', $path)->assertOk()->assertJsonPath('listing.title', 'A の名前')->assertJsonPath('listing.unit_amount', 1100);

        // 読み直したバージョンなら通る。何も変えない保存はバージョンを進めない
        $this->send('PATCH', $path, ['version' => 2, 'title' => 'B の名前', 'status' => 'archived'])->assertOk()->assertJsonPath('listing.version', 3);
        $this->send('PATCH', $path, ['version' => 3, 'title' => 'B の名前'])->assertOk()->assertJsonPath('listing.version', 3);

        // バージョンが無い・出品者や種類は変えられない（送っても無視される）
        $this->send('PATCH', $path, ['title' => 'x'])->assertStatus(422)->assertJsonValidationErrors('version');
        $this->send('PATCH', $path, ['version' => 3, 'kind' => 'service', 'seller_id' => strtolower((string) Str::ulid()), 'currency' => 'USD'])->assertOk()
            ->assertJsonPath('listing.kind', 'product')->assertJsonPath('listing.seller_id', $listing['seller_id'])->assertJsonPath('listing.currency', 'JPY');

        $this->inside('led', function (): void {
            $updates = DomainEvent::query()->where('type', 'listing.updated')->orderBy('sequence')->get();
            $this->assertCount(2, $updates);
            // jsonb はキーを並べ替えて持つので、順番は問わずに比べる
            $this->assertEquals(['from' => '元の名前', 'to' => 'A の名前'], $updates[0]->payload['changes']['title'] ?? null);
            $this->assertEquals(['from' => 'draft', 'to' => 'archived'], $updates[1]->payload['changes']['status'] ?? null);
            $this->assertSame(['operator:'.$this->operator->id], $updates->pluck('actor')->unique()->values()->all());
        });
    }

    public function test_stock_is_received_and_adjusted_but_never_goes_negative(): void
    {
        $this->tenant('lst');
        $this->signIn();
        $listing = $this->create('lst', ['title' => '在庫の商品', 'unit_amount' => 500]);
        $locationId = $listing['stocks'][0]['location_id'];
        $base = "/api/tenants/lst/listings/{$listing['id']}/stock";

        $this->send('POST', "{$base}/receive", ['location_id' => $locationId, 'quantity' => 5])->assertOk()
            ->assertJsonPath('listing.stocks.0.quantity', 5)->assertJsonPath('listing.stocks.0.available', 5);
        $this->send('POST', "{$base}/adjust", ['location_id' => $locationId, 'delta' => -3, 'reason' => 'stocktake'])->assertOk()
            ->assertJsonPath('listing.stocks.0.quantity', 2);

        // 負にはならない。取引で確保済みの数も下回れない
        $this->send('POST', "{$base}/adjust", ['location_id' => $locationId, 'delta' => -3, 'reason' => 'loss'])->assertStatus(422)->assertJsonValidationErrors('delta');
        $this->inside('lst', fn () => app(StockService::class)->reserve(Listing::query()->findOrFail($listing['id']), Location::query()->findOrFail($locationId), 2));
        $this->send('POST', "{$base}/adjust", ['location_id' => $locationId, 'delta' => -1, 'reason' => 'damage'])->assertStatus(422)->assertJsonValidationErrors('delta');

        foreach ([
            ['receive', ['location_id' => $locationId, 'quantity' => 0], 'quantity'],
            ['receive', ['location_id' => $locationId, 'quantity' => 1_000_001], 'quantity'],
            ['receive', ['location_id' => $locationId, 'quantity' => -1], 'quantity'],
            ['receive', ['quantity' => 1], 'location_id'],
            ['adjust', ['location_id' => $locationId, 'delta' => 0, 'reason' => 'other'], 'delta'],
            ['adjust', ['location_id' => $locationId, 'delta' => 1], 'reason'],
            ['adjust', ['location_id' => $locationId, 'delta' => 1, 'reason' => '気分'], 'reason'],
        ] as [$action, $body, $field]) {
            $this->send('POST', "{$base}/{$action}", $body)->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->inside('lst', function () use ($listing): void {
            $stock = InventoryStock::query()->where('listing_id', $listing['id'])->sole();
            $this->assertSame([2, 2], [$stock->quantity, $stock->reserved]);

            $events = DomainEvent::query()->whereIn('type', ['inventory.received', 'inventory.adjusted'])->orderBy('sequence')->get();
            $this->assertSame(['inventory.received', 'inventory.adjusted'], $events->pluck('type')->all());
            $this->assertSame(['delta' => -3, 'reason' => 'stocktake'], array_intersect_key($events[1]->payload, ['delta' => 0, 'reason' => 0]));
            $this->assertSame(2, $events[1]->payload['quantity_after'] ?? null);
            $this->assertSame('operator:'.$this->operator->id, $events[1]->actor);

            // アプリを通さずに書いても、DB が負の在庫・確保数の超過を拒む
            foreach (['quantity = -1, reserved = 0', 'quantity = 1, reserved = 2'] as $set) {
                $this->assertRejectedByDatabase(fn () => DB::statement("UPDATE inventory_stocks SET {$set} WHERE id = ?", [$stock->id]));
            }

            $this->assertRejectedByDatabase(fn () => DB::statement('UPDATE listings SET unit_amount = -1'));
        });
    }

    public function test_other_tenants_and_other_sellers_are_out_of_reach(): void
    {
        $this->tenant('lba');
        $this->tenant('lbb');
        $this->signIn();
        $listing = $this->create('lba', ['title' => 'A の商品', 'unit_amount' => 1000]);

        // テナント B の経路から A の出品物は「無い」
        foreach ([
            ['GET', "/api/tenants/lbb/listings/{$listing['id']}", []],
            ['PATCH', "/api/tenants/lbb/listings/{$listing['id']}", ['version' => 1, 'title' => '乗っ取り']],
            ['POST', "/api/tenants/lbb/listings/{$listing['id']}/stock/receive", ['location_id' => $listing['stocks'][0]['location_id'], 'quantity' => 1]],
        ] as [$method, $path, $body]) {
            $this->send($method, $path, $body)->assertNotFound();
        }

        $this->send('GET', '/api/tenants/lbb/listings')->assertOk()->assertJsonCount(0, 'listings');
        $this->send('GET', '/api/tenants/lba/listings/'.strtolower((string) Str::ulid()))->assertNotFound();
        $this->send('GET', '/api/tenants/lba/listings/not-a-ulid')->assertNotFound();

        // 同じテナントの別の出品者の拠点には置けない（API でも DB でも）
        $otherLocationId = $this->inside('lba', function (): string {
            $other = Seller::create(['name' => '別の販売者', 'slug' => 'other', 'status' => SellerStatus::Active]);

            return Location::create(['seller_id' => $other->id, 'kind' => LocationKind::Warehouse, 'name' => '別の倉庫'])->id;
        });

        $this->send('POST', "/api/tenants/lba/listings/{$listing['id']}/stock/receive", ['location_id' => $otherLocationId, 'quantity' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('location_id');

        $this->inside('lba', function () use ($listing, $otherLocationId): void {
            $this->assertSame(0, InventoryStock::query()->count());
            $this->assertRejectedByDatabase(fn () => InventoryStock::create(['listing_id' => $listing['id'], 'location_id' => $otherLocationId, 'quantity' => 1, 'reserved' => 0]));
            $this->assertSame(ListingStatus::Draft, Listing::query()->findOrFail($listing['id'])->status);
        });

        $this->inside('lbb', fn () => $this->assertSame(0, Listing::query()->count()));
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

    private function signIn(): void
    {
        [$this->operator] = $this->enrolledOperator();
    }

    private function assertRejectedByDatabase(Closure $write): void
    {
        try {
            DB::transaction(static fn () => $write());
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode(), $e->getMessage()); // check_violation

            return;
        }

        $this->fail('DB が書き込みを拒まなかった');
    }
}
