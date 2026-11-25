<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Contracts\ContentAccessProvider;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\ContentAccess;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use RuntimeException;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 読む権利（鍵）を App に聞く口（ContentAccessProvider・ContentAccess）。
 * 有料の記事を出すかの判断に使うので、**迷ったら送らない側へ倒す**ことを確かめる
 */
final class ContentAccessTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function tearDown(): void
    {
        // 作った店の DB とロールを消す（残すとテスト用の DB に溜まり、次の回の邪魔になる）
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_only_the_apps_this_shop_uses_answer_and_only_the_keys_asked_for_come_back(): void
    {
        $this->tenant('caa');
        $this->tenant('cab');
        $this->register('seller', ['membership:standard', 'post:AAA']);
        $this->register('other', ['membership:gold']);

        // 使う店だけで呼ばれる: caa は seller だけ、cab はどちらも使わない
        $this->inside('caa', function (): void {
            app(PluginStates::class)->set('seller', true, 'test');

            $access = app(ContentAccess::class);
            $this->assertSame(['membership:standard'], $access->held('acct-1', ['membership:standard']));
            $this->assertTrue($access->any('acct-1', ['membership:gold', 'membership:standard']));
            // 使っていない App（other）の鍵は返らない
            $this->assertSame([], $access->held('acct-1', ['membership:gold']));
        });

        // ほかの店では、同じお客さんの ID でも開かない
        $this->inside('cab', function (): void {
            $this->assertSame([], app(ContentAccess::class)->held('acct-1', ['membership:standard']));
            $this->assertFalse(app(ContentAccess::class)->any('acct-1', ['membership:standard']));
        });
    }

    public function test_an_app_cannot_open_a_key_that_was_not_asked_for(): void
    {
        $this->tenant('cac');
        // 聞かれていない鍵を返してくる App（誤りか、わざと）
        $this->register('greedy', ['post:AAA', 'post:SECRET', 'membership:standard']);

        $this->inside('cac', function (): void {
            app(PluginStates::class)->set('greedy', true, 'test');

            // 頼んだのは post:AAA だけ。ほかは核が捨てる
            $this->assertSame(['post:AAA'], app(ContentAccess::class)->held('acct-1', ['post:AAA']));
            $this->assertFalse(app(ContentAccess::class)->any('acct-1', ['post:OTHER']));
        });
    }

    public function test_a_broken_app_means_not_held_and_does_not_stop_the_shop(): void
    {
        $this->tenant('cad');
        app(PluginRegistry::class)->register(new PluginDefinition(
            name: 'broken', title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
            contentAccess: new class implements ContentAccessProvider
            {
                public function held(string $accountId, array $keys): array
                {
                    throw new RuntimeException('壊れた');
                }
            },
        ));
        $this->register('fine', ['membership:standard']);

        $this->inside('cad', function (): void {
            app(PluginStates::class)->set('broken', true, 'test');
            app(PluginStates::class)->set('fine', true, 'test');

            // 落ちた App は「持っていない」として扱い、ほかの App の答えは生きる
            $this->assertSame(['membership:standard'], app(ContentAccess::class)->held('acct-1', ['membership:standard']));
        });
    }

    public function test_no_account_and_bad_keys_never_open_anything(): void
    {
        $this->tenant('cae');
        $this->register('seller', ['membership:standard', 'post:AAA']);
        // 聞かれた鍵をそのまま返す App（核が形を検査していなければ、変な鍵がそのまま返ってくる）
        app(PluginRegistry::class)->register(new PluginDefinition(
            name: 'echo', title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
            contentAccess: new class implements ContentAccessProvider
            {
                public function held(string $accountId, array $keys): array
                {
                    return $keys;
                }
            },
        ));

        $this->inside('cae', function (): void {
            app(PluginStates::class)->set('seller', true, 'test');
            $access = app(ContentAccess::class);

            // ログインしていない人には聞かない
            $this->assertSame([], $access->held(null, ['membership:standard']));
            $this->assertSame([], $access->held('', ['membership:standard']));
            $this->assertFalse($access->any(null, ['membership:standard']));

            // 形の違う鍵は聞く前に落とす（App に変な文字を渡さない）。
            // 「聞かれた鍵をそのまま返す」App で確かめる（決め打ちで返す App では、検査が無くても結果が同じになってしまう）
            app(PluginStates::class)->set('echo', true, 'test');

            foreach ([
                '正しい形は通る' => ['membership:standard', ['membership:standard']],
            ] as $case => [$key, $want]) {
                $this->assertSame($want, $access->held('acct-1', [$key]), $case);
            }

            foreach ([
                '種類が無い' => 'standard',
                '名前が無い' => 'membership:',
                '大文字の種類' => 'Membership:standard',
                '区切りが 2 つ' => 'a:b:c',
                '空' => '',
                '長すぎる名前' => 'membership:'.str_repeat('a', 200),
                '道筋' => 'membership:../../etc/passwd',
            ] as $case => $key) {
                $this->assertSame([], $access->held('acct-1', [$key]), $case);
            }
            // ついでに、聞かれた鍵をそのまま返す App でも「頼んだ鍵以外は返らない」こと
            $this->assertSame(['post:AAA'], $access->held('acct-1', ['post:AAA']));

            // 鍵が 1 つも無いときも聞かない
            $this->assertSame([], $access->held('acct-1', []));
        });
    }

    public function test_the_number_of_keys_asked_at_once_is_capped(): void
    {
        $this->tenant('caf');
        $keys = array_map(static fn (int $i): string => "post:P{$i}", range(1, 40));
        $this->register('many', $keys);

        $this->inside('caf', function () use ($keys): void {
            app(PluginStates::class)->set('many', true, 'test');

            // 20 個までしか聞かない（記事の条件が膨らんでも聞く回数を増やさない）
            $this->assertCount(20, app(ContentAccess::class)->held('acct-1', $keys));
        });
    }

    /**
     * 持っている鍵を決め打ちで答える見本の App
     *
     * @param  list<string>  $holds
     */
    private function register(string $name, array $holds): void
    {
        app(PluginRegistry::class)->register(new PluginDefinition(
            name: $name, title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
            contentAccess: new class($holds) implements ContentAccessProvider
            {
                /** @param list<string> $holds */
                public function __construct(private readonly array $holds) {}

                public function held(string $accountId, array $keys): array
                {
                    return $this->holds;
                }
            },
        ));
    }
}
