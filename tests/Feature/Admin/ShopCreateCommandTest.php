<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Core\Listing\Models\Seller;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Stancl\Tenancy\Events\CreatingTenant;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店を作るコマンド（ADR 0031）。インストーラーが最初の店を作るのに使う。DB を作る力は CLI だけが持つ。
 * 流し直しても増えない・他の店の名前は取れない・おかしな入力は何も作らずに断る・途中で失敗したら作りかけを片付ける。
 */
final class ShopCreateCommandTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** @var list<string> コマンドで作った店（tearDown で消す） */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.domain' => 'admin.example.test', 'tenancy.central_domains' => ['localhost', 'admin.example.test']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            Tenant::query()->find($id)?->delete();
        }

        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_creates_a_shop_with_its_database_domain_and_audit_record(): void
    {
        $this->create('scc', 'はじめての店', 'scc.shop.example.test')->expectsOutputToContain('作りました')->assertSuccessful();

        $tenant = Tenant::query()->findOrFail('scc');
        self::assertSame('はじめての店', $tenant->name);
        self::assertSame(['scc.shop.example.test'], $tenant->domains()->pluck('domain')->all());
        self::assertTrue($this->inside('scc', static fn (): bool => Seller::query()->exists()), '店の DB ができて既定の販売者が入る');

        $audit = AuditEvent::query()->where('type', 'tenant.created')->where('tenant_id', 'scc')->sole();
        self::assertSame('scc', $audit->tenant_id);
        self::assertSame('scc.shop.example.test', $audit->data['domain']);
    }

    public function test_running_again_with_the_same_values_changes_nothing(): void
    {
        $this->create('scd', '店', 'scd.shop.example.test')->assertSuccessful();
        $this->create('scd', '店', 'scd.shop.example.test')->expectsOutputToContain('もうあります')->assertSuccessful();

        self::assertSame(1, Tenant::query()->whereKey('scd')->count());
        self::assertSame(1, DB::table('domains')->where('tenant_id', 'scd')->count());
        self::assertSame(1, AuditEvent::query()->where('type', 'tenant.created')->where('tenant_id', 'scd')->count());
    }

    public function test_refuses_an_existing_shop_with_different_values(): void
    {
        $this->create('sce', '店', 'sce.shop.example.test')->assertSuccessful();

        $this->create('sce', '店', 'other.shop.example.test')->expectsOutputToContain('別の内容')->assertFailed();

        self::assertSame(['sce.shop.example.test'], Tenant::query()->findOrFail('sce')->domains()->pluck('domain')->all());
    }

    public function test_running_again_after_the_name_was_changed_in_the_admin_keeps_the_new_name(): void
    {
        // 名前は管理画面で変えられる。インストーラーが答えの古い名前で流し直しても止めず、名前も書き戻さない
        $this->create('scr', '店', 'scr.shop.example.test')->assertSuccessful();
        Tenant::query()->whereKey('scr')->update(['name' => '新しい名前']);

        $this->create('scr', '店', 'scr.shop.example.test')->expectsOutputToContain('新しい名前')->assertSuccessful();

        self::assertSame('新しい名前', Tenant::query()->findOrFail('scr')->name);
    }

    public function test_refuses_a_domain_another_shop_already_uses_and_creates_nothing(): void
    {
        $this->tenant('scf');

        $this->create('scg', '店', 'scf.localhost')->expectsOutputToContain('ほかの店')->assertFailed();

        self::assertNull(Tenant::query()->find('scg'));
        self::assertFalse($this->databaseExists('scg'));
    }

    public function test_refuses_bad_input_without_creating_anything(): void
    {
        // 監査の記録は追記だけで空にできないので、前後の数で比べる
        $audits = AuditEvent::query()->where('type', 'tenant.created')->count();
        $cases = [
            ['Upper', '店', 'upper.shop.example.test'],
            ['under_score', '店', 'u.shop.example.test'],
            ['-dash', '店', 'd.shop.example.test'],
            ['dash-', '店', 'd.shop.example.test'],
            [str_repeat('a', 33), '店', 'long.shop.example.test'],
            ['a', '店', 'a.shop.example.test'],
            ['admin', '店', 'admin2.shop.example.test'],
            ['www', '店', 'www.shop.example.test'],
            ['sch', '', 'sch.shop.example.test'],
            ['sch', str_repeat('店', 101), 'sch.shop.example.test'],
            ['sch', "店\u{0007}", 'sch.shop.example.test'],
            ['sch', '店', 'admin.example.test'],
            ['sch', '店', 'localhost'],
            ['sch', '店', '127.0.0.1'],
            ['sch', '店', 'sch.shop.example.test/evil'],
            ['sch', '店', 'SCH.shop.example.test'],
            ['sch', '店', 'sch_.shop.example.test'],
            ['sch', '店', 'nodot'],
        ];

        foreach ($cases as [$id, $name, $domain]) {
            $this->create($id, $name, $domain)->assertExitCode(2);
        }

        self::assertSame(0, Tenant::query()->count(), 'どれも店を作らない');
        self::assertSame($audits, AuditEvent::query()->where('type', 'tenant.created')->count());
    }

    public function test_in_the_multi_shop_form_a_host_may_differ_from_the_id_but_is_one_label_under_the_shop_domain(): void
    {
        // 1 店舗の形から広げた本番の形（ADR 0090）: ショップのドメインは example.test、管理画面は admin.example.test
        config(['tls.shops' => 'multi', 'tls.shop_domain' => 'example.test', 'tenancy.central_domains' => ['localhost', 'admin.example.test', 'api.example.test']]);

        // ID と違う 1 段目（ID にハイフンを使わず、ホストには使う）
        $this->create('scdemo', '霞堤酒造', 'sc-demo.example.test')->expectsOutputToContain('作りました')->assertSuccessful();
        self::assertSame(['sc-demo.example.test'], Tenant::query()->findOrFail('scdemo')->domains()->pluck('domain')->all());

        $audits = AuditEvent::query()->where('type', 'tenant.created')->count();
        foreach ([
            'www.example.test',          // 同じドメインのほかのサイト
            'dev.example.test',
            'mail.example.test',
            'webmail.example.test',
            'api.example.test',          // 中央のホスト（central にも入っている）
            'admin.example.test',        // 管理画面
            'example.test',              // ショップのドメインそのもの
            'a.b.example.test',          // 2 段下（入口が受けない）
            'scx.other.test',            // ショップのドメインの外
            'scxexample.test',           // 末尾が似ているだけ
            'xn--scx.example.test',      // 3〜4 文字目の --
        ] as $domain) {
            $this->create('scx', '店', $domain)->assertExitCode(2);
        }

        self::assertNull(Tenant::query()->find('scx'), 'どれも店を作らない');
        self::assertFalse($this->databaseExists('scx'));
        self::assertSame($audits, AuditEvent::query()->where('type', 'tenant.created')->count());
    }

    public function test_rerunning_for_an_existing_shop_after_widening_to_multi_shops_changes_nothing(): void
    {
        // 1 店舗の形で作った店（ホストは何でもよかった）。広げた後にインストーラーを流し直しても、同じ内容なら何も変えずに通る
        config(['tls.shops' => 'single', 'tls.shop_domain' => 'www.example.test']);
        $this->create('scw', '店', 'www.example.test')->assertSuccessful();

        config(['tls.shops' => 'multi', 'tls.shop_domain' => 'example.test']);
        $this->create('scw', '店', 'www.example.test')->expectsOutputToContain('もうあります')->assertSuccessful();

        self::assertSame(1, DB::table('domains')->where('tenant_id', 'scw')->count());
    }

    public function test_cleans_up_the_half_made_shop_when_preparing_its_database_fails(): void
    {
        Event::listen(DatabaseMigrated::class, static function (): never {
            throw new RuntimeException('移行の後でわざと落とす');
        });

        $this->create('sci', '店', 'sci.shop.example.test')->expectsOutputToContain('作れませんでした')->assertFailed();

        self::assertFalse(tenancy()->initialized, '落ちた後に店の文脈を残さない');
        self::assertNull(Tenant::query()->find('sci'), '作りかけの店の行を残さない');
        self::assertFalse($this->databaseExists('sci'), '作りかけの DB を残さない');
        self::assertSame(0, DB::table('domains')->where('domain', 'sci.shop.example.test')->count());
    }

    public function test_a_losing_concurrent_run_does_not_delete_the_shop_the_other_run_made(): void
    {
        // 前の確かめを通った後、行を足す直前に別の流れが同じ ID の店を作り終えた
        $raced = false;
        Event::listen(CreatingTenant::class, function (CreatingTenant $event) use (&$raced): void {
            if (! $raced && $event->tenant->getTenantKey() === 'scj') {
                $raced = true;
                $this->tenant('scj');
            }
        });

        $this->create('scj', '店 scj', 'scj.shop.example.test')->expectsOutputToContain('作れませんでした')->assertFailed();

        self::assertTrue($raced);

        self::assertNotNull(Tenant::query()->find('scj'), '負けた側が他人の店を消さない');
        self::assertTrue($this->databaseExists('scj'));
    }

    private function create(string $id, string $name, string $domain): PendingCommand
    {
        $this->created[] = $id;

        $command = $this->artisan('shop:create', ['id' => $id, '--name' => $name, '--domain' => $domain]);
        self::assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function databaseExists(string $id): bool
    {
        $name = config('tenancy.database.prefix').$id.config('tenancy.database.suffix');

        return DB::connection(config('tenancy.database.central_connection'))
            ->table('pg_database')->where('datname', $name)->exists();
    }
}
