<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Section\SectionSchema;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\OperatorSession;
use Tests\Support\RegistersSampleTheme;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * テーマの区画の値（ADR 0028 区切り 2）。宣言（theme.json）どおりの値だけを下書きに入れ、公開したものだけをショップに出す。
 * 同時の編集は改訂で断り、使っている写真は消せない。値は店の中にだけある。
 * 管理画面から中身を入れる口はページの区画の並び（…/pages/{ページ}。ADR 0039）だけ。前の「種類 => 値」の口（…/content）は で外した
 */
final class ThemeContentTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_draft_reaches_the_shop_only_when_published(): void
    {
        $this->tenant('tca')->forceFill(['theme' => 'sample'])->save();
        $this->signIn();
        $photo = $this->photo('tca', '鉢の写真');
        [$active, $draft] = $this->inside('tca', fn (): array => [$this->listing('アガベ', ListingStatus::Active)->id, $this->listing('下書きの鉢', ListingStatus::Draft)->id]);

        // まだバージョンが無い: 宣言とテーマの最初の並びを返し、読むだけでは作らない
        $this->send('GET', '/api/tenants/tca/themes/sample/pages/home')->assertOk()
            ->assertJsonPath('theme.name', 'sample')
            ->assertJsonPath('theme.active', true)
            ->assertJsonPath('schema.hero.fields.heading', ['type' => 'text', 'label' => '見出し', 'max' => 60])
            ->assertJsonPath('schema.features.items.max', 3)
            ->assertJsonPath('version', null);
        $this->assertSame(0, $this->inside('tca', fn (): int => ThemeVersion::query()->count()));
        $this->get('http://tca.localhost/')->assertOk()->assertSee('<h1 data-test="hero-heading">店 tca</h1>', false);
        tenancy()->end();

        // 最初の保存で「標準」のバージョンができる
        $sections = [
            ['type' => 'announcement', 'values' => ['text' => '送料無料のお知らせ']],
            ['type' => 'hero', 'values' => ['heading' => '<b>春の植え替え</b>', 'body' => "鉢と土を\nそろえました", 'image' => $photo, 'link' => '/products?sort=new']],
            ['type' => 'features', 'items' => [['title' => '鉢', 'image' => $photo, 'tone' => 'dark'], ['title' => '土']]],
            ['type' => 'rail', 'values' => ['title' => 'おすすめ', 'products' => ['source' => 'pick', 'ids' => [$draft, $active]]]],
        ];
        $saved = $this->savePage('tca', null, 0, $sections)->assertOk()
            ->assertJsonPath('version.name', ThemeContentService::DEFAULT_NAME)
            ->assertJsonPath('version.revision', 1)
            ->assertJsonPath('version.published_at', null)
            ->assertJsonPath('version.unpublished_changes', true)
            ->assertJsonPath('sections.1.values.heading', '<b>春の植え替え</b>')
            ->assertJsonPath('sections.2.items.1', ['title' => '土', 'image' => null, 'tone' => null]);
        $versionId = (string) $saved->json('version.id');

        // 下書きはお客さんに出ない
        $this->get('http://tca.localhost/')->assertOk()->assertDontSee('春の植え替え')->assertDontSee('data-test="announcement"', false);
        tenancy()->end();

        $this->publish('tca', $versionId, 1)->assertOk()
            ->assertJsonPath('version.revision', 2)
            ->assertJsonPath('version.unpublished_changes', false);
        $this->assertNotNull($this->inside('tca', fn () => ThemeVersion::query()->findOrFail($versionId)->published_at));

        $page = $this->get('http://tca.localhost/')->assertOk();
        tenancy()->end();
        // 文はエスケープされ、改行はそのまま。写真は置き場の道筋、リンクは確かめた形のまま
        $page->assertSee('<p data-test="announcement">送料無料のお知らせ</p>', false)
            ->assertSee('<h1 data-test="hero-heading">&lt;b&gt;春の植え替え&lt;/b&gt;</h1>', false)
            ->assertSee("鉢と土を\nそろえました")
            ->assertSee('src="/media/shop/'.$photo.'/large"', false)
            ->assertSee('href="/products?sort=new"', false)
            ->assertSee('<article data-test="feature" data-tone="dark">', false)
            ->assertSee('alt="鉢の写真"', false)
            // 選んだ商品は公開中のものだけ
            ->assertSee('<p data-test="rail-item">アガベ</p>', false)
            ->assertDontSee('下書きの鉢');

        // 公開のあとに下書きを変えても、ショップは公開したまま
        $this->savePage('tca', $versionId, 2, [['type' => 'hero', 'hidden' => true]])->assertOk()
            ->assertJsonPath('version.unpublished_changes', true);
        $this->get('http://tca.localhost/')->assertOk()->assertSee('data-test="hero"', false);
        tenancy()->end();
        // 隠した区画は出ない
        $this->publish('tca', $versionId, 3)->assertOk();
        $this->get('http://tca.localhost/')->assertOk()->assertDontSee('data-test="hero"', false)->assertDontSee('data-test="announcement"', false);
        tenancy()->end();

        // 記録には文言を入れず、区画の名前と改訂だけ
        $events = $this->inside('tca', fn (): array => DomainEvent::query()->where('aggregate_type', 'theme_version')->orderBy('sequence')->get()->map(static fn (DomainEvent $e): array => [$e->type, $e->payload])->all());
        $this->assertSame(['theme.version_created', 'theme.draft_saved', 'theme.published', 'theme.draft_saved', 'theme.published'], array_column($events, 0));
        $this->assertSame(['page' => 'home', 'theme' => 'sample', 'revision' => 1, 'sections' => ['announcement', 'hero', 'features', 'rail']], $events[1][1]);
        // バージョンの名前（店の人の自由記述になる）も入れない
        $this->assertSame(['theme' => 'sample'], $events[0][1]);
        $this->assertStringNotContainsString('植え替え', (string) json_encode($events, JSON_UNESCAPED_UNICODE));
    }

    public function test_concurrent_edits_are_refused_by_revision(): void
    {
        $this->tenant('tcb');
        $this->signIn();
        $first = $this->savePage('tcb', null, 0, [['type' => 'hero', 'values' => ['heading' => 'A']]])->assertOk();
        $versionId = (string) $first->json('version.id');

        // 別の画面がまだバージョンが無いと思って作ろうとした
        $this->savePage('tcb', null, 0, [['type' => 'hero', 'values' => ['heading' => 'B']]])
            ->assertStatus(409)->assertJsonPath('version_id', $versionId)->assertJsonPath('current_revision', 1);
        // 古い改訂で保存・公開
        $this->savePage('tcb', $versionId, 0, [])->assertStatus(409);
        $this->publish('tcb', $versionId, 0)->assertStatus(409);
        // 無いバージョン・別のテーマのバージョン
        $this->savePage('tcb', '01jbx0000000000000000000aa', 1, [])->assertStatus(409);

        $this->assertSame('A', $this->inside('tcb', fn (): mixed => ThemeVersion::query()->findOrFail($versionId)->draft['pages']['home'][0]['values']['heading']));
        $this->assertSame(1, $this->inside('tcb', fn (): int => ThemeVersion::query()->count()));
    }

    public function test_values_are_checked_against_the_declaration_and_the_shops_own_photos(): void
    {
        $this->tenant('tcc');
        $this->tenant('tcd');
        $this->signIn();
        $elsewhere = $this->photo('tcd');

        $save = fn (array $sections): TestResponse => $this->savePage('tcc', null, 0, $sections);

        $save([['type' => 'hero', 'values' => ['link' => 'javascript:alert(document.cookie)']]])->assertStatus(422)->assertJsonValidationErrors(['sections.0.values.link']);
        $save([['type' => 'footer', 'values' => []]])->assertStatus(422)->assertJsonValidationErrors(['sections.0.type']);
        $save([['type' => 'hero', 'values' => ['heading' => str_repeat('あ', 61)]]])->assertStatus(422);
        // 別の店の写真は、この店の置き場に無い
        $save([['type' => 'hero', 'values' => ['image' => $elsewhere]]])->assertStatus(422)->assertJsonValidationErrors(['sections']);
        // 上限いっぱいは通る
        $save([['type' => 'features', 'items' => array_fill(0, 3, ['title' => str_repeat('あ', 30)])], ['type' => 'hero', 'values' => ['body' => str_repeat('あ', 200)]]])->assertOk();
        // 宣言の上限の内でも、バージョンの全体が大きすぎれば断る（30 区画 × 20 欄 × 2000 文字）
        $wide = SectionSchema::fromArray(array_fill_keys(array_map(static fn (int $i): string => "s{$i}", range(1, 30)),
            ['label' => '広い', 'fields' => array_fill_keys(array_map(static fn (int $i): string => "f{$i}", range(1, 20)), ['type' => 'textarea', 'label' => '文'])]));
        $this->inside('tcc', function () use ($wide): void {
            try {
                app(ThemeContentService::class)->saveDraft('wide', $wide, null, 0, array_map(static fn (): array => ['values' => array_fill_keys(array_map(static fn (int $i): string => "f{$i}", range(1, 20)), str_repeat('あ', 2000))], $wide->sections), 'cli:test');
                $this->fail('大きすぎる中身を受け付けてしまった');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('sections', $e->errors());
            }
        });
        // 宣言に無いテーマ・入っていないテーマ・形の違う名前
        $this->send('GET', '/api/tenants/tcc/themes/nope/pages/home')->assertNotFound();
        $this->send('GET', '/api/tenants/tcc/themes/Sample/pages/home')->assertNotFound();
        $this->send('GET', '/api/tenants/tcc/themes/..%2Fsample/pages/home')->assertNotFound();

        // 断ったものは何も残さない（最後の 1 つだけが通った）
        $this->assertSame(1, $this->inside('tcc', fn (): int => ThemeVersion::query()->count()));
        $this->assertSame(0, $this->inside('tcd', fn (): int => ThemeVersion::query()->count()));
    }

    public function test_a_photo_used_by_any_version_cannot_be_removed(): void
    {
        $this->tenant('tce');
        $this->signIn();
        $photo = $this->photo('tce');
        $this->savePage('tce', null, 0, [['type' => 'features', 'items' => [['image' => strtoupper($photo)]]]])->assertOk();

        $this->send('GET', '/api/tenants/tce/shop-media')->assertOk()->assertJsonPath('items.0.used_by', ['theme:sample:標準']);
        $this->send('DELETE', "/api/tenants/tce/shop-media/{$photo}")->assertStatus(422)
            ->assertJsonPath('errors.media.0', 'この写真はテーマ「sample」の「標準」で使われているため削除できません。先にそちらで別の写真に変更するか、写真を外してください。');

        // 下書きから外せば消せる
        $versionId = $this->inside('tce', fn (): string => ThemeVersion::query()->valueOrFail('id'));
        $this->savePage('tce', $versionId, 1, [])->assertOk();
        $this->send('DELETE', "/api/tenants/tce/shop-media/{$photo}")->assertOk();
    }

    public function test_publishing_after_the_theme_changed_keeps_the_draft_as_it_was(): void
    {
        $this->tenant('tci');

        $this->inside('tci', function (): void {
            $service = app(ThemeContentService::class);
            $wide = SectionSchema::fromArray(['hero' => ['label' => 'ヒーロー', 'fields' => ['heading' => ['type' => 'text', 'label' => '見出し'], 'old' => ['type' => 'text', 'label' => '古い欄']]]]);
            $version = $service->saveDraft('sample', $wide, null, 0, ['hero' => ['values' => ['heading' => str_repeat('あ', 80), 'old' => '残す']]], 'cli:test');

            // テーマの更新で欄が無くなり、上限も下がった
            $narrow = SectionSchema::fromArray(['hero' => ['label' => 'ヒーロー', 'fields' => ['heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60]]]]);
            $published = $service->publish('sample', $narrow, $version->id, $version->revision, 'cli:test');

            // 公開には今の宣言に合うものだけ、下書きは元のまま（テーマを戻せば取り戻せる）
            $this->assertSame(['heading' => null], $published->published['pages']['home'][0]['values'] ?? null);
            $this->assertEquals(['heading' => str_repeat('あ', 80), 'old' => '残す'], $published->fresh()?->draft['pages']['home'][0]['values']);
        });
    }

    public function test_a_theme_without_sections_has_nothing_to_edit(): void
    {
        $this->tenant('tcj');
        $this->signIn();

        // 区画を 1 つも宣言しないテーマ。宣言は空の対応、区画は入れられない
        app(ThemeRegistry::class)->register(new ThemeDefinition('plain', 'セクションの無いテーマ', ThemeRegistry::API_VERSION, 'theme-sample', 'x.css', manifest: base_path('tests/Support/Themes/plain/theme.json')));
        $response = $this->send('GET', '/api/tenants/tcj/themes/plain/pages/home')->assertOk()->assertJsonPath('version', null)->assertJsonPath('sections', []);
        $this->assertStringContainsString('"schema":{}', (string) $response->getContent());
        // 空のバージョンも作らない（作ると、区画のテーマに作り直したときに前の見せ方の控えが写らなくなる）
        foreach ([[], [['type' => 'hero', 'values' => []]]] as $sections) {
            $this->savePage('tcj', null, 0, $sections, 'plain')
                ->assertStatus(422)->assertJsonPath('message', 'このテーマには、編集できるセクションがありません。');
        }
        $this->publish('tcj', '01jbx0000000000000000000aa', 0, 'plain')->assertStatus(422);
        $this->assertSame(0, $this->inside('tcj', fn (): int => ThemeVersion::query()->count()));

        // 店の設定は見せ方の項目を持たない
        $this->send('GET', '/api/tenants/tcj/shop-settings')->assertOk()->assertJsonMissingPath('storefront')->assertJsonMissingPath('theme.sections');
    }

    public function test_staff_with_read_access_can_see_but_not_change_the_values(): void
    {
        $this->tenant('tcf');
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'tcf', 'read', 'test');

        $this->send('GET', '/api/tenants/tcf/themes/sample/pages/home')->assertOk();
        $this->send('GET', '/api/tenants/tcf/themes')->assertOk();
        $this->savePage('tcf', null, 0, [])->assertForbidden();
        $this->publish('tcf', '01jbx0000000000000000000aa', 0)->assertForbidden();
        // 任されていない店は「無い」
        $this->tenant('tcg');
        $this->send('GET', '/api/tenants/tcg/themes/sample/pages/home')->assertNotFound();
        $this->send('GET', '/api/tenants/tcg/themes')->assertNotFound();
        $this->assertSame(0, $this->inside('tcf', fn (): int => ThemeVersion::query()->count()));

        $access->grant($staff, 'tcf', 'operate', 'test');
        $this->savePage('tcf', null, 0, [])->assertOk();
    }

    /**
     * 前の「種類 => 値」の口（…/content。で外した）はもう無い。中身はページの区画の並びの口だけで入れる。
     * 今の形の保存（ThemeContentService::saveDraft）は、今の値を写すコマンドと見本の店のために残っている
     */
    public function test_the_old_keyed_content_routes_are_gone(): void
    {
        $this->tenant('tck');
        $this->signIn();

        $this->send('GET', '/api/tenants/tck/themes/sample/content')->assertNotFound();
        // 書く口は経路ごと無い（GET だけの殻の経路に当たるので 405 になる。どちらでも中身には届かない）
        $this->assertContains($this->send('PUT', '/api/tenants/tck/themes/sample/content/draft', ['version_id' => null, 'revision' => 0, 'sections' => ['hero' => ['values' => ['heading' => 'A']]]])->status(), [404, 405]);
        $this->assertContains($this->send('POST', '/api/tenants/tck/themes/sample/content/publish', ['version_id' => '01jbx0000000000000000000aa', 'revision' => 0])->status(), [404, 405]);
        $this->assertSame(0, $this->inside('tck', fn (): int => ThemeVersion::query()->count()));

        // サービスの今の形の保存は使える（トップの並びに写る。並びのあるテーマでは送らなかった種類は外れる）
        $this->inside('tck', function (): void {
            $schema = app(ThemeRegistry::class)->get('sample')->schema();
            $version = app(ThemeContentService::class)->saveDraft('sample', $schema, null, 0, ['hero' => ['values' => ['heading' => '写した見出し']]], 'cli:test');
            $this->assertSame([['hero', '写した見出し']], array_map(static fn (array $s): array => [$s['type'], $s['values']['heading'] ?? null], $version->draft['pages']['home']));
        });
    }

    public function test_the_database_keeps_the_shape_even_past_the_service(): void
    {
        $this->tenant('tch');

        $this->inside('tch', function (): void {
            $row = fn (array $extra): array => $extra + ['id' => strtolower((string) Str::ulid()), 'theme' => 'sample', 'name' => 'x', 'draft' => '{}', 'revision' => 0, 'created_at' => now(), 'updated_at' => now()];
            $refused = [
                '下書きが並び' => ['draft' => '[]'],
                '公開が文字' => ['published' => '"x"', 'published_at' => now()],
                '公開の時刻だけ' => ['published_at' => now()],
                '公開の時刻が無い' => ['published' => '{}'],
                'テーマの名前が道筋' => ['theme' => '../x'],
                'バージョンの名前が空' => ['name' => '  '],
                'バージョンの名前に制御文字' => ['name' => "a\u{0085}b"],
                '改訂が負' => ['revision' => -1],
                '大きすぎる' => ['draft' => json_encode(['x' => str_repeat('a', 524288)])],
            ];

            foreach ($refused as $case => $extra) {
                try {
                    DB::transaction(static fn () => DB::table('theme_versions')->insert($row($extra)));
                    $this->fail("DB が受け付けてしまった: {$case}");
                } catch (QueryException) {
                }
            }

            // テーマごとに使うバージョンは 1 つだけ
            DB::table('theme_versions')->insert($row(['name' => 'a', 'current' => true]));
            DB::table('theme_versions')->insert($row(['name' => 'b']));
            $this->expectException(QueryException::class);
            DB::table('theme_versions')->insert($row(['name' => 'c', 'current' => true]));
        });
    }

    private function photo(string $tenant, ?string $alt = null): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tc');
        file_put_contents($path, $this->jpeg(400, 200));
        $this->files[] = $path;

        return $this->inside($tenant, fn (): string => app(ShopMediaService::class)->add($path, $alt, 'cli:test')->id);
    }

    private function listing(string $title, ListingStatus $status): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => $status]);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return TestResponse<Response>
     */
    private function savePage(string $tenant, ?string $versionId, int $revision, array $sections, string $theme = 'sample'): TestResponse
    {
        return $this->send('PUT', "/api/tenants/{$tenant}/themes/{$theme}/pages/home/draft", ['version_id' => $versionId, 'revision' => $revision, 'sections' => $sections]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function publish(string $tenant, string $versionId, int $revision, string $theme = 'sample'): TestResponse
    {
        return $this->send('POST', "/api/tenants/{$tenant}/themes/{$theme}/pages/home/publish", ['version_id' => $versionId, 'revision' => $revision]);
    }

    private function signIn(): void
    {
        $this->enrolledOperator();
    }
}
