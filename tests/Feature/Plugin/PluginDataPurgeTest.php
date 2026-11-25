<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Console\PluginPurgeCommand;
use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Plugin\Contracts\PluginDataPurger;
use App\Core\Plugin\Models\PluginSetting;
use App\Core\Plugin\Models\PluginState;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginPurgeRefused;
use App\Core\Plugin\PluginRefusedInDemo;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginDataPurge;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\DownloadFiles;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use EcSugar\PluginFaq\FaqItems;
use EcSugar\PluginFaq\Models\FaqItem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Tests\Support\FakeDownloads;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店で App のデータを消す（ADR 0092。核の PluginDataPurge と App の口 PluginDataPurger）。
 * 止めた店でだけ・オーナーだけ・直前の再確認の後・口の無い App とデモの店は断る。消すのはその店の App の表の行とファイルと App の設定で、
 * ほかの店のデータは消えない（店の境界）。記録には App の名前と数だけ。払ったお客さんがまだ受け取れるダウンロードがあるうちは App が断る
 */
final class PluginDataPurgeTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->disk = FakeDownloads::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_owner_purges_a_stopped_app_and_only_that_shop_loses_its_data(): void
    {
        $this->tenant('pga');
        $this->tenant('pgb');

        foreach (['pga', 'pgb'] as $shop) {
            $this->inside($shop, function (): void {
                app(PluginStates::class)->set('faq', true, 'test');
                app(FaqItems::class)->create(['question' => '送料は？', 'answer' => '全国 800 円です。', 'listing' => 'all', 'published' => true], 'test');
                app(FaqItems::class)->create(['question' => '包装は？', 'answer' => 'できます。', 'listing' => 'all', 'published' => true], 'test');
                // 核が持つ App の設定の行（秘密も）も一緒に消える
                PluginSetting::query()->create(['plugin' => 'faq', 'values' => ['note' => 'x'], 'secrets' => [], 'changed_at' => CarbonImmutable::now()]);
                app(PluginStates::class)->set('faq', false, 'test');
            });
        }

        [$owner, $authenticator] = $this->enrolledOperator();
        $this->send('GET', '/api/tenants/pga/plugins')->assertOk()->assertJsonPath('plugins.'.$this->index('faq').'.purgeable', true)
            ->assertJsonPath('plugins.'.$this->index('contact').'.purgeable', false);

        // 直前の再確認が無ければ消さない
        $this->send('DELETE', '/api/tenants/pga/plugins/faq/data')->assertStatus(423);
        $this->inside('pga', fn () => $this->assertSame(2, FaqItem::query()->count()));

        $this->reconfirm($owner, $authenticator);
        $this->send('DELETE', '/api/tenants/pga/plugins/faq/data')->assertOk()
            ->assertJsonPath('purged.rows', ['faq_items' => 2])->assertJsonPath('purged.total', 2)->assertJsonPath('purged.settings', true)->assertJsonPath('purged.leftover', false);

        $this->inside('pga', function () use ($owner): void {
            $this->assertSame(0, FaqItem::query()->count());
            $this->assertFalse(PluginSetting::query()->where('plugin', 'faq')->exists());
            // 状態の行は止めたまま残る（表も残る）
            $this->assertFalse(PluginState::query()->where('plugin', 'faq')->sole()->enabled);
            $event = DomainEvent::query()->where('type', 'plugin.purged')->sole();
            $this->assertSame($owner->actor(), $event->actor);
            $this->assertSame('plugin_state', $event->aggregate_type);
            $this->assertEquals(['plugin' => 'faq', 'rows' => ['faq_items' => 2], 'settings' => true], $event->payload);
        });

        // ほかの店のデータは消えない
        $this->inside('pgb', function (): void {
            $this->assertSame(2, FaqItem::query()->count());
            $this->assertTrue(PluginSetting::query()->where('plugin', 'faq')->exists());
            $this->assertFalse(DomainEvent::query()->where('type', 'plugin.purged')->exists());
        });

        $audit = AuditEvent::query()->where('type', 'tenant.plugin_purged')->where('tenant_id', 'pga')->sole();
        $this->assertSame([$owner->id, 'pga', 'faq', 2], [$audit->operator_id, $audit->tenant_id, $audit->data['plugin'], $audit->data['rows']]);

        // 2 回目は消す物が無いだけ（断らない）
        $this->send('DELETE', '/api/tenants/pga/plugins/faq/data')->assertOk()->assertJsonPath('purged.total', 0)->assertJsonPath('purged.settings', false);
    }

    public function test_it_is_refused_while_in_use_without_a_purger_and_for_unknown_apps(): void
    {
        $this->tenant('pgc');
        $this->inside('pgc', function (): void {
            app(PluginStates::class)->set('faq', true, 'test');
            app(FaqItems::class)->create(['question' => '送料は？', 'answer' => '全国 800 円です。', 'listing' => 'all', 'published' => true], 'test');
        });
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);

        // 使っている間は断る（行は残る）
        $this->send('DELETE', '/api/tenants/pgc/plugins/faq/data')->assertUnprocessable()->assertJsonPath('user_message', PluginPurgeRefused::inUse()->getMessage());
        // 消す口の無い App・入っていない App
        $this->send('DELETE', '/api/tenants/pgc/plugins/contact/data')->assertUnprocessable()->assertJsonPath('user_message', PluginPurgeRefused::noPurger()->getMessage());
        $this->send('DELETE', '/api/tenants/pgc/plugins/nope/data')->assertNotFound();

        $this->inside('pgc', function (): void {
            $this->assertSame(1, FaqItem::query()->count());
            $this->assertFalse(DomainEvent::query()->where('type', 'plugin.purged')->exists());
        });
        $this->assertFalse(AuditEvent::query()->where('type', 'tenant.plugin_purged')->where('tenant_id', 'pgc')->exists());
    }

    public function test_staff_cannot_purge_even_with_the_money_level(): void
    {
        $this->tenant('pgh');
        $this->inside('pgh', fn () => app(FaqItems::class)->create(['question' => '送料は？', 'answer' => '全国 800 円です。', 'listing' => 'all', 'published' => true], 'test'));
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'pgh', 'money', 'test');
        $this->reconfirm($staff, $authenticator);

        // スタッフは、いちばん強い段階で直前の再確認をしても消せない（ボタンも出ない。見るのはできる）
        $this->send('GET', '/api/tenants/pgh/plugins')->assertOk();
        $this->send('DELETE', '/api/tenants/pgh/plugins/faq/data')->assertForbidden();
        $this->inside('pgh', function (): void {
            $this->assertSame(1, FaqItem::query()->count());
            $this->assertFalse(DomainEvent::query()->where('type', 'plugin.purged')->exists());
        });
        $this->assertFalse(AuditEvent::query()->where('type', 'tenant.plugin_purged')->where('tenant_id', 'pgh')->exists());
    }

    public function test_downloads_files_go_after_commit_only_in_this_shop_and_not_while_customers_can_still_download(): void
    {
        $this->tenant('pgd');
        $this->tenant('pge');
        $files = [];

        foreach (['pgd', 'pge'] as $shop) {
            $files[$shop] = $this->inside($shop, fn (): array => $this->downloadWithGrant());
        }

        $this->inside('pgd', function () use ($files): void {
            app(PluginStates::class)->set('downloads', false, 'test');
            // まだ受け取れるリンクがあるうちは App が断る
            try {
                app(PluginDataPurge::class)->purge('downloads', 'test');
                $this->fail('受け取れるリンクが残っているのに消せた');
            } catch (PluginPurgeRefused $e) {
                $this->assertStringContainsString('1 件', $e->getMessage());
            }

            $this->assertSame(1, DownloadFile::query()->count());
            $this->assertTrue($this->disk->exists($files['pgd']['path']));

            // 置き場にだけ残ったファイル（前に消し終えられなかった物）も拾う
            $this->disk->put(DownloadFiles::folder().'/01jaaaaaaaaaaaaaaaaaaaaaaa.pdf', '%PDF-1.4 orphan');
            DownloadGrant::query()->update(['stopped_at' => CarbonImmutable::now(), 'stop_reason' => 'staff']);
            $result = app(PluginDataPurge::class)->purge('downloads', 'test');

            $this->assertSame(['downloads_files' => 1, 'downloads_grants' => 1, 'downloads_listings' => 0], $result->rows);
            $this->assertSame(2, $result->files);
            $this->assertFalse($result->leftover);
            $this->assertSame(0, DownloadFile::query()->count() + DownloadGrant::query()->count());
            $this->assertSame([], $this->disk->allFiles(DownloadFiles::folder()));
            // 核の表（商品と注文）は消さない
            $this->assertTrue(Listing::query()->whereKey($files['pgd']['listing'])->exists());
            $this->assertEquals(['plugin' => 'downloads', 'rows' => $result->rows, 'settings' => false], DomainEvent::query()->where('type', 'plugin.purged')->sole()->payload);
        });

        // ほかの店のファイルと行は残る
        $this->assertTrue($this->disk->exists($files['pge']['path']));
        $this->inside('pge', function (): void {
            $this->assertSame(1, DownloadFile::query()->count());
            $this->assertSame(1, DownloadGrant::query()->count());
        });
    }

    public function test_a_demo_shop_cannot_purge(): void
    {
        $this->tenant('demopurge');
        $this->artisan('shop:demo', ['id' => 'demopurge', '--on' => true])->assertSuccessful();
        $this->assertTrue(DemoShop::is(Tenant::query()->findOrFail('demopurge')));
        $this->inside('demopurge', function (): void {
            app(FaqItems::class)->create(['question' => '送料は？', 'answer' => '全国 800 円です。', 'listing' => 'all', 'published' => true], 'test');
            $this->expectException(PluginRefusedInDemo::class);

            try {
                app(PluginDataPurge::class)->purge('faq', 'test');
            } finally {
                $this->assertSame(1, FaqItem::query()->count());
            }
        });
    }

    public function test_the_cli_asks_first_and_purges_with_force(): void
    {
        $this->tenant('pgf');
        $this->inside('pgf', fn () => app(FaqItems::class)->create(['question' => '送料は？', 'answer' => '全国 800 円です。', 'listing' => 'all', 'published' => true], 'test'));

        $this->artisan('plugin:purge', ['id' => 'pgf', 'plugin' => 'faq'])
            ->expectsConfirmation('店 pgf の App「よくある質問」のデータを削除します。元に戻せません。続けますか', 'no')->assertFailed();
        $this->inside('pgf', fn () => $this->assertSame(1, FaqItem::query()->count()));

        // 一度も使っていない店でも消せる（止めた状態の行を作って記録する）
        $this->artisan('plugin:purge', ['id' => 'pgf', 'plugin' => 'faq', '--force' => true])->expectsOutputToContain('行 1')->assertSuccessful();
        $this->inside('pgf', function (): void {
            $this->assertSame(0, FaqItem::query()->count());
            $this->assertSame(PluginPurgeCommand::ACTOR, DomainEvent::query()->where('type', 'plugin.purged')->sole()->actor);
            $this->assertFalse(PluginState::query()->where('plugin', 'faq')->sole()->enabled);
        });
        $this->assertSame('cli', AuditEvent::query()->where('type', 'tenant.plugin_purged')->where('tenant_id', 'pgf')->sole()->data['via']);

        $this->artisan('plugin:purge', ['id' => 'pgf', 'plugin' => 'contact', '--force' => true])->expectsOutputToContain('データを削除する機能がありません')->assertFailed();
        $this->artisan('plugin:purge', ['id' => 'nope', 'plugin' => 'faq', '--force' => true])->assertFailed();
    }

    public function test_an_app_taken_out_of_composer_leaves_rows_that_the_admin_ignores(): void
    {
        $this->tenant('pgg');
        $this->inside('pgg', function (): void {
            // composer から外した App の状態と設定の行（コードはもう無い）
            PluginState::query()->create(['plugin' => 'gone-app', 'enabled' => true, 'changed_at' => CarbonImmutable::now()]);
            PluginSetting::query()->create(['plugin' => 'gone-app', 'values' => [], 'secrets' => [], 'changed_at' => CarbonImmutable::now()]);
            $this->assertFalse(app(PluginStates::class)->enabled('gone-app'));
        });
        $this->enrolledOperator();

        $plugins = $this->send('GET', '/api/tenants/pgg/plugins')->assertOk()->json('plugins');
        $this->assertNotContains('gone-app', array_column($plugins, 'name'));
        $this->send('GET', '/api/tenants/pgg/app-pages')->assertOk();
        $this->send('GET', 'http://pgg.localhost/')->assertOk();
    }

    public function test_files_go_only_after_the_rows_commit_and_only_inside_a_shop(): void
    {
        // 行を消す途中で落ちる App（App の頭で始まらない表の名前を返す。核が記録の前に断る）
        $purger = new class implements PluginDataPurger
        {
            public int $fileCalls = 0;

            public function refusal(): ?string
            {
                return null;
            }

            public function purgeRows(): array
            {
                return ['listings' => 1];
            }

            public function purgeFiles(): int
            {
                $this->fileCalls++;

                return 0;
            }
        };
        $registry = new PluginRegistry;
        $registry->register(new PluginDefinition(name: 'trial', title: 'テスト', description: '', pluginApi: 1, purger: $purger));
        $this->app->instance(PluginRegistry::class, $registry);

        // 店の文脈の外では消さない
        try {
            app(PluginDataPurge::class)->purge('trial', 'test');
            $this->fail('店の文脈の外で消せた');
        } catch (LogicException) {
            $this->assertSame(0, $purger->fileCalls);
        }

        $this->tenant('pgi');
        $this->inside('pgi', function () use ($purger): void {
            try {
                app(PluginDataPurge::class)->purge('trial', 'test');
                $this->fail('核の表の名前を返す App で消せた');
            } catch (LogicException $e) {
                $this->assertStringContainsString('App の頭で始め', $e->getMessage());
            }

            // トランザクションが落ちたので、ファイルには触れず、記録も状態の行も残らない
            $this->assertSame(0, $purger->fileCalls);
            $this->assertFalse(DomainEvent::query()->where('type', 'plugin.purged')->exists());
            $this->assertFalse(PluginState::query()->where('plugin', 'trial')->exists());
        });
    }

    /**
     * 店の中: ダウンロードを使い、売るファイル 1 つ（置き場にも）と、受け取れるリンク 1 つを作る
     *
     * @return array{listing: string, path: string}
     */
    private function downloadWithGrant(): array
    {
        app(PluginStates::class)->set('downloads', true, 'test');
        $seller = Seller::query()->firstOrFail();
        $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'download', 'title' => '写真集（PDF）', 'unit_amount' => 1500, 'status' => 'active']);
        $id = strtolower((string) str()->ulid());
        $path = DownloadFiles::folder()."/{$id}.pdf";
        $this->disk->put($path, '%PDF-1.4 test');
        DownloadFile::create(['id' => $id, 'listing_id' => $listing->id, 'name' => '写真集.pdf', 'extension' => 'pdf', 'mime' => 'application/pdf', 'bytes' => 13, 'sha256' => str_repeat('a', 64), 'path' => $path]);
        $order = app(TransactionService::class)->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000)], actor: 'test');
        DownloadGrant::create(['transaction_id' => $order->id, 'file_id' => $id, 'count' => 0, 'max_count' => 3, 'expires_at' => CarbonImmutable::now()->addDays(7)]);

        return ['listing' => $listing->id, 'path' => $path];
    }

    /**
     * 管理画面の App の一覧の中の、その App の位置
     */
    private function index(string $plugin): int
    {
        $names = array_column((array) $this->send('GET', '/api/tenants/pga/plugins')->json('plugins'), 'name');
        $index = array_search($plugin, $names, true);
        $this->assertIsInt($index);

        return $index;
    }
}
