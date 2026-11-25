<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Catalog\Models\Category;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Section\Services\ShopPageService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * テーマの区画で使っているカテゴリは消せない。下書き・公開・自由なページ・核の区画・古いバージョンのどれで使っていても断り、
 * 使っている所（ページと区画の名前）を文で返す。ID の場所は欄の種類で決める（文の欄に同じ文字があっても数えない）。ほかの店の値は見ない
 */
final class CategoryInThemeTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_category_picked_in_any_draft_or_published_section_cannot_be_deleted(): void
    {
        $this->tenant('cta')->forceFill(['theme' => 'default'])->save();
        $this->enrolledOperator();
        $knit = $this->add('cta', 'ニット', 'knit');
        $other = $this->add('cta', 'アウター', 'outer');

        // 下書き: トップの「カテゴリ」の区画（カテゴリの並びの欄）
        $this->save('cta', 'home', [['type' => 'categories', 'values' => ['list' => [$other, $knit]]]]);
        $this->refused('cta', $knit, 'このカテゴリは『トップ』の『カテゴリ』で使われています。');

        // 公開中だけ: 公開した後、下書きから外した（公開すると外れる、と添える）
        $this->save('cta', 'home', [['type' => 'categories', 'values' => ['list' => [$knit]]]], publish: true);
        $this->save('cta', 'home', [['type' => 'categories', 'values' => ['list' => [$other]]]]);
        $message = $this->refused('cta', $knit, '『トップ』の『カテゴリ』（公開中だけ）');
        $this->assertStringContainsString('「公開中だけ」と書かれた場所は、下書きではすでに外してあるため、公開すると使われなくなります。', $message);

        // 公開すると外れて消せる。使っているアウターは残る
        $this->publish('cta');
        $this->send('DELETE', "/api/tenants/cta/categories/{$knit}")->assertOk();
        $this->inside('cta', fn () => $this->assertFalse(Category::query()->whereKey($knit)->exists()));
        $this->refused('cta', $other, '『トップ』の『カテゴリ』');

        // 核の区画（商品の並びの「カテゴリから」）
        $bag = $this->add('cta', 'かばん', 'bag');
        $this->save('cta', 'home', [['type' => 'basic_products', 'values' => ['heading' => 'かばん', 'products' => ['source' => 'category', 'category' => strtoupper($bag)]]]]);
        $this->refused('cta', $bag, '『トップ』の『商品の並び』');

        // 自由なページ（題を出す）。ゴミ箱に入れても数える（戻せばまた出る）
        $hat = $this->add('cta', '帽子', 'hat');
        $pageName = $this->inside('cta', fn (): string => app(ShopPageService::class)->create(['title' => '春のキャンペーン', 'slug' => 'spring'], 'cli:test')->pageName());
        $this->save('cta', $pageName, [['type' => 'categories', 'values' => ['list' => [$hat]]]]);
        $this->refused('cta', $hat, '『春のキャンペーン』の『カテゴリ』');
        $this->inside('cta', fn () => app(ShopPageService::class)->trash(substr($pageName, strlen('page-')), 'cli:test'));
        $this->refused('cta', $hat, '『春のキャンペーン』の『カテゴリ』（ゴミ箱の中のページ）');

        // 古いバージョンと、店で使っていないテーマ
        $shoe = $this->add('cta', '靴', 'shoe');
        $sock = $this->add('cta', '靴下', 'sock');
        $this->inside('cta', function () use ($shoe, $sock): void {
            $page = static fn (string $id): string => (string) json_encode(['pages' => ['home' => [
                ['id' => 'categories', 'type' => 'categories', 'pattern' => 'default', 'hidden' => false, 'values' => ['list' => [$id]], 'items' => [], 'blocks' => []],
            ]]]);
            DB::table('theme_versions')->insert([
                ['id' => strtolower((string) Str::ulid()), 'theme' => 'default', 'name' => '冬', 'current' => false, 'draft' => $page($shoe),
                    'published' => null, 'published_at' => null, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['id' => strtolower((string) Str::ulid()), 'theme' => 'kinari', 'name' => '標準', 'current' => true, 'draft' => '{"pages": {}}',
                    'published' => $page($sock), 'published_at' => now(), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()],
            ]);
        });
        $kinari = app(ThemeRegistry::class)->get('kinari')->label;
        $this->refused('cta', $shoe, '『トップ』の『カテゴリ』（テーマ「'.app(ThemeRegistry::class)->get('default')->label.'」のバージョン「冬」）');
        $this->refused('cta', $sock, "（テーマ「{$kinari}」・公開中だけ）");

        // 使っていないカテゴリは消せる。文の欄に同じ ID の文字があっても、カテゴリの欄でなければ数えない
        $free = $this->add('cta', 'セール', 'sale');
        $this->save('cta', 'home', [
            ['type' => 'announcement', 'values' => ['text' => $free]],
            ['type' => 'basic_products', 'values' => ['heading' => $free, 'products' => ['source' => 'new']]],
        ]);
        $this->send('DELETE', "/api/tenants/cta/categories/{$free}")->assertOk();

        // 断ったときは消さず、記録も残さない
        $this->inside('cta', function (): void {
            $this->assertSame(2, DomainEvent::query()->where('type', 'category.deleted')->count());
            $this->assertSame(5, Category::query()->count());
        });
    }

    public function test_a_used_child_category_is_refused_and_its_parent_still_waits_for_the_child(): void
    {
        $this->tenant('ctb')->forceFill(['theme' => 'default'])->save();
        $this->enrolledOperator();
        $tops = $this->add('ctb', 'トップス', 'tops');
        $knit = $this->add('ctb', 'ニット', 'knit', $tops);
        $this->save('ctb', 'home', [['type' => 'categories', 'values' => ['list' => [$knit]]]]);

        // 子を使っている: 子は区画で断り、親は今までどおり下にカテゴリがあるので断る
        $this->refused('ctb', $knit, '『トップ』の『カテゴリ』');
        $this->send('DELETE', "/api/tenants/ctb/categories/{$tops}")->assertStatus(422)
            ->assertJsonPath('errors.category.0', '下の階層にカテゴリがあるため削除できません。先に下のカテゴリを移動するか削除してください。');

        // 親を使っている: 子は消せるが、子が無くなった親は区画で断る
        $this->save('ctb', 'home', [['type' => 'categories', 'values' => ['list' => [$tops]]]]);
        $this->send('DELETE', "/api/tenants/ctb/categories/{$knit}")->assertOk();
        $this->refused('ctb', $tops, '『トップ』の『カテゴリ』');
    }

    public function test_only_the_shops_own_sections_count(): void
    {
        $this->tenant('ctc')->forceFill(['theme' => 'default'])->save();
        $this->tenant('ctd')->forceFill(['theme' => 'default'])->save();
        $this->enrolledOperator();
        $knit = $this->add('ctc', 'ニット', 'knit');

        // ほかの店の区画が同じ ID を指していても（その店には無いカテゴリ）、この店の消す判断には入らない。
        // その店には無いカテゴリなので保存の口は断る。しまってある値として直に入れる
        $this->inside('ctd', fn () => DB::table('theme_versions')->insert([
            'id' => strtolower((string) Str::ulid()), 'theme' => 'default', 'name' => '標準', 'current' => true,
            'draft' => (string) json_encode(['pages' => ['home' => [
                ['id' => 'categories', 'type' => 'categories', 'pattern' => 'default', 'hidden' => false, 'values' => ['list' => [$knit]], 'items' => [], 'blocks' => []],
            ]]]),
            'published' => null, 'published_at' => null, 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->send('DELETE', "/api/tenants/ctc/categories/{$knit}")->assertOk();
    }

    public function test_saving_a_category_that_is_not_in_the_shop_is_refused(): void
    {
        $this->tenant('ctf')->forceFill(['theme' => 'default'])->save();
        $this->tenant('ctg')->forceFill(['theme' => 'default'])->save();
        $this->enrolledOperator();
        $knit = $this->add('ctf', 'ニット', 'knit');
        $gone = $this->add('ctf', 'アウター', 'outer');
        $this->send('DELETE', "/api/tenants/ctf/categories/{$gone}")->assertOk();
        $elsewhere = $this->add('ctg', 'かばん', 'bag');

        // ページの編集の口: あるカテゴリは保存できる（大文字で送っても同じ ID）
        $this->send('PUT', '/api/tenants/ctf/themes/default/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'categories', 'values' => ['list' => [strtoupper($knit)]]],
            ['type' => 'basic_products', 'values' => ['heading' => 'ニット', 'products' => ['source' => 'category', 'category' => $knit]]],
        ]])->assertOk();
        $version = $this->inside('ctf', fn () => app(ThemeContentService::class)->current('default'));
        $this->assertNotNull($version);

        // 消したカテゴリ・ほかの店のカテゴリは、その欄の道筋で断る。下書きは変えない
        $this->send('PUT', '/api/tenants/ctf/themes/default/pages/home/draft', ['version_id' => $version->id, 'revision' => $version->revision, 'sections' => [
            ['type' => 'categories', 'values' => ['list' => [$knit, $gone]]],
            ['type' => 'basic_products', 'values' => ['heading' => 'かばん', 'products' => ['source' => 'category', 'category' => $elsewhere]]],
        ]])->assertStatus(422)
            ->assertJsonPath('errors', [
                'sections.0.values.list' => ['「並べるカテゴリ」で選んだカテゴリの中に、ショップに無いものがあります（削除したカテゴリかもしれません）。カテゴリを選び直してください。'],
                'sections.1.values.products.category' => ['「商品」で選んだカテゴリの中に、ショップに無いものがあります（削除したカテゴリかもしれません）。カテゴリを選び直してください。'],
            ]);

        // 今の書き方（種類 => 値）の保存も同じ確かめを通る
        $this->inside('ctf', function () use ($knit, $gone, $version): void {
            $content = app(ThemeContentService::class);
            $schema = app(ThemeRegistry::class)->get('default')->schema();

            try {
                $content->saveDraft('default', $schema, $version->id, $version->revision, ['categories' => ['values' => ['list' => [$gone]]]], 'cli:test');
                $this->fail('無いカテゴリを保存できてしまった');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('sections.0.values.list', $e->errors());
            }

            $this->assertSame($version->revision, $content->current('default')?->revision);
            $content->saveDraft('default', $schema, $version->id, $version->revision, ['categories' => ['values' => ['list' => [$knit]]]], 'cli:test');
        });
    }

    public function test_read_only_staff_are_refused_before_the_check(): void
    {
        $this->tenant('cte')->forceFill(['theme' => 'default'])->save();
        $bag = $this->inside('cte', fn (): string => app(CategoryService::class)->create(['name' => 'かばん', 'slug' => 'bag'], 'cli:test')->id);
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'cte', 'read', 'test');
        $this->reconfirm($staff, $authenticator);

        // 閲覧だけのスタッフは今どおり断る（使っていないカテゴリでも）
        $this->send('DELETE', "/api/tenants/cte/categories/{$bag}")->assertForbidden();
        $this->inside('cte', fn () => $this->assertTrue(Category::query()->whereKey($bag)->exists()));
    }

    /**
     * 消そうとして断られる。文に $expected が入っていることを確かめ、文を返す
     */
    private function refused(string $tenant, string $category, string $expected): string
    {
        $message = (string) $this->send('DELETE', "/api/tenants/{$tenant}/categories/{$category}")->assertStatus(422)->json('errors.category.0');
        $this->assertStringContainsString($expected, $message);
        $this->assertStringContainsString('ページの編集でセクションからこのカテゴリを外してから削除してください。', $message);
        $this->inside($tenant, fn () => $this->assertTrue(Category::query()->whereKey($category)->exists()));

        return $message;
    }

    /**
     * 角砂糖（default）のページの下書きを置き換える（公開もできる）
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private function save(string $tenant, string $page, array $sections, bool $publish = false): void
    {
        $this->inside($tenant, function () use ($page, $sections, $publish): void {
            $content = app(ThemeContentService::class);
            $schema = app(ThemeRegistry::class)->get('default')->schema();
            $version = $content->current('default');
            $saved = $content->savePage('default', $schema, $page, $version?->id, $version->revision ?? 0, $sections, 'cli:test');

            if ($publish) {
                $content->publish('default', $schema, $saved->id, $saved->revision, 'cli:test');
            }
        });
    }

    private function publish(string $tenant): void
    {
        $this->inside($tenant, function (): void {
            $content = app(ThemeContentService::class);
            $version = $content->current('default') ?? throw new \LogicException('バージョンが無い');
            $content->publish('default', app(ThemeRegistry::class)->get('default')->schema(), $version->id, $version->revision, 'cli:test');
        });
    }

    private function add(string $tenant, string $name, string $slug, ?string $parent = null): string
    {
        $this->send('POST', "/api/tenants/{$tenant}/categories", ['name' => $name, 'slug' => $slug, 'parent_id' => $parent])->assertCreated();

        return $this->inside($tenant, fn (): string => (string) Category::query()->where('slug', $slug)->value('id'));
    }
}
