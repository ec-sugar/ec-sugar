<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Models\OrderField;
use App\Core\Order\Services\OrderFields;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 設定 › 注文の項目（ADR 0066）: 読むのは閲覧から・足す・直す・消す・並べ替えるは運用から。形の誤り・上限（使う欄 10 個）は 422、
 * 注文に使われた欄は消せない（「使わない」にはできる）。変えたことは店の記録（actor つき）と中央の監査ログに残る。店をまたがない。
 * 注文の詳細では選ぶ・チェックの値だけを出し、自由な文は個人情報の入口（見た記録が残る）でだけ返す
 */
final class OrderFieldAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string API = '/api/tenants/ofa/order-fields';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_read_level_reads_operate_level_changes_and_changes_are_recorded(): void
    {
        $this->tenant('ofa');
        $this->tenant('ofb');
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'ofa', 'read', 'test');

        $this->send('GET', self::API)->assertOk()->assertJsonPath('fields', [])
            ->assertJsonPath('limits.enabled', 10)->assertJsonPath('types.0', ['value' => 'text', 'label' => '1 行のテキスト', 'max_length' => 200]);
        $this->send('POST', self::API, self::noshi())->assertForbidden();
        // ほかの店（権限の無い店）は見えない
        $this->send('GET', '/api/tenants/ofb/order-fields')->assertNotFound();

        $access->grant($staff, 'ofa', 'operate', 'test');
        $created = $this->send('POST', self::API, [...self::noshi(), 'name' => '  のし  ', 'options' => [' お祝い ', '内祝い']])->assertCreated()
            ->assertJsonPath('fields.0.name', 'のし')->assertJsonPath('fields.0.options', ['お祝い', '内祝い'])->assertJsonPath('fields.0.used', false)
            ->json('fields.0.id');
        $wish = $this->send('POST', self::API, ['name' => 'ご要望', 'type' => 'textarea', 'options' => ['見ない'], 'required' => false, 'enabled' => true])->assertCreated()
            // 選ぶ欄でなければ選択肢は持たない
            ->assertJsonPath('fields.1.options', [])->json('fields.1.id');
        $this->send('PUT', self::API.'/'.$created, [...self::noshi(), 'description' => '包み方を合わせます', 'required' => true])->assertOk()
            ->assertJsonPath('fields.0.required', true)->assertJsonPath('fields.0.description', '包み方を合わせます');
        // 何も変えない保存・並べ替えは、店の記録にも監査ログにも残さない
        $this->send('PUT', self::API.'/'.$created, [...self::noshi(), 'description' => '包み方を合わせます', 'required' => true])->assertOk();
        $this->send('POST', self::API.'/order', ['ids' => [$wish, $created]])->assertOk()->assertJsonPath('fields.0.id', $wish);
        $this->send('POST', self::API.'/order', ['ids' => [$wish, $created]])->assertOk();
        $this->send('DELETE', self::API.'/'.$wish)->assertOk()->assertJsonCount(1, 'fields');

        $this->inside('ofa', function () use ($staff): void {
            $types = DomainEvent::query()->where('aggregate_type', 'order_field')->orderBy('sequence')->get();
            $this->assertSame(['order_field.created', 'order_field.created', 'order_field.updated', 'order_field.reordered', 'order_field.deleted'], $types->pluck('type')->all());
            $this->assertSame(['operator:'.$staff->id], $types->pluck('actor')->unique()->values()->all());
        });
        $this->assertSame(['created', 'created', 'updated', 'reordered', 'deleted'],
            AuditEvent::query()->where('type', 'operator.order_field_changed')->where('tenant_id', 'ofa')->orderBy('sequence')->get()->map(static fn (AuditEvent $e): string => $e->data['action'])->all());

        // ほかの店の欄の ID には届かない
        $this->inside('ofb', fn () => app(OrderFields::class)->create(self::noshi(), 'test'));
        $other = $this->inside('ofb', fn () => OrderField::query()->value('id'));
        $this->send('PUT', self::API.'/'.$other, self::noshi())->assertNotFound();
        $this->send('DELETE', self::API.'/'.$other)->assertNotFound();
        $this->inside('ofb', fn () => $this->assertSame(1, OrderField::query()->count()));
    }

    public function test_malformed_definitions_are_refused(): void
    {
        $this->tenant('ofa');
        $this->enrolledOperator();

        $refused = [
            'name' => ['name' => ''],
            'name.' => ['name' => str_repeat('あ', 41)],
            'name..' => ['name' => "のし\nあり"],
            'description' => ['description' => str_repeat('あ', 101)],
            'type' => ['type' => 'date'],
            'options' => ['options' => []],
            'options.' => ['options' => array_map(static fn (int $i): string => "選択肢{$i}", range(1, 21))],
            'options.0' => ['options' => [str_repeat('あ', 41)]],
            'options.1' => ['options' => ['お祝い', 'お祝い']],
            'required' => ['required' => 'たぶん'],
            'enabled' => ['enabled' => null],
            'name...' => ['name' => "の\u{200B}し"],             // 幅の無い文字
            'description.' => ['description' => "包み\u{2028}方"],  // 行の区切り
            'options.0.' => ['options' => ["お\u{FEFF}祝い"]],
        ];

        foreach ($refused as $field => $values) {
            $this->send('POST', self::API, [...self::noshi(), ...$values])->assertStatus(422)->assertJsonValidationErrors(rtrim($field, '.'));
        }

        // 境界はちょうどなら通る
        $this->send('POST', self::API, [...self::noshi(), 'name' => str_repeat('あ', 40), 'description' => str_repeat('い', 100),
            'options' => array_map(static fn (int $i): string => str_pad((string) $i, 40, 'x'), range(1, 20))])->assertCreated();
        $this->inside('ofa', fn () => $this->assertSame(1, OrderField::query()->count()));
    }

    public function test_positions_near_the_limit_are_packed_before_adding(): void
    {
        $this->tenant('ofa');
        $this->enrolledOperator();
        $first = $this->send('POST', self::API, [...self::noshi(), 'name' => '欄1'])->assertCreated()->json('fields.0.id');
        $second = $this->send('POST', self::API, [...self::noshi(), 'name' => '欄2'])->assertCreated()->json('fields.1.id');
        // 足して消すを繰り返した店（並びが上限の 1000 に届いている）
        $this->inside('ofa', function () use ($first, $second): void {
            DB::table('order_fields')->where('id', $first)->update(['position' => 999]);
            DB::table('order_fields')->where('id', $second)->update(['position' => 1000]);
        });

        // 足すときに詰め直す（500 にしない）。並びの順は変えない
        $this->send('POST', self::API, [...self::noshi(), 'name' => '欄3'])->assertCreated()
            ->assertJsonPath('fields.0.name', '欄1')->assertJsonPath('fields.1.name', '欄2')->assertJsonPath('fields.2.name', '欄3');
        $this->inside('ofa', fn () => $this->assertSame([0, 1, 2], OrderField::query()->orderBy('position')->pluck('position')->all()));
        // 上限の手前（1000 に 1 つ空き）なら、詰めずにそのまま足す
        $this->inside('ofa', fn () => DB::table('order_fields')->where('name', '欄3')->update(['position' => 999]));
        $this->send('POST', self::API, [...self::noshi(), 'name' => '欄4'])->assertCreated()->assertJsonPath('fields.3.name', '欄4');
        $this->inside('ofa', fn () => $this->assertSame([0, 1, 999, 1000], OrderField::query()->orderBy('position')->pluck('position')->all()));
    }

    public function test_ten_fields_can_be_in_use_at_once_and_used_fields_cannot_be_deleted(): void
    {
        $this->tenant('ofa');
        $this->enrolledOperator();

        $ids = [];
        for ($i = 1; $i <= 10; $i++) {
            $ids[] = $this->send('POST', self::API, [...self::noshi(), 'name' => "欄{$i}"])->assertCreated()->json('fields.'.($i - 1).'.id');
        }

        // 11 個目は使う欄にできない（使わない欄としてなら足せる。使うに変えると断る）
        $this->send('POST', self::API, [...self::noshi(), 'name' => '欄11'])->assertStatus(422)->assertJsonValidationErrors('enabled');
        $eleventh = $this->send('POST', self::API, [...self::noshi(), 'name' => '欄11', 'enabled' => false])->assertCreated()->json('fields.10.id');
        $this->send('PUT', self::API.'/'.$eleventh, [...self::noshi(), 'name' => '欄11'])->assertStatus(422)->assertJsonValidationErrors('enabled');
        // 1 つ使わないにすれば使える
        $this->send('PUT', self::API.'/'.$ids[0], [...self::noshi(), 'name' => '欄1', 'enabled' => false])->assertOk();
        $this->send('PUT', self::API.'/'.$eleventh, [...self::noshi(), 'name' => '欄11'])->assertOk();

        $this->inside('ofa', function () use ($ids): void {
            // DB も 11 個目を断る（アプリの検査をすり抜けても）
            try {
                DB::transaction(static fn () => DB::table('order_fields')->where('id', $ids[0])->update(['enabled' => true]));
                $this->fail('DB が 11 個目を通した');
            } catch (QueryException $e) {
                $this->assertStringContainsString('order_fields_enabled_limit', $e->getMessage());
            }
            // 選ぶ欄でない欄に選択肢は持たせない（選択肢のある欄を 1 行の文にする）
            try {
                DB::transaction(static fn () => DB::table('order_fields')->where('id', $ids[1])->update(['type' => 'text']));
                $this->fail('DB が選択肢を通した');
            } catch (QueryException $e) {
                $this->assertStringContainsString('order_fields_options_shape', $e->getMessage());
            }
        });

        // 注文に使われた欄は消せない（使わないにはできる）。使われていない欄は消せる
        $this->order('ofa', [$ids[1] => 'はい']);
        $this->send('GET', self::API)->assertOk()->assertJsonPath('fields.1.used', true)->assertJsonPath('fields.2.used', false);
        $this->send('DELETE', self::API.'/'.$ids[1])->assertStatus(422)->assertJsonValidationErrors('order_field');
        $this->send('PUT', self::API.'/'.$ids[1], [...self::noshi(), 'name' => '欄2', 'enabled' => false])->assertOk();
        $this->send('DELETE', self::API.'/'.$ids[2])->assertOk();
        // 並びは全部の欄の ID で（足りない・知らない ID は断る）
        $this->send('POST', self::API.'/order', ['ids' => [$ids[1]]])->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->send('POST', self::API.'/order', ['ids' => 'x'])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_the_order_page_shows_choices_and_the_personal_view_shows_free_text(): void
    {
        $this->tenant('ofa');
        [$owner] = $this->enrolledOperator();
        $transaction = $this->inside('ofa', function (): string {
            $fields = app(OrderFields::class);
            $use = $fields->create(['name' => 'ご用途', 'type' => 'select', 'options' => ['お祝い'], 'required' => true, 'enabled' => true], 'test')->id;
            $wish = $fields->create(['name' => 'ご要望', 'type' => 'textarea', 'required' => false, 'enabled' => true], 'test')->id;

            return $this->placeWith([$use => 'お祝い', $wish => '山田様へ<b>直接</b>']);
        });

        $this->send('GET', "/api/tenants/ofa/transactions/{$transaction}")->assertOk()
            ->assertJsonPath('transaction.order_fields', [
                ['name' => 'ご用途', 'type' => 'select', 'value' => 'お祝い', 'private' => false],
                ['name' => 'ご要望', 'type' => 'textarea', 'value' => null, 'private' => true],
            ]);
        $this->assertStringNotContainsString('直接', (string) $this->send('GET', "/api/tenants/ofa/transactions/{$transaction}")->getContent());
        $this->send('GET', "/api/tenants/ofa/transactions/{$transaction}/personal")->assertOk()
            ->assertJsonPath('personal.order_fields', [['name' => 'ご要望', 'value' => '山田様へ<b>直接</b>']]);
        $this->assertTrue(AuditEvent::query()->where('type', 'operator.personal_data_viewed')->where('operator_id', $owner->id)->exists());

        // 閲覧の人は注文の詳細（選ぶ・チェック）を見られるが、自由な文（個人情報の入口）は見られない
        $this->send('POST', '/auth/logout')->assertSuccessful();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'ofa', 'read', 'test');
        $this->send('GET', "/api/tenants/ofa/transactions/{$transaction}")->assertOk()->assertJsonPath('transaction.order_fields.0.value', 'お祝い');
        $this->send('GET', "/api/tenants/ofa/transactions/{$transaction}/personal")->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private static function noshi(): array
    {
        return ['name' => 'のし', 'type' => 'select', 'options' => ['お祝い', '内祝い'], 'required' => false, 'enabled' => true];
    }

    /**
     * 注文を 1 つ作り、注文の項目の値を残す（注文の確定と同じく、連鎖のロックの中で）
     *
     * @param  array<string, string>  $answers
     */
    private function order(string $shop, array $answers): string
    {
        return $this->inside($shop, fn (): string => $this->placeWith($answers));
    }

    /**
     * @param  array<string, string>  $answers
     */
    private function placeWith(array $answers): string
    {
        return DB::transaction(function () use ($answers): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 5);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 4400, $location)], actor: 'test');
            app(OrderFields::class)->record($sale->id, $answers, 'storefront');

            return $sale->id;
        });
    }
}
