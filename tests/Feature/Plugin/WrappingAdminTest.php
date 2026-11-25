<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\OrderCharges;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Plugin\OrderCharge\ChargeCart;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use EcSugar\PluginWrapping\Models\WrappingListing;
use EcSugar\PluginWrapping\Models\WrappingType;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「ラッピング・のし」の管理画面（ADR 0078）: App の画面でラッピングの種類を足す・直す・消す、商品の画面の欄で「ラッピングできる」「のしを付けられる」。
 * 変えるのは運用の段階から（閲覧の人は見るだけ）、消すのは直前の再確認の後。ほかの店の種類と商品は読めず変えられない。
 * 名入れは注文の画面の「個人情報」（運用から・見た記録が残る）でだけ見え、注文の詳細には出ない
 */
final class WrappingAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BASE = '/api/tenants/wpa/apps/wrapping/records';

    private string $knit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->configureAdminHost();
        $this->tenant('wpa');
        $this->tenant('wpb');
        $this->knit = $this->inside('wpa', function (): string {
            app(PluginStates::class)->set('wrapping', true, 'test');

            return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active'])->id;
        });
        $this->inside('wpb', fn () => app(PluginStates::class)->set('wrapping', true, 'test'));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_owner_adds_types_and_marks_products_and_changes_are_recorded(): void
    {
        [$owner, $key] = $this->enrolledOperator();

        // 名前と料金の形（料金は 0〜100,000 の円。< と > を断る）
        $this->send('POST', self::BASE, ['values' => ['name' => 'リボン', 'price' => '-1', 'active' => true]])->assertUnprocessable()->assertJsonValidationErrors(['values.price']);
        $this->send('POST', self::BASE, ['values' => ['name' => 'リボン', 'price' => '100001', 'active' => true]])->assertUnprocessable()->assertJsonValidationErrors(['values.price']);
        $this->send('POST', self::BASE, ['values' => ['name' => 'リボン', 'price' => '1.5', 'active' => true]])->assertUnprocessable()->assertJsonValidationErrors(['values.price']);
        $this->send('POST', self::BASE, ['values' => ['name' => '<b>箱</b>', 'price' => '300', 'active' => true]])->assertUnprocessable()->assertJsonValidationErrors(['values.name']);

        $id = (string) $this->send('POST', self::BASE, ['values' => ['name' => 'リボン', 'price' => '330', 'active' => true]])->assertCreated()->json('id');
        $this->assertNotSame('', $id);
        $this->send('GET', self::BASE)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('rows.0.cells.price', 330);
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['name' => 'リボン掛け', 'price' => '440', 'active' => true]])->assertOk();

        // 商品の画面の欄
        $panel = "/api/tenants/wpa/listings/{$this->knit}/apps/wrapping";
        $this->send('PUT', $panel, ['values' => ['wrappable' => true, 'noshi' => true]])->assertOk()->assertJsonPath('app.values.wrappable', true)->assertJsonPath('app.values.noshi', true);
        $this->assertSame([true, true], $this->inside('wpa', fn (): array => [WrappingListing::query()->findOrFail($this->knit)->wrappable, WrappingListing::query()->findOrFail($this->knit)->noshi]));
        $this->send('PUT', $panel, ['values' => ['wrappable' => false, 'noshi' => false]])->assertOk();

        $this->inside('wpa', function () use ($id, $owner): void {
            $this->assertSame(['リボン掛け', 440], [WrappingType::query()->findOrFail($id)->name, WrappingType::query()->findOrFail($id)->price]);
            $this->assertSame(0, WrappingListing::query()->count());
            $types = DomainEvent::query()->where('type', 'like', 'wrapping.%')->orderBy('sequence')->get();
            $this->assertSame(['wrapping.type_created', 'wrapping.type_updated', 'wrapping.listing_updated', 'wrapping.listing_updated'], $types->pluck('type')->all());
            $this->assertSame(['operator:'.$owner->id], $types->pluck('actor')->unique()->values()->all());
        });

        // 消すのは直前の再確認の後
        $this->send('POST', self::BASE.'/'.$id.'/actions/delete')->assertStatus(423);
        $this->reconfirm($owner, $key);
        $this->send('POST', self::BASE.'/'.$id.'/actions/delete')->assertOk();
        $this->assertSame(0, $this->inside('wpa', fn (): int => WrappingType::query()->count()));
    }

    public function test_read_level_only_reads_and_other_shops_cannot_be_reached(): void
    {
        $id = $this->inside('wpa', fn (): string => (string) WrappingType::query()->create(['name' => 'リボン', 'price' => 330, 'active' => true, 'position' => 1])->id);
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'wpa', 'read', 'test');

        $this->send('GET', self::BASE)->assertOk()->assertJsonPath('meta.total', 1);
        $this->send('POST', self::BASE, ['values' => ['name' => '箱', 'price' => '0', 'active' => true]])->assertForbidden();
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['name' => 'x', 'price' => '0', 'active' => true]])->assertForbidden();
        $this->send('PUT', "/api/tenants/wpa/listings/{$this->knit}/apps/wrapping", ['values' => ['wrappable' => true, 'noshi' => false]])->assertForbidden();

        // 入っていない店（wpb）からは、wpa の種類も商品も見えない
        $this->send('GET', "/api/tenants/wpb/apps/wrapping/records/{$id}")->assertNotFound();
        $this->send('PUT', "/api/tenants/wpb/apps/wrapping/records/{$id}", ['values' => ['name' => 'x', 'price' => '0', 'active' => true]])->assertNotFound();
    }

    public function test_the_name_on_the_noshi_is_seen_only_through_the_personal_view(): void
    {
        [$owner] = $this->enrolledOperator();
        $sale = $this->inside('wpa', function (): string {
            app(PluginSettings::class)->update('wrapping', ['noshi' => true, 'noshi_name' => true], 'test');
            WrappingListing::query()->create(['listing_id' => $this->knit, 'wrappable' => false, 'noshi' => true]);

            return DB::transaction(function (): string {
                app(EventStore::class)->lock();
                $listing = Listing::query()->findOrFail($this->knit);
                $location = Location::query()->firstOrFail();
                app(StockService::class)->receive($listing, $location, 5);
                $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']);
                $sale = app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), $customer,
                    [LineDraft::fromListing($listing, 1, 1000, $location), LineDraft::adjustment('noshi', 'のし（御祝・外のし・名入れあり）', 0, 1000)], actor: 'test');
                app(OrderCharges::class)->record($sale->id, new ChargeCart([['listingId' => $this->knit, 'title' => 'ニット', 'quantity' => 1]]), ['noshi' => '御祝', 'noshi_name' => '鈴木一郎'], 'storefront');

                return $sale->id;
            });
        });

        // 注文の詳細: 行の名前は「名入れあり」だけで、名入れの文字は出ない
        $detail = $this->send('GET', "/api/tenants/wpa/transactions/{$sale}")->assertOk();
        $this->assertStringNotContainsString('鈴木', json_encode($detail->json(), JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertContains('のし（御祝・外のし・名入れあり）', array_column($detail->json('transaction.lines'), 'description'));

        // 個人情報の入口: 名入れの文字まで出し、見た記録を残す
        $this->send('GET', "/api/tenants/wpa/transactions/{$sale}/personal")->assertOk()
            ->assertJsonPath('personal.charge_notes', [['name' => 'のし', 'value' => '御祝・外のし・名入れ 鈴木一郎']]);
        $this->assertTrue(AuditEvent::query()->where('type', 'operator.personal_data_viewed')->where('operator_id', $owner->id)->exists());

        // 閲覧の人は個人情報の入口を開けない
        $this->send('POST', '/auth/logout')->assertSuccessful();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'wpa', 'read', 'test');
        $this->send('GET', "/api/tenants/wpa/transactions/{$sale}/personal")->assertForbidden();
    }
}
