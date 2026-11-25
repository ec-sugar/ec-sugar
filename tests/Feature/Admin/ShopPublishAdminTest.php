<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Shop\Models\ShopSettings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の「ショップの公開」: 準備中・公開の切り替えと、準備中に入るパスワード。
 * 変えるのは運用の段階から（ほかの店の設定と同じ。ADR 0024）。変えたことはイベントに残し、パスワードの値はどこにも返さない・残さない
 */
final class ShopPublishAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string PASSWORD = 'preview-pass-77';

    /** 作った直後の店は準備中のまま確かめる */
    protected bool $openNewShops = false;

    private ?Operator $operator = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->tenant('spp');
        $this->tenant('spq');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_shop_is_published_and_the_password_is_set_and_recorded_without_its_value(): void
    {
        [$this->operator, $authenticator] = $this->enrolledOperator();
        $this->send('GET', '/api/tenants/spp/shop-settings')->assertOk()->assertJsonPath('publish', ['status' => 'preparing', 'has_password' => false]);

        $response = $this->send('PUT', '/api/tenants/spp/shop-settings/storefront-password', ['password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('publish', ['status' => 'preparing', 'has_password' => true]);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $response->getContent());
        $this->assertStringNotContainsString('$2y$', (string) $response->getContent());

        // 公開にするときだけ、パスキーでの再確認が要る（準備中にするのは要らない）
        $this->send('PUT', '/api/tenants/spp/shop-settings/publish', ['status' => 'open'])->assertStatus(423);
        $this->send('GET', '/api/tenants/spp/shop-settings')->assertOk()->assertJsonPath('publish.status', 'preparing');
        $this->send('PUT', '/api/tenants/spp/shop-settings/publish', ['status' => 'preparing'])->assertOk(); // 同じなら記録しない
        $this->reconfirm($this->operator, $authenticator);
        $this->send('PUT', '/api/tenants/spp/shop-settings/publish', ['status' => 'open'])->assertOk()->assertJsonPath('publish.status', 'open');
        $this->send('PUT', '/api/tenants/spp/shop-settings/publish', ['status' => 'open'])->assertOk(); // 同じなら記録しない
        $this->send('PUT', '/api/tenants/spp/shop-settings/publish', ['status' => 'preparing'])->assertOk()->assertJsonPath('publish.status', 'preparing');
        $this->send('PUT', '/api/tenants/spp/shop-settings/storefront-password', ['password' => null])->assertOk()->assertJsonPath('publish.has_password', false);

        $this->inside('spp', function (): void {
            $hash = DB::table('shop_settings')->value('storefront_password_hash');
            $this->assertNull($hash);
            $this->assertSame(2, (int) DB::table('shop_settings')->value('storefront_password_generation'));

            $events = DomainEvent::query()->whereIn('type', ['shop.storefront_status_changed', 'shop.storefront_password_changed'])->orderBy('sequence')->get();
            $this->assertEquals([
                ['shop.storefront_password_changed', ['set' => true]],
                ['shop.storefront_status_changed', ['from' => 'preparing', 'to' => 'open']],
                ['shop.storefront_status_changed', ['from' => 'open', 'to' => 'preparing']],
                ['shop.storefront_password_changed', ['set' => false]],
            ], $events->map(static fn (DomainEvent $e): array => [$e->type, $e->payload])->all());
            $this->assertSame(['operator:'.$this->operator?->id], $events->pluck('actor')->unique()->values()->all());
            $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(DB::table('domain_events')->get()));
        });
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(AuditEvent::query()->get()));

        // ハッシュで置く（平文は持たない）・ほかの店は変わらない
        $this->send('PUT', '/api/tenants/spp/shop-settings/storefront-password', ['password' => self::PASSWORD])->assertOk();
        $this->inside('spp', function (): void {
            $hash = (string) ShopSettings::query()->value('storefront_password_hash');
            $this->assertNotSame(self::PASSWORD, $hash);
            $this->assertTrue(Hash::check(self::PASSWORD, $hash));
            $this->assertArrayNotHasKey('storefront_password_hash', ShopSettings::query()->firstOrFail()->toArray());
        });
        $this->send('GET', '/api/tenants/spq/shop-settings')->assertOk()->assertJsonPath('publish', ['status' => 'preparing', 'has_password' => false]);
    }

    public function test_bad_values_are_refused(): void
    {
        $this->enrolledOperator();
        foreach ([[], ['status' => 'public'], ['status' => 'OPEN'], ['status' => ['open']]] as $body) {
            $this->send('PUT', '/api/tenants/spp/shop-settings/publish', $body)->assertStatus(422)->assertJsonValidationErrors('status');
        }

        foreach ([[], ['password' => 'short'], ['password' => str_repeat('a', 65)], ['password' => "line\nbreak-pass"], ['password' => str_repeat('あ', 30)], ['password' => 12345678]] as $body) {
            $this->send('PUT', '/api/tenants/spp/shop-settings/storefront-password', $body)->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $this->inside('spp', fn () => $this->assertSame(0, DomainEvent::query()->where('type', 'like', 'shop.storefront_%')->count()));
    }

    public function test_only_operate_level_and_above_change_it(): void
    {
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'spp', 'read', 'test');

        $this->send('GET', '/api/tenants/spp/shop-settings')->assertOk()->assertJsonPath('publish.status', 'preparing');
        $this->send('PUT', '/api/tenants/spp/shop-settings/publish', ['status' => 'open'])->assertForbidden();
        $this->send('PUT', '/api/tenants/spp/shop-settings/storefront-password', ['password' => self::PASSWORD])->assertForbidden();
        $this->send('PUT', '/api/tenants/spq/shop-settings/publish', ['status' => 'open'])->assertNotFound();

        $access->grant($staff, 'spp', 'operate', 'test');
        $this->send('PUT', '/api/tenants/spp/shop-settings/publish', ['status' => 'open'])->assertStatus(423);
        $this->reconfirm($staff, $authenticator);
        $this->send('PUT', '/api/tenants/spp/shop-settings/publish', ['status' => 'open'])->assertOk()->assertJsonPath('publish.status', 'open');

        // 止めた運営は入れない
        $staff->update(['disabled_at' => now()]);
        $this->send('PUT', '/api/tenants/spp/shop-settings/publish', ['status' => 'preparing'])->assertUnauthorized();
        $this->send('PUT', '/api/tenants/spp/shop-settings/storefront-password', ['password' => self::PASSWORD])->assertUnauthorized();
    }
}
