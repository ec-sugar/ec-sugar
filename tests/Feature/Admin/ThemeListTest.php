<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use InvalidArgumentException;
use Tests\Support\OperatorSession;
use Tests\Support\RegistersSampleTheme;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 「デザイン」のテーマの画面が読むもの: 入っているテーマの札（名前・バージョン・見本の絵）と、
 * 店が使っているテーマ・テーマごとの中身の状態。見本の絵はテーマのパッケージの中の静的な絵で、ログイン中の運営だけに配る
 */
final class ThemeListTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use RegistersSampleTheme;
    use WithTenants;

    private ?string $directory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->registerSampleTheme();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        if ($this->directory !== null) {
            array_map('unlink', glob("{$this->directory}/*") ?: []);
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_the_list_shows_every_theme_with_its_version_screenshot_and_the_state_of_its_content(): void
    {
        $this->tenant('tla')->forceFill(['theme' => 'fashion'])->save();
        $this->enrolledOperator();

        $list = $this->send('GET', '/api/tenants/tla/themes')->assertOk()
            ->assertJsonPath('chosen', 'fashion')
            ->assertJsonPath('active', 'fashion')
            ->assertJsonPath('default', config('storefront.theme'));
        /** @var list<array<string, mixed>> $rows */
        $rows = $list->json('themes');
        $names = array_column($rows, 'name');
        $themes = array_combine($names, $rows);
        $this->assertSame(app(ThemeRegistry::class)->names(), $names);
        $this->assertEqualsCanonicalizing(['default', 'kinari', 'fashion', 'botanica', 'sample'], $names);
        // 既定のテーマの画面の名前は文書・公式サイトと同じ「角砂糖」（識別子は default のまま）
        $this->assertSame('角砂糖', $themes['default']['label']);
        $at = array_search('fashion', $names, true);
        // 公式テーマはパッケージのバージョンと見本の絵を持つ。テスト用のテーマはパッケージで入っていないのでバージョンが無く、絵も無い
        $this->assertSame('0.1.0', $themes['fashion']['version']);
        $this->assertMatchesRegularExpression('#\A/api/themes/fashion/screenshot\?v=[0-9a-f]{12}\z#', (string) $themes['fashion']['screenshot']);
        $this->assertNull($themes['sample']['version']);
        $this->assertNull($themes['sample']['screenshot']);
        $this->assertNull($themes['fashion']['content']);

        // 中身を下書きに保存すると「公開していない変更あり」、公開すると無くなる
        $version = $this->inside('tla', function (): array {
            $schema = app(ThemeRegistry::class)->get('fashion')->schema();
            $content = app(ThemeContentService::class);
            $version = $content->saveDraft('fashion', $schema, null, 0, ['hero' => ['values' => ['heading' => '秋の新作']]], 'cli:test');

            return ['id' => $version->id, 'revision' => $version->revision];
        });
        $this->send('GET', '/api/tenants/tla/themes')->assertOk()
            ->assertJsonPath("themes.{$at}.content", ['version_id' => $version['id'], 'revision' => 1, 'published_at' => null, 'unpublished_changes' => true]);
        $this->send('POST', '/api/tenants/tla/themes/fashion/pages/home/publish', ['version_id' => $version['id'], 'revision' => 1])->assertOk();
        $this->send('GET', '/api/tenants/tla/themes')->assertOk()
            ->assertJsonPath("themes.{$at}.content.revision", 2)
            ->assertJsonPath("themes.{$at}.content.unpublished_changes", false);
        $this->assertNotNull($this->send('GET', '/api/tenants/tla/themes')->json("themes.{$at}.content.published_at"));
    }

    public function test_staff_with_read_access_can_read_the_list_but_not_other_shops(): void
    {
        $this->tenant('tlb');
        $this->tenant('tlc');
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'tlb', 'read', 'test');

        // 選んでいなければ既定のテーマで描く
        $this->send('GET', '/api/tenants/tlb/themes')->assertOk()->assertJsonPath('chosen', null)->assertJsonPath('active', config('storefront.theme'));
        $this->send('GET', '/api/tenants/tlc/themes')->assertNotFound();
        // 選び替えは今までどおり運用の段階から
        $this->send('PUT', '/api/tenants/tlb/shop-settings/theme', ['theme' => 'kinari'])->assertForbidden();
    }

    public function test_the_screenshot_is_served_only_to_signed_in_operators_and_only_when_it_is_an_image(): void
    {
        // ログインしていなければ配らない
        $this->send('GET', '/api/themes/default/screenshot')->assertUnauthorized();

        $this->enrolledOperator();
        $path = (string) app(ThemeRegistry::class)->get('default')->screenshot;
        $response = $this->send('GET', '/api/themes/default/screenshot?v=x')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(base_path('plugins/theme-default/screenshot.jpg'), $path);
        $this->assertSame((string) file_get_contents($path), $response->streamedContent());
        $this->assertStringContainsString('nosniff', (string) $response->headers->get('X-Content-Type-Options'));

        // 絵の無いテーマ・入っていないテーマ・形の違う名前
        $this->send('GET', '/api/themes/sample/screenshot')->assertNotFound();
        $this->send('GET', '/api/themes/nope/screenshot')->assertNotFound();
        $this->send('GET', '/api/themes/Default/screenshot')->assertNotFound();
        $this->send('GET', '/api/themes/..%2Fdefault/screenshot')->assertNotFound();

        // 登録の後に中身が絵でないものに差し替わっていたら配らない（拡張子だけを信じない）
        $this->directory = sys_get_temp_dir().'/theme-list-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        copy(base_path('tests/Support/Themes/sample/theme.json'), "{$this->directory}/theme.json");
        $manifest = (array) json_decode((string) file_get_contents("{$this->directory}/theme.json"), true);
        file_put_contents("{$this->directory}/theme.json", (string) json_encode(['screenshot' => 'shot.png'] + $manifest));
        file_put_contents("{$this->directory}/shot.png", "\x89PNG\r\n\x1a\n".str_repeat('a', 32));
        app(ThemeRegistry::class)->register(new ThemeDefinition('swapped', '差し替わる', 2, 'theme-sample', 'x.css', manifest: "{$this->directory}/theme.json"));
        $this->send('GET', '/api/themes/swapped/screenshot')->assertOk()->assertHeader('Content-Type', 'image/png');
        file_put_contents("{$this->directory}/shot.png", '<svg onload="alert(1)"></svg>');
        $this->send('GET', '/api/themes/swapped/screenshot')->assertNotFound();

        // テーマのフォルダの外を指すシンボリックリンクは、登録で断る
        unlink("{$this->directory}/shot.png");
        symlink(base_path('plugins/theme-default/screenshot.jpg'), "{$this->directory}/shot.png");
        $this->expectException(InvalidArgumentException::class);
        app(ThemeRegistry::class)->register(new ThemeDefinition('linked', 'リンク', 2, 'theme-sample', 'x.css', manifest: "{$this->directory}/theme.json"));
    }
}
