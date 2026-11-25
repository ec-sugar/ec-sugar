<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Section\PageSpec;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\OperatorSession;
use Tests\Support\RegistersSampleTheme;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ページの区画の並び（ADR 0039 区切り 1）: 足す・消す・並べ替え・パターンの選び直しを下書きに置き換え、公開した順にショップに出す。
 * テーマが宣言していない種類・パターン・ブロック・欄は断り、数と大きさの上限は核と DB で守る。今の書き方のテーマと今の値はそのまま
 */
final class ThemePagesTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use OperatorSession;
    use RegistersSampleTheme;
    use WithTenants;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        FakeMedia::fake();
        $this->registerSampleTheme();
        // 今の書き方（pages を書かない）のテーマ。公式テーマはどれも並びの形になった（ADR 0039 区切り 4）ので、決まった並びはこれで確かめる
        app(ThemeRegistry::class)->register(new ThemeDefinition('fixed', '決まった並びのテーマ', 2, 'theme-sample', 'x.css', manifest: base_path('tests/Support/Themes/fixed/theme.json')));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_adding_reordering_removing_and_repatterning_change_the_shop_once_published(): void
    {
        $this->tenant('tpa')->forceFill(['theme' => 'sample'])->save();
        $this->enrolledOperator();
        $photo = $this->photo('tpa');

        // まだバージョンが無い: テーマの最初の並び（ID は種類の名前）を空の値で。読むだけでは作らない
        $first = $this->send('GET', '/api/tenants/tpa/themes/sample/pages/home')->assertOk()
            ->assertJsonPath('page.name', 'home')
            ->assertJsonPath('page.fixed', false)
            ->assertJsonPath('page.max_sections', PageSpec::MAX_SECTIONS)
            ->assertJsonPath('schema.hero.patterns', ['full' => '全面', 'split' => '左右', 'grid' => 'グリッド'])
            ->assertJsonPath('schema.hero.max_blocks', 3)
            // 見本（編集の画面が足した区画に入れる）。宣言の欄をそろえ、写真は空。書かない種類（shelves）には出さない
            ->assertJsonPath('schema.hero.example.values', ['heading' => '見出しを入れてください', 'body' => 'ここに文を入れてください。', 'image' => null, 'link' => '/products'])
            ->assertJsonPath('schema.hero.example_blocks', [['type' => 'button', 'values' => ['label' => 'くわしく見る', 'link' => '/products']]])
            ->assertJsonPath('schema.features.example.items.1', ['title' => '2 つ目の特集', 'image' => null, 'tone' => 'dark'])
            ->assertJsonPath('schema.rail.example.values.products', ['source' => 'new', 'category' => null, 'ids' => []])
            ->assertJsonMissingPath('schema.shelves.example')
            ->assertJsonPath('version', null);
        $this->assertSame(['announcement', 'hero', 'features', 'rail', 'shelves'], array_column($first->json('sections'), 'id'));
        $this->assertSame('full', $first->json('sections.1.pattern'));
        $this->assertSame(0, $this->inside('tpa', fn (): int => ThemeVersion::query()->count()));
        // ショップも最初の並び（お知らせの帯は部品が無いのでページには出ず、外枠が読む）
        $this->assertSame(['hero:full', 'rail'], $this->order('tpa'));

        // 足す（ID 無し）・並べ替え・パターンの選び直し・ブロック・消す（features と shelves を外す）をまとめて
        $saved = $this->savePage('tpa', null, 0, [
            ['type' => 'rail', 'values' => ['title' => '新着']],
            ['id' => 'hero', 'type' => 'hero', 'pattern' => 'split', 'values' => ['heading' => '春の植え替え'],
                'blocks' => [['type' => 'button', 'values' => ['label' => '見る', 'link' => '/products']], ['type' => 'photo', 'values' => ['image' => strtoupper($photo)]]]],
            ['type' => 'hero', 'pattern' => 'grid', 'values' => ['heading' => '二つ目のヒーロー']],
            ['id' => 'announcement', 'type' => 'announcement', 'values' => ['text' => '送料無料']],
        ])->assertOk()
            ->assertJsonPath('version.revision', 1)
            ->assertJsonPath('version.unpublished_changes', true)
            ->assertJsonPath('sections.1.id', 'hero')
            ->assertJsonPath('sections.1.blocks.1.values.image', $photo);
        $sections = $saved->json('sections');
        $this->assertSame(['rail', 'hero', 'hero', 'announcement'], array_column($sections, 'type'));
        // 足した区画には ID が付く（ページの中で重ならない）
        $this->assertMatchesRegularExpression('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $sections[0]['id']);
        $this->assertCount(4, array_unique(array_column($sections, 'id')));
        $versionId = (string) $saved->json('version.id');

        // 下書きはお客さんに出ない
        $this->assertSame(['hero:full', 'rail'], $this->order('tpa'));

        $this->send('POST', '/api/tenants/tpa/themes/sample/pages/home/publish', ['version_id' => $versionId, 'revision' => 1])->assertOk()
            ->assertJsonPath('version.revision', 2)
            ->assertJsonPath('version.unpublished_changes', false);
        // 公開した順に、種類 × パターンの部品で（格子は部品が無いので種類だけの部品で）
        $this->assertSame(['rail', 'hero:split', 'hero:grid:fallback'], $this->order('tpa'));
        $page = $this->get('http://tpa.localhost/')->assertOk()
            ->assertSee('<p data-test="announcement">送料無料</p>', false)
            ->assertSee('<a data-test="hero-button" href="/products">見る</a>', false)
            ->assertSee('src="/media/shop/'.$photo.'/medium"', false)
            ->assertSee('二つ目のヒーロー');
        tenancy()->end();
        // $sections->get は種類ごとの最初の区画（外枠のお知らせの帯もこれで読む）
        $this->assertStringContainsString('data-section="hero"', (string) $page->getContent());

        // 隠す・消す（2 つ目のヒーローを外し、新着を隠す）。値はそのまま
        $sections[0]['hidden'] = true;
        unset($sections[2]);
        $this->savePage('tpa', $versionId, 2, array_values($sections))->assertOk();
        $this->send('POST', '/api/tenants/tpa/themes/sample/pages/home/publish', ['version_id' => $versionId, 'revision' => 3])->assertOk();
        $this->assertSame(['hero:split'], $this->order('tpa'));

        // 記録には文言を入れず、ページと種類の並びと改訂だけ
        $events = $this->inside('tpa', fn (): array => DomainEvent::query()->where('aggregate_type', 'theme_version')->where('type', 'theme.draft_saved')->orderBy('sequence')->pluck('payload')->all());
        $this->assertEquals(['theme' => 'sample', 'revision' => 1, 'page' => 'home', 'sections' => ['rail', 'hero', 'hero', 'announcement']], $events[0]);
        $this->assertStringNotContainsString('植え替え', (string) json_encode($events, JSON_UNESCAPED_UNICODE));

        // 使っている写真（ブロックの中）は消せない
        $this->send('DELETE', "/api/tenants/tpa/shop-media/{$photo}")->assertStatus(422);
    }

    public function test_what_the_theme_does_not_declare_is_refused(): void
    {
        $this->tenant('tpb');
        $this->tenant('tpc');
        $this->enrolledOperator();
        $elsewhere = $this->photo('tpc');

        $refused = [
            ['sections.0.type', [['type' => 'footer']]],
            ['sections.0.pattern', [['type' => 'hero', 'pattern' => 'diagonal']]],
            ['sections.0.values.subtitle', [['type' => 'hero', 'values' => ['subtitle' => 'x']]]],
            ['sections.0.blocks.0.type', [['type' => 'hero', 'blocks' => [['type' => 'video', 'values' => []]]]]],
            ['sections.0.blocks.0.values.target', [['type' => 'hero', 'blocks' => [['type' => 'button', 'values' => ['target' => '_blank']]]]]],
            ['sections.0.blocks', [['type' => 'rail', 'blocks' => [['type' => 'button', 'values' => []]]]]],
            ['sections.0.html', [['type' => 'hero', 'html' => '<script>alert(1)</script>']]],
            ['sections.0.values.link', [['type' => 'hero', 'values' => ['link' => 'javascript:alert(1)']]]],
            ['sections.1.id', [['id' => 'same', 'type' => 'rail'], ['id' => 'same', 'type' => 'rail']]],
            ['sections.0.id', [['id' => '../x', 'type' => 'rail']]],
            // 種類ごとの上限（お知らせの帯は 1 つ）・ブロックの上限（ヒーローは 3 つ）
            ['sections.1.type', [['type' => 'announcement'], ['type' => 'announcement']]],
            ['sections.0.blocks', [['type' => 'hero', 'blocks' => array_fill(0, 4, ['type' => 'button', 'values' => []])]]],
        ];

        foreach ($refused as [$path, $sections]) {
            $this->savePage('tpb', null, 0, $sections)->assertStatus(422)->assertJsonValidationErrors([$path]);
        }

        // 1 つのページの区画の数（入口と核の両方で）
        $this->savePage('tpb', null, 0, array_fill(0, PageSpec::MAX_SECTIONS + 1, ['type' => 'rail']))->assertStatus(422)->assertJsonValidationErrors(['sections']);
        // 並びでない・別の店の写真
        $this->savePage('tpb', null, 0, ['hero' => ['type' => 'hero']])->assertStatus(422);
        $this->savePage('tpb', null, 0, [['type' => 'hero', 'blocks' => [['type' => 'photo', 'values' => ['image' => $elsewhere]]]]])->assertStatus(422)->assertJsonValidationErrors(['sections']);
        // 無いページ・無いテーマ
        $this->send('GET', '/api/tenants/tpb/themes/sample/pages/about')->assertNotFound();
        $this->send('GET', '/api/tenants/tpb/themes/nope/pages/home')->assertNotFound();
        $this->send('GET', '/api/tenants/tpb/themes/sample/pages/..%2Fhome')->assertNotFound();

        // 上限いっぱいは通る
        $this->savePage('tpb', null, 0, [...array_fill(0, PageSpec::MAX_SECTIONS - 1, ['type' => 'rail']), ['type' => 'hero', 'blocks' => array_fill(0, 3, ['type' => 'button', 'values' => []])]])->assertOk();
        $this->assertSame(1, $this->inside('tpb', fn (): int => ThemeVersion::query()->count()));
    }

    public function test_concurrent_edits_are_refused(): void
    {
        $this->tenant('tpd');
        $this->enrolledOperator();
        $saved = $this->savePage('tpd', null, 0, [['type' => 'rail', 'values' => ['title' => 'A']]])->assertOk();
        $versionId = (string) $saved->json('version.id');

        // まだ無いと思って作る・古い改訂で保存・公開
        $this->savePage('tpd', null, 0, [['type' => 'rail']])->assertStatus(409)->assertJsonPath('version_id', $versionId)->assertJsonPath('current_revision', 1);
        $this->savePage('tpd', $versionId, 0, [])->assertStatus(409);
        $this->send('POST', '/api/tenants/tpd/themes/sample/pages/home/publish', ['version_id' => $versionId, 'revision' => 0])->assertStatus(409);
        $this->assertSame(['rail'], $this->inside('tpd', fn (): array => array_column(ThemeVersion::query()->sole()->draft['pages']['home'], 'type')));
    }

    public function test_staff_with_read_access_can_see_but_not_change_the_page(): void
    {
        $this->tenant('tph');
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'tph', 'read', 'test');

        $this->send('GET', '/api/tenants/tph/themes/sample/pages/home')->assertOk();
        $this->savePage('tph', null, 0, [])->assertForbidden();
        $this->send('POST', '/api/tenants/tph/themes/sample/pages/home/publish', ['version_id' => '01jbx0000000000000000000aa', 'revision' => 0])->assertForbidden();
        $this->assertSame(0, $this->inside('tph', fn (): int => ThemeVersion::query()->count()));
    }

    public function test_a_theme_written_the_current_way_keeps_its_fixed_order(): void
    {
        $this->tenant('tpe')->forceFill(['theme' => 'fixed'])->save();
        $this->enrolledOperator();

        // pages を書かないテーマは、宣言した区画を宣言の順に 1 つずつ・パターンは default の 1 つ
        $page = $this->send('GET', '/api/tenants/tpe/themes/fixed/pages/home')->assertOk()->assertJsonPath('page.fixed', true);
        $order = array_keys(app(ThemeRegistry::class)->get('fixed')->schema()->sections);
        $this->assertSame($order, array_column($page->json('sections'), 'type'));
        $this->assertSame(['default'], array_values(array_unique(array_column($page->json('sections'), 'pattern'))));

        // 値と隠す・出すは変えられ、足す・消す・並べ替えは断る
        $sections = $page->json('sections');
        $sections[1]['values']['heading'] = '秋の新作';
        $this->savePage('tpe', null, 0, $sections, 'fixed')->assertOk();
        $this->savePage('tpe', null, 0, array_reverse($sections), 'fixed')->assertStatus(422)->assertJsonValidationErrors(['sections']);
        $this->savePage('tpe', null, 0, [...$sections, ['type' => 'hero']], 'fixed')->assertStatus(422);
        $this->savePage('tpe', null, 0, array_slice($sections, 1), 'fixed')->assertStatus(422);

        $versionId = $this->inside('tpe', fn (): string => ThemeVersion::query()->valueOrFail('id'));
        $this->send('POST', '/api/tenants/tpe/themes/fixed/pages/home/publish', ['version_id' => $versionId, 'revision' => 1])->assertOk();
        $this->get('http://tpe.localhost/')->assertOk()->assertSee('秋の新作');
        tenancy()->end();
    }

    /**
     * 今の値（{ sections: { 区画: 値 } }）は、移行でトップの並びに写り、ショップの見た目も「公開していない変更」の印も変わらない
     */
    public function test_the_migration_keeps_the_current_values_and_the_look_of_the_shop(): void
    {
        $this->tenant('tpf')->forceFill(['theme' => 'fixed'])->save();
        $migration = require database_path('migrations/tenant/2026_10_30_000500_theme_versions_to_page_sections.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $this->assertTrue(method_exists($migration, 'up') && method_exists($migration, 'down'));

        $this->inside('tpf', function (): void {
            $content = app(ThemeContentService::class);
            $schema = app(ThemeRegistry::class)->get('fixed')->schema();
            $version = $content->saveDraft('fixed', $schema, null, 0, [
                'announcement' => ['values' => ['text' => '送料無料のお知らせ']],
                'hero' => ['values' => ['heading' => "秋の\n新作", 'body' => '羽織りものをそろえました']],
                'features' => ['items' => [['tone' => 'dark', 'title' => '返品OK']]],
                'rail' => ['hidden' => true],
            ], 'cli:test');
            $version = $content->publish('fixed', $schema, $version->id, $version->revision, 'cli:test');
            // 公開のあとに下書きだけ変える（下書きと公開が別々に写ることを見る）
            $content->saveDraft('fixed', $schema, $version->id, $version->revision, ['hero' => ['values' => ['heading' => '下書きの見出し']]], 'cli:test');
        });
        $before = $this->shopHtml('tpf');
        [$draft, $published] = $this->inside('tpf', fn (): array => [ThemeVersion::query()->sole()->draft, ThemeVersion::query()->sole()->published]);
        $this->assertStringContainsString('送料無料のお知らせ', $before);

        // 前の形に戻すと、今のアプリが書いていた形（区画の名前 => 値）になる
        $this->inside('tpf', function () use ($migration): void {
            $migration->down();
            $old = ThemeVersion::query()->sole();
            $this->assertArrayNotHasKey('pages', $old->draft);
            $this->assertSame('下書きの見出し', $old->draft['sections']['hero']['values']['heading']);
            $this->assertSame("秋の\n新作", $old->published['sections']['hero']['values']['heading'] ?? null);
            $this->assertTrue($old->published['sections']['rail']['hidden'] ?? null);
            $this->assertSame('返品OK', $old->published['sections']['features']['items'][0]['title'] ?? null);
            $migration->up();
        });

        // 移行の後: 値は同じ、ショップの見た目も同じ
        $after = $this->inside('tpf', fn (): array => [ThemeVersion::query()->sole()->draft, ThemeVersion::query()->sole()->published]);
        $this->assertSame([$draft, $published], $after);
        $this->assertSame($before, $this->shopHtml('tpf'));

        // 何も入れていなかったバージョンは、ページを保存していない形（テーマの最初の並び）に。前の形はもう DB が受け付けない
        $this->inside('tpf', function () use ($migration): void {
            $migration->down();
            DB::table('theme_versions')->insert(['id' => strtolower((string) Str::ulid()), 'theme' => 'kinari', 'name' => '標準', 'current' => true, 'draft' => '{"sections": {}}', 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $migration->up();
            $this->assertSame(['pages' => []], ThemeVersion::query()->where('theme', 'kinari')->sole()->draft);
            $this->expectException(QueryException::class);
            DB::table('theme_versions')->where('theme', 'kinari')->update(['draft' => '{"sections": {}}']);
        });
    }

    public function test_the_database_keeps_the_shape_of_the_pages(): void
    {
        $this->tenant('tpg');

        $this->inside('tpg', function (): void {
            $section = static fn (array $extra = []): array => $extra + ['id' => 'hero', 'type' => 'hero', 'pattern' => 'full', 'hidden' => false, 'values' => [], 'items' => [], 'blocks' => []];
            $row = static fn (mixed $draft): array => ['id' => strtolower((string) Str::ulid()), 'theme' => 'sample', 'name' => 'x', 'draft' => json_encode($draft), 'revision' => 0, 'created_at' => now(), 'updated_at' => now()];
            $refused = [
                '前の形' => ['sections' => []],
                'ページが並び' => ['pages' => [[]]],
                'ページの名前が道筋' => ['pages' => ['../x' => []]],
                'セクションが対応' => ['pages' => ['home' => ['hero' => $section()]]],
                'セクションが多すぎる' => ['pages' => ['home' => array_map(static fn (int $i): array => $section(['id' => "s{$i}"]), range(1, PageSpec::MAX_SECTIONS + 1))]],
                'ID が重なる' => ['pages' => ['home' => [$section(), $section()]]],
                'ID の形' => ['pages' => ['home' => [$section(['id' => 'A B'])]]],
                'ID が無い' => ['pages' => ['home' => [array_diff_key($section(), ['id' => true])]]],
                '種類の形' => ['pages' => ['home' => [$section(['type' => '<b>'])]]],
                'パターンが数' => ['pages' => ['home' => [$section(['pattern' => 1])]]],
                'ブロックが多すぎる' => ['pages' => ['home' => [$section(['blocks' => array_fill(0, 21, ['type' => 'button', 'values' => []])])]]],
            ];

            foreach ($refused as $case => $draft) {
                try {
                    DB::transaction(static fn () => DB::table('theme_versions')->insert($row($draft)));
                    $this->fail("DB が受け付けてしまった: {$case}");
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }

            DB::table('theme_versions')->insert($row(['pages' => ['home' => array_map(static fn (int $i): array => $section(['id' => "s{$i}"]), range(1, PageSpec::MAX_SECTIONS))]]));
            $this->assertSame(1, ThemeVersion::query()->count());
        });
    }

    /**
     * ショップのトップの区画の並び（部品の印から。種類:パターン、種類だけの部品なら :fallback）
     *
     * @return list<string>
     */
    private function order(string $tenant): array
    {
        $html = $this->shopHtml($tenant);
        preg_match_all('/data-test="(hero|rail)"(?: data-pattern="([a-z]+)")?( data-fallback)?/', $html, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): string => $m[1].(($m[2] ?? '') !== '' ? ":{$m[2]}" : '').(($m[3] ?? '') !== '' ? ':fallback' : ''), $matches);
    }

    private function shopHtml(string $tenant): string
    {
        $html = (string) $this->get("http://{$tenant}.localhost/")->assertOk()->getContent();
        tenancy()->end();

        // 要求ごとに変わる値（CSRF の合い言葉・CSP の nonce）は比べない
        return (string) preg_replace(['/name="_token" value="[^"]*"/', '/nonce="[^"]*"/', '/<meta name="csrf-token" content="[^"]*">/'], '', $html);
    }

    /**
     * @param  array<mixed>  $sections
     * @return TestResponse<Response>
     */
    private function savePage(string $tenant, ?string $versionId, int $revision, array $sections, string $theme = 'sample'): TestResponse
    {
        return $this->send('PUT', "/api/tenants/{$tenant}/themes/{$theme}/pages/home/draft", ['version_id' => $versionId, 'revision' => $revision, 'sections' => $sections]);
    }

    private function photo(string $tenant): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tp');
        file_put_contents($path, $this->jpeg(400, 200));
        $this->files[] = $path;

        return $this->inside($tenant, fn (): string => app(ShopMediaService::class)->add($path, null, 'cli:test')->id);
    }
}
