<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\Manifest\Disclosure;
use App\Core\Plugin\Manifest\PackageManifest;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Sections\AppSection;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AppContract;
use Tests\TestCase;

/**
 * 全部の App に共通の確かめ（App の契約のテスト）のうち、DB を使わずに宣言とコードから決められるもの。
 * App を足すと、データの提供者が plugins/ から拾い、何も書かなくてもこの確かめが当たる。
 *
 *   - 登録簿の App と plugins/ の App が同じ一覧
 *   - マニフェスト（composer.json の extra.ec-sugar）の目的・設定の型・秘密の印・公表・差し込みが、登録簿の宣言と同じ
 *   - 秘密らしい名前の設定に secret の印がある（秘密は文の項目だけ）
 *   - ショップの経路に storefront・public・plugin:<名前> の印がある（使っていない店では 404。ADR 0025）。
 *     止めても開くと宣言した経路（keptStorefrontRoutes）だけは plugin の印が無い
 *   - お金の言葉に当たる管理画面の操作に money の印がある（例外は AppContract の一覧に理由つき）
 *
 * 店とお客さんの側から確かめるもの（使っていない店で 404・ほかの店の記録・権限・秘密の暗号化）は AppContractTest
 */
final class AppDeclarationsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function apps(): array
    {
        return AppContract::provider();
    }

    public function test_the_registry_holds_exactly_the_apps_in_plugins(): void
    {
        $registry = app(PluginRegistry::class);
        $packages = AppContract::packages();
        $this->assertNotEmpty($packages);

        // 登録簿にあって plugins/ に無い App（テストの中だけの App を除く）も、plugins/ にあって登録されていない App も無い
        $registered = array_map(static fn ($p): string => $p->name, $registry->all());
        $this->assertSame(array_keys($packages), $registered, 'plugins/ の App と登録簿の App が違う（サービスプロバイダの登録を忘れていないか）');

        foreach ($registry->all() as $plugin) {
            // マニフェストを渡していれば、そのパッケージの composer.json そのもの
            if ($plugin->manifest !== null) {
                $this->assertSame(realpath($packages[$plugin->name].'/composer.json'), realpath($plugin->manifest), "{$plugin->name}: 登録に渡した composer.json がパッケージのものではない");
            }
        }
    }

    #[DataProvider('apps')]
    public function test_the_manifest_matches_what_the_registry_holds(string $plugin): void
    {
        $definition = app(PluginRegistry::class)->get($plugin);
        $root = AppContract::packages()[$plugin];
        $package = json_decode((string) file_get_contents($root.'/composer.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->assertIsArray($package);
        $manifest = PackageManifest::of($package);
        $this->assertIsArray($manifest);

        $this->assertSame([$plugin, $definition->pluginApi], [$manifest['name'] ?? null, $manifest['plugin_api'] ?? null], "{$plugin}: composer.json の name・plugin_api が登録と違う");

        // 登録簿が持つ宣言と、composer.json を読み直した宣言が同じ（manifest を渡し忘れると目的・設定・公表が登録簿に入らず、ここで違う）
        $declared = AppManifest::fromArray($manifest, $root);
        $this->assertSame(self::shape($declared), self::shape($definition->app()), "{$plugin}: composer.json の宣言と登録簿の宣言が違う（PluginDefinition に manifest を渡しているか）");
    }

    #[DataProvider('apps')]
    public function test_secret_looking_settings_are_marked_secret_and_secrets_are_text(string $plugin): void
    {
        $problems = [];

        foreach (app(PluginRegistry::class)->get($plugin)->app()->settings as $key => $spec) {
            foreach (AppContract::SECRET_KEY_WORDS as $word) {
                if (str_contains($key, $word) && ! $spec->secret) {
                    $problems[] = "{$key}: 名前に「{$word}」が入るのに secret の印が無い（店の鍵で暗号化されず、管理画面の応答とショップに出る）";
                }
            }

            if ($spec->secret && $spec->type !== SettingType::Text) {
                $problems[] = "{$key}: 秘密の項目は文（text）だけ（{$spec->type->value} は秘密にしても画面に値が要る）";
            }
        }

        $this->assertSame([], $problems);
    }

    #[DataProvider('apps')]
    public function test_shop_routes_carry_the_storefront_public_and_plugin_marks(string $plugin): void
    {
        $definition = app(PluginRegistry::class)->get($plugin);
        $routes = AppContract::shopRoutes(app(Router::class), $plugin, $definition->shopPages?->prefix());
        $kept = AppContract::keptWhenOff($definition);
        $problems = [];

        // ショップの経路を渡した App には経路があり、渡していない App には無い（核の経路に App の名前の経路が混ざらない）
        $this->assertSame($definition->storefrontRoutes !== null || $definition->keptStorefrontRoutes !== null || $definition->shopPages !== null, $routes !== [], "{$plugin}: ショップの経路の有無が登録と違う");

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $name = (string) $route->getName();
            $key = implode('|', $route->methods()).' '.$route->uri();

            if (! str_starts_with($name, "storefront.plugin.{$plugin}.")) {
                $problems[] = "{$key}: 経路の名前が storefront.plugin.{$plugin}. で始まらない（核の PluginRoutes を通さずに足した経路）";
            }

            foreach (['storefront', 'public'] as $mark) {
                if (! in_array($mark, $middleware, true)) {
                    $problems[] = "{$key}: {$mark} の印が無い";
                }
            }

            $marks = array_values(array_filter($middleware, static fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'plugin:')));

            // 止めても開くと宣言した経路（keptStorefrontRoutes）は、使う店の印を付けない（付くと、宣言に反して止めた店で 404 になる）
            if (in_array($name, $kept, true)) {
                if ($marks !== []) {
                    $problems[] = "{$key}: 止めても開くと宣言した経路なのに ".implode(',', $marks).' の印がある';
                }

                continue;
            }

            if ($marks !== ["plugin:{$plugin}"]) {
                $problems[] = "{$key}: plugin:{$plugin} の印がちょうど 1 つ無い（".($marks === [] ? '無い' : implode(',', $marks)).'）。使っていない店でも開いてしまう';
            }
        }

        // 止めても開くと宣言した経路は、どれも本当に登録されている
        foreach ($kept as $name) {
            if (! in_array($name, array_map(static fn ($r): string => (string) $r->getName(), $routes), true)) {
                $problems[] = "{$name}: keptStorefrontRoutes で宣言したのに、その名前の経路が登録されていない";
            }
        }

        $this->assertSame([], $problems);
    }

    #[DataProvider('apps')]
    public function test_admin_actions_that_move_money_carry_the_money_mark(string $plugin): void
    {
        $actions = AppContract::adminActions(AppContract::packages()[$plugin]);
        $lists = ['MONEY_UNMARKED' => AppContract::moneyUnmarked($plugin), 'MONEY_WORDS_ONLY' => AppContract::MONEY_WORDS_ONLY[$plugin] ?? []];
        $problems = [];
        $seen = [];

        foreach ($actions as $action) {
            $where = "{$action['file']}:{$action['line']} {$action['key']}";
            $listed = array_keys(array_filter($lists, static fn (array $list): bool => isset($list[$action['key']])));
            $word = AppContract::moneyWord($action['key'], $action['text']);
            $seen[$action['key']] = true;

            if ($action['money'] === null) {
                $problems[] = "{$where}: money の値が式で、機械では決めきれない（true か false をそのまま書く）";
            } elseif ($action['money'] && $listed !== []) {
                $problems[] = "{$where}: money の印が付いたので、AppContract::".implode('・', $listed).' から消す';
            } elseif (! $action['money'] && $word !== null && $listed === []) {
                $problems[] = "{$where}: 「{$word}」に当たるのに money の印が無い（お金に触れるなら money: true。触れないなら AppContract::MONEY_WORDS_ONLY に理由を書く）";
            } elseif (! $action['money'] && $word === null && $listed !== []) {
                $problems[] = "{$where}: お金の言葉に当たらなくなったので、AppContract::".implode('・', $listed).' から消す';
            }
        }

        // 例外の一覧は、今ある操作だけ
        foreach ($lists as $list => $keys) {
            foreach (array_keys($keys) as $key) {
                if (! isset($seen[$key])) {
                    $problems[] = "{$key}: AppContract::{$list} にあるが、そういう操作が無い（一覧から消す）";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_the_money_rule_reads_actions_from_the_code(): void
    {
        // 読み方そのものの確かめ: 今の App の操作から、印の付いたもの・付いていないもの・変数を含む鍵を読める。
        // 例のチケットの App が入っていない木（公開用の 1.0。ADR 0098）では、言葉の当て方だけを確かめる
        if ($this->hasApp('tickets')) {
            $byKey = [];

            foreach (AppContract::adminActions(AppContract::packages()['tickets']) as $action) {
                $byKey[$action['key']][] = $action['money'];
            }

            $this->assertSame([true, true], $byKey['void_refund']);
            $this->assertSame([false], $byKey['void']);
            $this->assertArrayHasKey('use_{n}', $byKey);
        }

        $this->assertSame('返金', AppContract::moneyWord('void', '返金はしません'));
        $this->assertSame('refund', AppContract::moneyWord('void_refund', ''));
        $this->assertNull(AppContract::moneyWord('publish', '公開する'));
    }

    /**
     * 比べるための宣言の形（区画の係や Blade の場所など、登録で足すものは外す）
     *
     * @return array<string, mixed>
     */
    private static function shape(AppManifest $manifest): array
    {
        return [
            'category' => $manifest->category->value,
            'settings' => array_map(static fn (SettingSpec $s): array => ['key' => $s->key] + $s->toArray(), $manifest->settings),
            'disclosures' => array_map(static fn (Disclosure $d): array => [$d->company, $d->data, $d->purpose, $d->policy], $manifest->disclosures),
            'scripts' => array_map(static fn ($s): array => [$s->file, array_map(static fn ($p) => $p->value, $s->pages)], $manifest->scripts),
            'csp' => $manifest->csp,
            'consent' => array_map(static fn ($c) => $c->value, $manifest->consent),
            'sections' => array_map(static fn (AppSection $s): array => array_map(static fn ($c) => $c->value, $s->consent) + ['embed' => $s->embed !== null], $manifest->sections),
        ];
    }
}
