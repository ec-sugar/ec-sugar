<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Contracts\AdminFormHandler;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Core\Plugin\Manifest\AppCategory;
use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\Manifest\PackageManifest;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Scaffold\AppScaffold;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * App のひな形のコマンド（make:plugin）。一時のフォルダに出し、composer に入れずに中身の形を確かめる:
 * 書くファイルの並び・入れ替え忘れの無いこと・PHP の構文・マニフェスト、登録簿が受け付けること（名前・目的・表の移行・App の画面・ショップの経路）。
 * 名前・目的・表示名の誤り、入っている App・ファイル・表と重なる名前、上書きを断ること。本番では作らないこと。
 * ひな形のテストそのもの（店を作って流す）は、composer に入れてから流す（docs/development/making-apps.md）
 */
final class MakePluginCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ec-sugar-make-plugin-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->root);
        $this->app->instance(AppScaffold::class, new AppScaffold($this->root));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_writes_an_app_that_the_registry_accepts_and_lists_what_to_add_by_hand(): void
    {
        $this->artisan('make:plugin', ['name' => 'scaffold-probe', '--category' => 'selling', '--title' => 'ひな形の見本'])
            ->expectsOutputToContain('App「ひな形の見本」のひな形を作りました')
            ->expectsOutputToContain('"ec-sugar/plugin-scaffold-probe": "^0.1"')
            ->expectsOutputToContain("'ec-sugar/plugin-scaffold-probe'")
            ->expectsOutputToContain("'scaffold-probe' => 'selling'")
            ->expectsOutputToContain("'scaffold-probe' => \$this->saved(new \\EcSugar\\PluginScaffoldProbe\\Models\\ScaffoldProbeItem, ['title' => '契約の確認の記録', 'status' => 'open']),")
            ->expectsOutputToContain("import scaffoldProbe from './scaffold-probe.js';")
            ->assertExitCode(0);

        $dir = $this->root.'/plugins/plugin-scaffold-probe';
        $migrations = glob($dir.'/database/migrations/*_000100_create_scaffold_probe_items_table.php') ?: [];
        $this->assertCount(1, $migrations);
        $expected = [
            'plugins/plugin-scaffold-probe/README.md',
            'plugins/plugin-scaffold-probe/composer.json',
            'plugins/plugin-scaffold-probe/database/migrations/'.basename($migrations[0]),
            'plugins/plugin-scaffold-probe/resources/views/index.blade.php',
            'plugins/plugin-scaffold-probe/src/Http/ScaffoldProbeController.php',
            'plugins/plugin-scaffold-probe/src/Models/ScaffoldProbeItem.php',
            'plugins/plugin-scaffold-probe/src/ScaffoldProbeAdminPage.php',
            'plugins/plugin-scaffold-probe/src/ScaffoldProbeItems.php',
            'plugins/plugin-scaffold-probe/src/ScaffoldProbeServiceProvider.php',
            'plugins/plugin-scaffold-probe/src/ScaffoldProbeStatus.php',
            'resources/admin/help/scaffold-probe.js',
            'tests/Feature/Plugin/ScaffoldProbeAppTest.php',
        ];
        $this->assertSame($expected, $this->written());

        foreach ($expected as $file) {
            $code = (string) file_get_contents($this->root.'/'.$file);

            // 入れ替え忘れが無い
            foreach (['__NAME__', '__PREFIX__', '__STUDLY__', '__TITLE__', '__CATEGORY__'] as $placeholder) {
                $this->assertStringNotContainsString($placeholder, $code, $file);
            }

            if (str_ends_with($file, '.php')) {
                $lint = Process::run([PHP_BINARY, '-l', $this->root.'/'.$file]);
                $this->assertTrue($lint->successful(), $file.': '.$lint->output().$lint->errorOutput());
            }
        }

        // ショップの経路の書き方に、個人情報の画面の印（navigation-only。ADR 0088）の注意と例がある。
        // ひな形が作る経路（/p/<名前> の公開の一覧）には印を付けない（会員の画面を作らないので、NavigationOnlyRoutesTest の一覧を変えずに通る）
        $provider = (string) file_get_contents($dir.'/src/ScaffoldProbeServiceProvider.php');
        $this->assertStringContainsString("->middleware('navigation-only')", $provider);
        $this->assertStringContainsString('ADR 0088', $provider);
        $this->assertStringContainsString('NavigationOnlyRoutesTest', $provider);
        $this->assertMatchesRegularExpression("~^\\s*//\\s+Route::middleware\\('navigation-only'\\)->group\\(~m", $provider);
        $this->assertDoesNotMatchRegularExpression('~^\\s*Route::[^\\n]*navigation-only~m', $provider);

        // 表とイベントの頭は名前のハイフンを _ に（自分の表だけを作る。PluginMigrationsTest と同じ見方）
        preg_match_all("/Schema::(?:create|table)\\('([a-z0-9_]+)'|ALTER TABLE ([a-z0-9_]+)/", (string) file_get_contents($migrations[0]), $tables);
        $this->assertSame(['scaffold_probe_items'], array_values(array_unique(array_filter([...$tables[1], ...$tables[2]]))));

        // マニフェストは核が読める形（目的・プラグイン API）
        $package = json_decode((string) file_get_contents($dir.'/composer.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('ec-sugar/plugin-scaffold-probe', $package['name']);
        $this->assertSame(['EcSugar\\PluginScaffoldProbe\\' => 'src/'], $package['autoload']['psr-4']);
        $this->assertSame(['EcSugar\\PluginScaffoldProbe\\ScaffoldProbeServiceProvider'], $package['extra']['laravel']['providers']);
        $manifest = PackageManifest::of($package);
        $this->assertIsArray($manifest);
        $this->assertSame(['plugin', 'scaffold-probe', PluginRegistry::API_VERSION, '^1.0'], [$manifest['type'], $manifest['name'], $manifest['plugin_api'], $manifest['core']]);
        $this->assertSame(AppCategory::Selling, AppManifest::fromArray($manifest, $dir)->category);

        // composer に入れずに読み込み、アプリに登録する（サービスプロバイダが登録簿に載せ、名前・マニフェスト・移行のフォルダを核が確かめる）
        // （composer の読み込みは、一度探して無かったクラスを覚える。コマンドが名前空間の重なりを確かめた時に探しているので、
        // 道筋を足しても読まない。このテストの間だけ自前で読む）
        spl_autoload_register(static function (string $class) use ($dir): void {
            $prefix = 'EcSugar\\PluginScaffoldProbe\\';

            if (str_starts_with($class, $prefix) && is_file($file = $dir.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php')) {
                require $file;
            }
        });
        $this->app->register('EcSugar\\PluginScaffoldProbe\\ScaffoldProbeServiceProvider');

        $definition = app(PluginRegistry::class)->get('scaffold-probe');
        $this->assertSame(['ひな形の見本', AppCategory::Selling], [$definition->title, $definition->app()->category]);
        $this->assertNotNull($definition->storefrontRoutes);
        $this->assertInstanceOf(AdminPageHandler::class, $definition->adminPage);
        $this->assertInstanceOf(AdminFormHandler::class, $definition->adminPage);
        $this->assertSame('ひな形の見本', $definition->adminPage->title());
        $this->assertContains(realpath($dir.'/database/migrations'), (array) config('tenancy.migration_parameters.--path'));
    }

    public function test_it_refuses_bad_names_categories_and_titles_without_writing(): void
    {
        foreach (['Stamp', 'stamp_card', '-stamp', 'stamp-', 'stamp--card', '1stamp', 's', str_repeat('a', AppScaffold::NAME_MAX + 1), 'stamp.card', 'スタンプ'] as $name) {
            $this->artisan('make:plugin', ['name' => $name])->expectsOutputToContain('名前は英小文字で始まり')->assertExitCode(1);
        }

        // 入っている App・核の画面の名前空間と重なる名前
        $this->artisan('make:plugin', ['name' => 'faq'])->expectsOutputToContain('App faq はもう入っています')->assertExitCode(1);
        $this->artisan('make:plugin', ['name' => 'theme-default'])->expectsOutputToContain('画面の名前空間')->assertExitCode(1);

        $this->artisan('make:plugin', ['name' => 'stamp-card', '--category' => 'Selling'])->expectsOutputToContain('目的は analytics')->assertExitCode(1);
        $this->artisan('make:plugin', ['name' => 'stamp-card', '--category' => 'nope'])->assertExitCode(1);

        foreach (["スタンプ'カード", 'スタンプ"カード', '<b>スタンプ</b>', 'スタンプ{{ x }}', '@php', 'a\\b', '$x', "1 行目\n2 行目", ' 前の空白', "見えない\u{202E}文字", '', str_repeat('あ', AppScaffold::TITLE_MAX + 1)] as $title) {
            $this->artisan('make:plugin', ['name' => 'stamp-card', '--title' => $title])->expectsOutputToContain('表示名は')->assertExitCode(1);
        }

        $this->assertSame([], $this->written());
    }

    public function test_it_never_overwrites_and_refuses_names_whose_files_or_table_exist(): void
    {
        // App のフォルダがある
        File::ensureDirectoryExists($this->root.'/plugins/plugin-stamp-card');
        File::put($this->root.'/plugins/plugin-stamp-card/README.md', 'mine');
        $this->artisan('make:plugin', ['name' => 'stamp-card'])->expectsOutputToContain('はもうあります（上書きしません）')->assertExitCode(1);
        $this->assertSame('mine', File::get($this->root.'/plugins/plugin-stamp-card/README.md'));

        // テストのファイルだけがある（App のフォルダも作らない）
        File::ensureDirectoryExists($this->root.'/tests/Feature/Plugin');
        File::put($this->root.'/tests/Feature/Plugin/PointCardAppTest.php', 'mine');
        $this->artisan('make:plugin', ['name' => 'point-card'])->expectsOutputToContain('tests/Feature/Plugin/PointCardAppTest.php はもうあります')->assertExitCode(1);
        $this->assertDirectoryDoesNotExist($this->root.'/plugins/plugin-point-card');

        // 表の名前がもう使われている（核の移行にも App の移行にも）
        File::ensureDirectoryExists($this->root.'/database/migrations');
        File::put($this->root.'/database/migrations/2026_01_01_000000_x.php', "Schema::create('coin_card_items', fn () => null);");
        $this->artisan('make:plugin', ['name' => 'coin-card'])->expectsOutputToContain('表 coin_card_items はもうあります')->assertExitCode(1);
        $this->assertDirectoryDoesNotExist($this->root.'/plugins/plugin-coin-card');

        $this->assertSame(['database/migrations/2026_01_01_000000_x.php', 'plugins/plugin-stamp-card/README.md', 'tests/Feature/Plugin/PointCardAppTest.php'], $this->written());
    }

    public function test_it_does_not_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('make:plugin', ['name' => 'stamp-card'])->expectsOutputToContain('本番ではひな形を作りません')->assertExitCode(1);
        $this->assertSame([], $this->written());
    }

    /**
     * 一時のフォルダにあるファイル（ルートからの道筋。名前の順）
     *
     * @return list<string>
     */
    private function written(): array
    {
        $files = array_map(fn (\SplFileInfo $file): string => substr($file->getPathname(), strlen($this->root) + 1), File::allFiles($this->root, true));
        sort($files);

        return $files;
    }
}
