<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Plugin\Models\PluginState;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginNotInstalled;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PluginRoutes;
use App\Core\Plugin\PluginTenants;
use App\Core\Plugin\Services\PluginStates;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店ごとのプラグインの使う・使わない（ADR 0025）。使う店だけで経路と定期の処理が動く。
 * テスト用のプラグイン hello を登録簿に足し、核の関数（PluginRoutes）で経路を登録して確かめる
 */
final class PluginStatesTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->tenant('psa');
        $this->tenant('psb');

        $registry = new PluginRegistry;
        $registry->register(new PluginDefinition(name: 'hello', title: 'こんにちは', description: '', pluginApi: 1, storefrontRoutes: static function (): void {
            Route::get('/', static fn (): string => 'hello from '.Tenant::current()->getTenantKey())->name('index');
        }));
        $this->app->instance(PluginRegistry::class, $registry);
        PluginRoutes::storefront($registry);
        // テストの中で後から足した経路は、名前の目録を作り直す（本番は起動の時に一度だけ登録する）
        Route::getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_a_plugin_is_off_until_a_shop_turns_it_on_and_each_change_is_recorded(): void
    {
        $this->inside('psa', function (): void {
            $states = app(PluginStates::class);
            $this->assertFalse($states->enabled('hello'));
            $this->assertSame(['hello' => ['enabled' => false, 'changed_at' => null]], $states->all());

            $states->set('hello', true, 'operator:test');
            $states->set('hello', true, 'operator:test');
            $this->assertTrue($states->enabled('hello'));
            $states->set('hello', false, 'operator:test');
            $this->assertFalse($states->enabled('hello'));
            $states->set('hello', true);

            // 同じ選びの繰り返しは記録しない。行は 1 つだけ
            $events = DomainEvent::query()->where('aggregate_type', 'plugin_state')->orderBy('sequence')->get();
            $this->assertSame(['plugin.enabled', 'plugin.disabled', 'plugin.enabled'], $events->pluck('type')->all());
            $this->assertEquals(['plugin' => 'hello'], $events->first()?->payload);
            // （ほかの行は移行が入れる商品の CSV の「使う」。ADR 0107 の段 1）
            $this->assertSame(1, PluginState::query()->where('plugin', 'hello')->count());

            // 入っていないプラグインは使えない
            try {
                $states->set('nope', true);
                $this->fail('入っていないプラグインを使えた');
            } catch (PluginNotInstalled $e) {
                $this->assertSame(404, $e->status());
            }
        });

        // 別の店には届かない
        $this->inside('psb', fn () => $this->assertFalse(app(PluginStates::class)->enabled('hello')));
    }

    public function test_the_storefront_route_and_scheduled_work_run_only_where_the_plugin_is_on(): void
    {
        $this->inside('psa', fn () => app(PluginStates::class)->set('hello', true));

        $this->shop('psa', '/p/hello')->assertOk()->assertSeeText('hello from psa');
        $this->shop('psb', '/p/hello')->assertNotFound();
        // 核の経路の場所には置けない（/p/<名前> の下だけ）
        $this->assertNull(collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === '/' && in_array('plugin:hello', $r->gatherMiddleware(), true)));
        $route = Route::getRoutes()->getByName('storefront.plugin.hello.index');
        $this->assertNotNull($route);
        $this->assertSame('p/hello', $route->uri());
        foreach (['storefront', 'public', 'plugin:hello'] as $mark) {
            $this->assertContains($mark, $route->gatherMiddleware());
        }

        $ran = [];
        $this->assertSame(1, app(PluginTenants::class)->each('hello', function (Tenant $tenant) use (&$ran): void {
            $ran[] = $tenant->getTenantKey();
        }));
        $this->assertSame(['psa'], $ran);

        // 止めると、どちらも動かない
        $this->inside('psa', fn () => app(PluginStates::class)->set('hello', false));
        $this->shop('psa', '/p/hello')->assertNotFound();
        $this->assertSame(0, app(PluginTenants::class)->each('hello', fn () => $this->fail('止めた店で動いた')));

        // 外した（登録簿から消えた）プラグインは、行が残っていても動かない
        $this->inside('psa', fn () => app(PluginStates::class)->set('hello', true));
        $this->app->instance(PluginRegistry::class, new PluginRegistry);
        $this->shop('psa', '/p/hello')->assertNotFound();
        $this->assertSame(0, app(PluginTenants::class)->each('hello', fn () => $this->fail('外したプラグインが動いた')));
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $id, string $path): TestResponse
    {
        $response = $this->get("http://{$id}.localhost{$path}");
        tenancy()->end();

        return $response;
    }
}
