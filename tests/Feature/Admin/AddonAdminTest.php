<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\Seller;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面で商品のオプション（名入れ・ギフト包装など）を編集し、注文の詳細で入れた文字を見る（ADR 0036）。
 * 変えるのは核の AddonService。入れた文字は「個人情報を見る」入口（見た記録が残る）でだけ返す。
 */
final class AddonAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string NAME = '山田 花子';

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

    public function test_defining_addons_lists_them_in_the_detail(): void
    {
        $this->tenant('ada');
        [$this->operator] = $this->enrolledOperator();
        $listing = $this->create('ada', ['title' => 'マグ', 'unit_amount' => 2000]);
        $path = "/api/tenants/ada/listings/{$listing['id']}";
        $this->assertSame([], $listing['addons']);

        $defined = $this->send('PUT', "{$path}/addons", ['version' => 1, 'addons' => [
            ['kind' => 'choice', 'name' => 'ギフト包装', 'required' => true, 'amount' => 0, 'choices' => [['label' => 'なし', 'amount' => 0], ['label' => 'あり', 'amount' => 330]], 'max_length' => null],
            ['kind' => 'text', 'name' => '名入れ', 'required' => false, 'amount' => 500, 'choices' => null, 'max_length' => 10],
            ['kind' => 'toggle', 'name' => '保証の延長', 'amount' => 1000],
        ]])->assertOk()->assertJsonPath('listing.version', 2)->json('listing');

        $this->assertSame(['choice', 'text', 'toggle'], array_column($defined['addons'], 'kind'));
        $this->assertSame(['ギフト包装', '名入れ', '保証の延長'], array_column($defined['addons'], 'name'));
        $this->assertSame([true, false, false], array_column($defined['addons'], 'required'));
        $this->assertSame([['label' => 'なし', 'amount' => 0], ['label' => 'あり', 'amount' => 330]], $defined['addons'][0]['choices']);
        $this->assertSame([0, 500, 1000], array_column($defined['addons'], 'amount'));
        $this->assertSame([null, 10, null], array_column($defined['addons'], 'max_length'));

        // 読み直しても同じ。並べ替えて 1 つ消すと、並びがそのまま残る
        $this->send('GET', $path)->assertOk()->assertJsonPath('listing.addons.1.name', '名入れ');
        $this->send('PUT', "{$path}/addons", ['version' => 2, 'addons' => [
            ['kind' => 'toggle', 'name' => '保証の延長', 'amount' => 1200],
            ['kind' => 'text', 'name' => '名入れ', 'amount' => 500, 'max_length' => 10],
        ]])->assertOk()->assertJsonPath('listing.version', 3)
            ->assertJsonPath('listing.addons.0.name', '保証の延長')->assertJsonPath('listing.addons.0.amount', 1200)
            ->assertJsonPath('listing.addons.1.name', '名入れ')->assertJsonCount(2, 'listing.addons');

        // 空の並びでオプションを外せる。変えた人がイベントに残る
        $this->send('PUT', "{$path}/addons", ['version' => 3, 'addons' => []])->assertOk()->assertJsonPath('listing.addons', []);
        $this->inside('ada', fn () => $this->assertSame(
            ['operator:'.$this->operator->id],
            DomainEvent::query()->where('type', 'listing.addons_defined')->pluck('actor')->unique()->values()->all(),
        ));
    }

    public function test_a_stale_version_is_a_conflict_and_a_wrong_shape_is_an_input_error(): void
    {
        $this->tenant('adb');
        [$this->operator] = $this->enrolledOperator();
        $listing = $this->create('adb', ['title' => 'マグ', 'unit_amount' => 2000]);
        $path = "/api/tenants/adb/listings/{$listing['id']}";
        $wrap = [['kind' => 'toggle', 'name' => '包装', 'amount' => 330]];

        $this->send('PUT', "{$path}/addons", ['version' => 1, 'addons' => $wrap])->assertOk();
        // 古いバージョンは 409（先に保存した人が勝つ）
        $this->send('PUT', "{$path}/addons", ['version' => 1, 'addons' => []])->assertStatus(409)->assertJsonPath('current_version', 2);

        // 空の名前は受け取る前に 422。文は欄の名前（addons.0.name）でなく「オプション 1 の名前」
        $this->send('PUT', "{$path}/addons", ['version' => 2, 'addons' => [['kind' => 'toggle', 'name' => '', 'amount' => 0]]])
            ->assertStatus(422)->assertJsonValidationErrors(['addons.0.name' => 'オプション 1 の名前を入力してください。']);
        // 形の誤りは核の確かめがそのまま 422（どの行のどの欄か）
        $this->send('PUT', "{$path}/addons", ['version' => 2, 'addons' => [['kind' => 'choice', 'name' => '包装', 'choices' => []]]])
            ->assertStatus(422)->assertJsonValidationErrors('addons.0.choices');
        $this->send('PUT', "{$path}/addons", ['version' => 2, 'addons' => [['kind' => 'text', 'name' => '名入れ', 'amount' => 0, 'max_length' => 101]]])
            ->assertStatus(422)->assertJsonValidationErrors('addons.0.max_length');
        $this->send('PUT', "{$path}/addons", ['version' => 2, 'addons' => [$wrap[0], $wrap[0]]])
            ->assertStatus(422)->assertJsonValidationErrors('addons.1.name');
        $this->send('PUT', "{$path}/addons", ['version' => 2, 'addons' => [['kind' => 'toggle', 'name' => '包装', 'amount' => -1]]])
            ->assertStatus(422)->assertJsonValidationErrors('addons.0.amount');
        // 付けるは必須にできない（「付けない」も選び方）。DB の CHECK も断る
        $this->send('PUT', "{$path}/addons", ['version' => 2, 'addons' => [['kind' => 'toggle', 'name' => '包装', 'required' => true, 'amount' => 330]]])
            ->assertStatus(422)->assertJsonValidationErrors(['addons.0.required' => '「あり・なし」のオプションは必須にできません。']);
        $this->inside('adb', function () use ($listing): void {
            try {
                ListingAddon::query()->whereKey(ListingAddon::query()->where('listing_id', $listing['id'])->value('id'))->update(['required' => true]);
                $this->fail('付けるに必須を DB が断らなかった');
            } catch (QueryException $e) {
                $this->assertStringContainsString('listing_addons_toggle_not_required', $e->getMessage());
            }
        });
        // 11 個は受け取る前に断る
        $this->send('PUT', "{$path}/addons", ['version' => 2, 'addons' => array_map(static fn (int $i): array => ['kind' => 'toggle', 'name' => "o{$i}", 'amount' => 0], range(1, 11))])
            ->assertStatus(422)->assertJsonValidationErrors('addons');

        // どれも保存されていない
        $this->send('GET', $path)->assertJsonPath('listing.version', 2)->assertJsonPath('listing.addons.0.name', '包装')->assertJsonCount(1, 'listing.addons');
    }

    public function test_viewers_cannot_change_addons(): void
    {
        $this->tenant('adc');
        [$this->operator] = $this->enrolledOperator();
        $listing = $this->create('adc', ['title' => 'マグ', 'unit_amount' => 1200]);

        // オーナーはログアウトし、閲覧だけのスタッフでログインし直す
        $this->send('POST', '/auth/logout')->assertNoContent();
        [$staff] = $this->enrolledOperator('viewer@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'adc', 'read', 'test');

        $path = "/api/tenants/adc/listings/{$listing['id']}";
        $this->send('GET', $path)->assertOk()->assertJsonPath('listing.addons', []);
        $this->send('PUT', "{$path}/addons", ['version' => 1, 'addons' => [['kind' => 'toggle', 'name' => '包装', 'amount' => 330]]])->assertForbidden();

        $this->inside('adc', fn () => $this->assertSame(0, ListingAddon::query()->count()));
    }

    public function test_the_entered_text_is_returned_only_with_the_personal_data(): void
    {
        $this->tenant('add');
        [$this->operator] = $this->enrolledOperator();
        $baseline = (int) AuditEvent::query()->max('sequence');

        $sale = $this->inside('add', function (): string {
            $seller = Seller::query()->firstOrFail();
            $store = Location::query()->firstOrFail();
            $mug = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'マグ', 'unit_amount' => 2000, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($mug, $store, 5);

            return app(TransactionService::class)->open('sale', $seller, null, [
                LineDraft::fromListing($mug, 2, 1000, $store),
                new LineDraft(LineType::Adjustment, '名入れ（×2）', 1, 1000, 1000, 'addon', parentPosition: 1, addonText: self::NAME),
                new LineDraft(LineType::Adjustment, 'ギフト包装：あり（×2）', 1, 660, 1000, 'addon', parentPosition: 1),
            ], actor: 'system')->id;
        });
        $path = "/api/tenants/add/transactions/{$sale}";

        // 取引の詳細（明細の表）には入れた文字が出ない
        $detail = $this->send('GET', $path)->assertOk();
        $this->assertStringNotContainsString(self::NAME, (string) json_encode($detail->json(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(['マグ', '名入れ（×2）', 'ギフト包装：あり（×2）'], array_column($detail->json('transaction.lines'), 'description'));

        // 「個人情報を見る」では、従う商品の行の位置と一緒に返る（文字を持つ行だけ）
        $this->send('GET', "{$path}/personal")->assertOk()
            ->assertJsonCount(1, 'personal.addon_texts')
            ->assertJsonPath('personal.addon_texts.0.text', self::NAME)
            ->assertJsonPath('personal.addon_texts.0.parent_position', 1)
            ->assertJsonPath('personal.addon_texts.0.description', '名入れ（×2）');

        // 見た事実が残る（中身の文字は残さない）
        $viewed = AuditEvent::query()->where('sequence', '>', $baseline)->where('type', 'operator.personal_data_viewed')->get();
        $this->assertSame([$sale], $viewed->map(static fn (AuditEvent $e): mixed => $e->data['transaction_id'] ?? null)->all());
        $this->assertStringNotContainsString(self::NAME, (string) json_encode($viewed->pluck('data')->all(), JSON_UNESCAPED_UNICODE));
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
