<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Discount\Models\Coupon;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面のクーポン（ADR 0054 の区切り 2）。見るのは閲覧、作る・直す・止める・再開は運用。
 * 値の幅は入口（CouponRequest）、コードの重なり・対象の ID・使われた後に変えない欄はサービスが錠の中で確かめる。
 * 変えたことは店のイベント（actor つき）と中央の監査ログに残る
 */
final class CouponAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private Operator $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->tenant('cpa');
        $this->tenant('cpb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_coupons_are_created_listed_and_shown_with_their_targets(): void
    {
        [$this->operator] = $this->enrolledOperator();
        $knit = $this->listing('cpa', 'ニット');
        $tops = $this->inside('cpa', fn (): string => app(CategoryService::class)->create(['name' => 'トップス', 'slug' => 'tops', 'parent_id' => null], 'test')->id);

        $fixed = $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['code' => ' welcome-500 ', 'name' => '初回のお客さん', 'value' => 500, 'target' => 'categories', 'target_ids' => [strtoupper($tops)]]))
            ->assertCreated()->assertJsonPath('coupon.code', 'WELCOME-500')->assertJsonPath('coupon.state', 'active')->assertJsonPath('coupon.used', 0)
            ->assertJsonPath('coupon.per_customer_limit', 1)->assertJsonPath('coupon.locked', false)
            ->assertJsonPath('coupon.targets', [['id' => $tops, 'name' => 'トップス']])->json('coupon.id');
        // 定率は上限の額を持てる。定率でない種類に送った上限の額は捨てる
        $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['code' => 'KNIT15', 'kind' => 'percent', 'value' => 15, 'max_discount' => 3000, 'target' => 'listings', 'target_ids' => [$knit], 'per_customer_limit' => null, 'members_only' => true]))
            ->assertCreated()->assertJsonPath('coupon.max_discount', 3000)->assertJsonPath('coupon.per_customer_limit', null)->assertJsonPath('coupon.targets.0.name', 'ニット');
        $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['code' => 'SHIPFREE', 'kind' => 'free_shipping', 'value' => 999, 'max_discount' => 100, 'min_subtotal' => 5000, 'status' => 'paused']))
            ->assertCreated()->assertJsonPath('coupon.value', 0)->assertJsonPath('coupon.max_discount', null)->assertJsonPath('coupon.state', 'paused');
        // 始まる前・終わった・回数の上限
        $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['code' => 'LATER', 'starts_at' => now()->addDay()->toIso8601String()]))->assertJsonPath('coupon.state', 'scheduled');
        $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['code' => 'OVER', 'starts_at' => now()->subDays(3)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()]))->assertJsonPath('coupon.state', 'ended');

        $list = $this->send('GET', '/api/tenants/cpa/coupons')->assertOk()->assertJsonPath('counts', ['all' => 5, 'active' => 4, 'paused' => 1])->assertJsonPath('meta.total', 5);
        $this->assertSame(['OVER', 'LATER', 'SHIPFREE', 'KNIT15', 'WELCOME-500'], array_column($list->json('coupons'), 'code'));
        $this->send('GET', '/api/tenants/cpa/coupons?status=paused')->assertJsonPath('meta.total', 1)->assertJsonPath('coupons.0.code', 'SHIPFREE');
        $this->send('GET', '/api/tenants/cpa/coupons?q=knit')->assertJsonPath('meta.total', 1)->assertJsonPath('coupons.0.code', 'KNIT15');
        $this->send('GET', '/api/tenants/cpa/coupons?q='.urlencode('初回'))->assertJsonPath('coupons.0.code', 'WELCOME-500');
        $this->send('GET', '/api/tenants/cpa/coupons?q=%25')->assertJsonPath('meta.total', 0);
        $this->send('GET', '/api/tenants/cpa/coupons?status=gone')->assertStatus(422);

        $this->send('GET', "/api/tenants/cpa/coupons/{$fixed}")->assertOk()->assertJsonPath('coupon.name', '初回のお客さん');

        // 店のイベント（運営の actor）と中央の監査ログ
        $this->inside('cpa', function () use ($fixed): void {
            $event = DomainEvent::query()->where('type', 'coupon.created')->where('aggregate_id', $fixed)->sole();
            $this->assertSame('operator:'.$this->operator->id, $event->actor);
        });
        // 監査ログは追記専用で、テストの間も消えない（自分のクーポンの ID で絞る）
        $audit = AuditEvent::query()->where('type', 'operator.coupon_changed')->where('data->coupon_id', $fixed)->sole();
        $this->assertEquals(['coupon_id' => $fixed, 'code' => 'WELCOME-500', 'action' => 'created'], $audit->data);
        $this->assertSame('cpa', $audit->tenant_id);
    }

    public function test_wrong_values_are_refused_at_the_door(): void
    {
        [$this->operator] = $this->enrolledOperator();
        $knit = $this->listing('cpa', 'ニット');
        $elsewhere = $this->listing('cpb', 'ほかの店の商品');
        $base = '/api/tenants/cpa/coupons';
        $this->send('POST', $base, $this->form(['code' => 'TAKEN']))->assertCreated();

        $cases = [
            'value' => [['value' => 0], ['value' => 100_000_001], ['kind' => 'percent', 'value' => 101], ['value' => '500円'], ['value' => null]],
            'max_discount' => [['kind' => 'percent', 'value' => 10, 'max_discount' => 0]],
            'kind' => [['kind' => 'gift']],
            'ends_at' => [['starts_at' => '2026-12-01T00:00:00+09:00', 'ends_at' => '2026-11-30T00:00:00+09:00'], ['starts_at' => '2026-12-01T00:00:00+09:00', 'ends_at' => '2026-12-01T00:00:00+09:00']],
            'starts_at' => [['starts_at' => 'あした'], ['starts_at' => '1999-12-31T00:00:00Z']],
            'target_ids' => [
                ['target' => 'listings', 'target_ids' => []],
                ['target' => 'listings', 'target_ids' => [(string) Str::ulid()]],
                // ほかの店の商品の ID は、この店の DB には無い
                ['target' => 'listings', 'target_ids' => [$elsewhere]],
                ['target' => 'categories', 'target_ids' => [$knit]],
            ],
            'target_ids.0' => [['target' => 'listings', 'target_ids' => ['not-an-id']]],
            'target' => [['target' => 'everything']],
            'code' => [['code' => 'ab'], ['code' => 'a b c'], ['code' => '-ABC'], ['code' => '<script>'], ['code' => str_repeat('A', 33)], ['code' => 'taken']],
            'usage_limit' => [['usage_limit' => 0]],
            'per_customer_limit' => [['per_customer_limit' => 0], ['per_customer_limit' => 1001]],
            'members_only' => [['members_only' => 'yes']],
            'name' => [['name' => '   '], ['name' => str_repeat('あ', 101)]],
        ];

        foreach ($cases as $field => $variants) {
            foreach ($variants as $variant) {
                $response = $this->send('POST', $base, $this->form($variant + ['code' => 'FRESH-1']));
                $this->assertSame(422, $response->status(), json_encode($variant, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).' '.$response->getContent());
                $response->assertJsonValidationErrors($field);
            }
        }

        $this->inside('cpa', fn () => $this->assertSame(1, Coupon::query()->count()));
    }

    public function test_staff_need_the_operate_level_and_other_shops_coupons_are_not_found(): void
    {
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'cpa', 'operate', 'test');
        $access->grant($staff, 'cpb', 'operate', 'test');
        $id = $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['code' => 'STAFF-1']))->assertCreated()->json('coupon.id');

        // 閲覧: 見るだけ
        $access->grant($staff, 'cpa', 'read', 'test');
        $this->send('GET', '/api/tenants/cpa/coupons')->assertOk()->assertJsonPath('meta.total', 1);
        $this->send('GET', "/api/tenants/cpa/coupons/{$id}")->assertOk();
        $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['code' => 'STAFF-2']))->assertForbidden();
        $this->send('PUT', "/api/tenants/cpa/coupons/{$id}", $this->form(['name' => '直した']))->assertForbidden();
        $this->send('POST', "/api/tenants/cpa/coupons/{$id}/pause")->assertForbidden();
        $this->inside('cpa', function (): void {
            $this->assertSame(['STAFF-1'], Coupon::query()->pluck('code')->all());
            $this->assertSame('active', Coupon::query()->value('status'));
        });

        // ほかの店からは、ID を知っていても見えない・変えられない
        $this->send('GET', "/api/tenants/cpb/coupons/{$id}")->assertNotFound();
        $this->send('PUT', "/api/tenants/cpb/coupons/{$id}", $this->form(['name' => 'x']))->assertNotFound();
        $this->send('POST', "/api/tenants/cpb/coupons/{$id}/pause")->assertNotFound();
        $this->send('GET', '/api/tenants/cpb/coupons')->assertOk()->assertJsonPath('meta.total', 0);

        // 与えられていない店は 404
        $access->grant($staff, 'cpb', null, 'test');
        $this->send('GET', '/api/tenants/cpb/coupons')->assertNotFound();
    }

    public function test_pausing_and_resuming_leave_events_and_a_used_coupon_keeps_its_discount(): void
    {
        [$this->operator] = $this->enrolledOperator();
        $id = $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['code' => 'SALE-500', 'value' => 500]))->assertCreated()->json('coupon.id');
        $path = "/api/tenants/cpa/coupons/{$id}";

        $this->send('POST', "{$path}/pause")->assertOk()->assertJsonPath('coupon.state', 'paused');
        // 二度目は何もしない（イベントも増えない）
        $this->send('POST', "{$path}/pause")->assertOk()->assertJsonPath('coupon.status', 'paused');
        $this->send('POST', "{$path}/resume")->assertOk()->assertJsonPath('coupon.state', 'active');

        // 使われる前は、種類も値も対象も変えられる。コードは変えない
        $this->send('PUT', $path, $this->form(['value' => 800, 'name' => '秋のセール']))->assertOk()->assertJsonPath('coupon.value', 800)->assertJsonPath('coupon.name', '秋のセール');
        $this->send('PUT', $path, $this->form(['code' => 'OTHER', 'value' => 800]))->assertStatus(422)->assertJsonValidationErrors('code');
        $this->send('PUT', $path, $this->form(['status' => 'paused', 'value' => 800]))->assertStatus(422)->assertJsonValidationErrors('status');

        $this->redeem('cpa', $id, 800);
        $this->send('GET', $path)->assertJsonPath('coupon.used', 1)->assertJsonPath('coupon.locked', true);

        // 使われた後は、種類・値・上限の額・対象を変えられない。期間・条件・回数・メモは変えられる
        foreach ([['value' => 900], ['kind' => 'percent', 'value' => 10], ['target' => 'listings', 'target_ids' => [$this->listing('cpa', 'ニット')]]] as $change) {
            $field = array_key_first($change);
            $this->send('PUT', $path, $this->form($change + ['value' => 800]))->assertStatus(422)->assertJsonValidationErrors($field)
                ->assertJsonPath("errors.{$field}.0", CouponService::LOCKED);
        }
        $this->send('PUT', $path, $this->form(['value' => 800, 'name' => '秋のセール（延長）', 'usage_limit' => 1, 'min_subtotal' => 3000]))->assertOk()
            ->assertJsonPath('coupon.name', '秋のセール（延長）')->assertJsonPath('coupon.state', 'used_up');
        // 何も変えない保存はイベントを残さない
        $this->send('PUT', $path, $this->form(['value' => 800, 'name' => '秋のセール（延長）', 'usage_limit' => 1, 'min_subtotal' => 3000]))->assertOk();

        $this->send('GET', '/api/tenants/cpa/coupons')->assertJsonPath('coupons.0.used', 1)->assertJsonPath('coupons.0.state', 'used_up');

        $this->inside('cpa', function () use ($id): void {
            $types = DomainEvent::query()->where('aggregate_type', 'coupon')->where('aggregate_id', $id)->orderBy('sequence')->pluck('type')->all();
            $this->assertSame(['coupon.created', 'coupon.paused', 'coupon.resumed', 'coupon.updated', 'coupon.updated'], $types);
            $updated = DomainEvent::query()->where('type', 'coupon.updated')->orderBy('sequence')->get();
            $this->assertSame(['name', 'value'], $updated[0]->payload['fields']);
            $this->assertSame(['min_subtotal', 'name', 'usage_limit'], $updated[1]->payload['fields']);
            $this->assertSame(800, Coupon::query()->findOrFail($id)->value);
        });
        $this->assertSame(['created', 'paused', 'paused', 'resumed', 'updated', 'updated', 'updated'], AuditEvent::query()->where('type', 'operator.coupon_changed')->where('data->coupon_id', $id)->orderBy('sequence')->get()->map(static fn (AuditEvent $e): string => $e->data['action'])->all());
    }

    public function test_automatic_discounts_have_no_code_need_a_label_and_are_capped_at_twenty(): void
    {
        [$this->operator] = $this->enrolledOperator();
        $base = '/api/tenants/cpa/coupons';

        // コードは送られても捨てる。名前は前後の空白を除く。1 人の回数は送られなければ何回でも（クーポンは 1 回）
        $id = $this->send('POST', $base, $this->form(['method' => 'automatic', 'code' => 'IGNORED', 'label' => ' 秋のセール 全品 10% ', 'kind' => 'percent', 'value' => 10, 'min_quantity' => 3]))
            ->assertCreated()->assertJsonPath('coupon.method', 'automatic')->assertJsonPath('coupon.code', null)->assertJsonPath('coupon.label', '秋のセール 全品 10%')
            ->assertJsonPath('coupon.per_customer_limit', null)->assertJsonPath('coupon.min_quantity', 3)->json('coupon.id');
        $this->send('POST', $base, $this->form(['code' => 'PLAIN-1', 'label' => '捨てる名前']))->assertCreated()->assertJsonPath('coupon.method', 'code')->assertJsonPath('coupon.label', null)
            ->assertJsonPath('coupon.per_customer_limit', 1);

        // 一覧: 種類と名前。名前で探せて、種類で絞れる
        $this->send('GET', $base)->assertJsonPath('coupons.1.method', 'automatic')->assertJsonPath('coupons.1.label', '秋のセール 全品 10%');
        $this->send('GET', "{$base}?q=".urlencode('秋のセール'))->assertJsonPath('meta.total', 1)->assertJsonPath('coupons.0.id', $id);
        $this->send('GET', "{$base}?method=automatic")->assertJsonPath('meta.total', 1);
        $this->send('GET', "{$base}?method=gift")->assertStatus(422);

        // 直す: 名前と数の条件は変えられる。種類（method）は変えられない。名前は空にできない
        $this->send('PUT', "{$base}/{$id}", $this->form(['label' => 'まとめ買い 10%', 'kind' => 'percent', 'value' => 10, 'min_quantity' => 2]))->assertOk()
            ->assertJsonPath('coupon.label', 'まとめ買い 10%')->assertJsonPath('coupon.min_quantity', 2)->assertJsonPath('coupon.per_customer_limit', null);
        $this->send('PUT', "{$base}/{$id}", $this->form(['method' => 'code', 'kind' => 'percent', 'value' => 10]))->assertStatus(422)->assertJsonValidationErrors('method');
        $this->send('PUT', "{$base}/{$id}", $this->form(['label' => '', 'kind' => 'percent', 'value' => 10]))->assertStatus(422)->assertJsonValidationErrors('label');

        // 入口で断る値
        foreach ([
            'label' => [['label' => null], ['label' => str_repeat('あ', 41)], ['label' => "改行\nあり"]],
            'min_quantity' => [['label' => '名前', 'min_quantity' => 0], ['label' => '名前', 'min_quantity' => 1001], ['label' => '名前', 'min_quantity' => '2点']],
            'method' => [['label' => '名前', 'method' => 'secret']],
        ] as $field => $variants) {
            foreach ($variants as $variant) {
                $this->send('POST', $base, $this->form($variant + ['method' => 'automatic']))->assertStatus(422)->assertJsonValidationErrors($field);
            }
        }

        // 使っている自動の割引は 20 件まで（21 件目は断る。止めた割引は数えない）
        for ($i = 2; $i <= CouponService::MAX_AUTOMATIC; $i++) {
            $this->send('POST', $base, $this->form(['method' => 'automatic', 'label' => "割引 {$i}"]))->assertCreated();
        }
        $this->send('POST', $base, $this->form(['method' => 'automatic', 'label' => '21 件目']))->assertStatus(422)
            ->assertJsonPath('errors.method.0', CouponService::TOO_MANY_AUTOMATIC);
        $this->send('POST', "{$base}/{$id}/pause")->assertOk();
        $this->send('POST', $base, $this->form(['method' => 'automatic', 'label' => '21 件目']))->assertCreated();
        $this->send('POST', "{$base}/{$id}/resume")->assertStatus(422)->assertJsonPath('errors.status.0', CouponService::TOO_MANY_AUTOMATIC);

        $this->inside('cpa', function () use ($id): void {
            // イベントには種類だけ（名前は入れない）
            $this->assertEquals(['kind' => 'percent', 'method' => 'automatic'], DomainEvent::query()->where('type', 'coupon.created')->where('aggregate_id', $id)->sole()->payload);
            $this->assertSame(['label', 'min_quantity'], DomainEvent::query()->where('type', 'coupon.updated')->where('aggregate_id', $id)->sole()->payload['fields']);
        });
        // 監査ログは自動の割引でもクーポンの ID で残る（コードは無い）
        $this->assertEquals(['coupon_id' => $id, 'code' => null, 'action' => 'created'], AuditEvent::query()->where('type', 'operator.coupon_changed')->where('data->coupon_id', $id)->orderBy('sequence')->firstOrFail()->data);
    }

    public function test_reading_staff_cannot_make_automatic_discounts_and_other_shops_cannot_reach_them(): void
    {
        [$staff] = $this->enrolledOperator('staff2@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'cpa', 'operate', 'test');
        $access->grant($staff, 'cpb', 'operate', 'test');
        $id = $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['method' => 'automatic', 'label' => '全品 10%']))->assertCreated()->json('coupon.id');

        $access->grant($staff, 'cpa', 'read', 'test');
        $this->send('GET', "/api/tenants/cpa/coupons/{$id}")->assertOk()->assertJsonPath('coupon.label', '全品 10%');
        $this->send('POST', '/api/tenants/cpa/coupons', $this->form(['method' => 'automatic', 'label' => '閲覧の人の割引']))->assertForbidden();
        $this->send('PUT', "/api/tenants/cpa/coupons/{$id}", $this->form(['label' => '直した']))->assertForbidden();
        $this->send('POST', "/api/tenants/cpa/coupons/{$id}/pause")->assertForbidden();

        $this->send('GET', "/api/tenants/cpb/coupons/{$id}")->assertNotFound();
        $this->send('PUT', "/api/tenants/cpb/coupons/{$id}", $this->form(['label' => 'x']))->assertNotFound();
        $this->send('POST', "/api/tenants/cpb/coupons/{$id}/pause")->assertNotFound();
        $this->inside('cpa', function (): void {
            $this->assertSame(['全品 10%'], Coupon::query()->pluck('label')->all());
            $this->assertSame('active', Coupon::query()->value('status'));
        });
    }

    /**
     * 画面のフォームが送る形（直すときもコード以外の全部を送る）
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function form(array $values): array
    {
        return $values + [
            'name' => 'テスト',
            'kind' => 'fixed',
            'value' => 500,
            'max_discount' => null,
            'target' => 'all',
            'target_ids' => [],
            'min_subtotal' => null,
            'starts_at' => null,
            'ends_at' => null,
            'usage_limit' => null,
            'members_only' => false,
        ];
    }

    private function listing(string $shop, string $title): string
    {
        return $this->inside($shop, fn (): string => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 1000, 'status' => 'active'])->id);
    }

    /**
     * ショップで注文に使われた形（注文を作り、核の redeem で記録する）
     */
    private function redeem(string $shop, string $couponId, int $discount): void
    {
        $this->inside($shop, function () use ($couponId, $discount): void {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'セーター', 'unit_amount' => 1000, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '09012345678', 'address' => ['city' => '渋谷区']]);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'storefront');

            DB::transaction(function () use ($couponId, $discount, $sale, $listing): void {
                app(EventStore::class)->lock();
                $coupon = Coupon::query()->findOrFail($couponId);
                app(CouponService::class)->redeem($sale->id, $coupon, [['key' => '1', 'listingId' => $listing->id, 'amount' => 1000]], null, false, $discount, ['1' => $discount], 0, 'storefront');
            });
        });
    }
}
