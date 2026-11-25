<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Description\ProductDescription;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Media\Services\ShopMediaService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の商品の説明（ADR 0032）: ブロック・仕様の表・短い説明を、商品の編集と同じ道（バージョン・記録）で保存する。
 */
final class ListingDescriptionAdminTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use OperatorSession;
    use WithTenants;

    private Operator $operator;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_blocks_specs_and_the_summary_are_saved_and_read_back(): void
    {
        $this->tenant('lea');
        [$this->operator] = $this->enrolledOperator();
        [$photo, $listingId, $sellerId] = $this->inside('lea', fn (): array => [
            $this->shopPhoto(),
            app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => 'ニット', 'unit_amount' => 9900, 'description' => '前の説明'])->id,
            (string) Seller::query()->value('id'),
        ]);

        // 画面が上限を知れる
        $this->send('GET', '/api/tenants/lea/listing-options')->assertOk()
            ->assertJsonPath('description_limits.blocks', ProductDescription::MAX_BLOCKS)
            ->assertJsonPath('description_limits.text', ProductDescription::MAX_TEXT)
            ->assertJsonPath('description_limits.table_rows', ProductDescription::MAX_TABLE_ROWS)
            ->assertJsonPath('description_limits.specs', ProductDescription::MAX_SPECS);

        // 前の説明は段落として読める
        $this->send('GET', "/api/tenants/lea/listings/{$listingId}")->assertOk()
            ->assertJsonPath('listing.description_blocks', [['type' => 'paragraph', 'text' => '前の説明']])
            ->assertJsonPath('listing.specs', [])
            ->assertJsonPath('listing.summary', null);

        $blocks = [
            ['type' => 'heading', 'level' => 2, 'text' => '素材'],
            ['type' => 'paragraph', 'text' => '<b>綿</b> 100%'],
            ['type' => 'list', 'style' => 'bullet', 'items' => ['軽い', '涼しい']],
            ['type' => 'image', 'media_id' => $photo, 'caption' => '着た様子'],
            ['type' => 'table', 'header' => true, 'caption' => null, 'rows' => [['サイズ', '着丈'], ['M', '70']]],
            ['type' => 'note', 'tone' => 'warning', 'text' => '手洗いしてください'],
        ];
        $this->send('PATCH', "/api/tenants/lea/listings/{$listingId}", [
            'version' => 1,
            'description_blocks' => $blocks,
            'specs' => [['label' => '原産国', 'value' => '日本']],
            'summary' => 'ふんわり軽いニット',
        ])->assertOk()
            ->assertJsonPath('listing.version', 2)
            ->assertJsonPath('listing.description_blocks', $blocks)
            ->assertJsonPath('listing.specs', [['label' => '原産国', 'value' => '日本']])
            ->assertJsonPath('listing.summary', 'ふんわり軽いニット');

        $this->inside('lea', function () use ($listingId): void {
            $event = DomainEvent::query()->where('type', 'listing.updated')->sole();
            $this->assertSame('operator:'.$this->operator->id, $event->actor);
            $this->assertEqualsCanonicalizing(['description', 'description_blocks', 'specs', 'summary'], array_keys($event->payload['changes']));
            $this->assertStringContainsString('素材', (string) Listing::query()->findOrFail($listingId)->description);
        });

        // 登録のときにも入れられる
        $this->send('POST', '/api/tenants/lea/listings', [
            'seller_id' => $sellerId, 'kind' => 'product', 'title' => '鉢', 'unit_amount' => 100,
            'description_blocks' => [['type' => 'paragraph', 'text' => '素焼き']], 'summary' => '小さな鉢',
        ])->assertCreated()->assertJsonPath('listing.description_blocks.0.text', '素焼き')->assertJsonPath('listing.summary', '小さな鉢');
    }

    public function test_wrong_blocks_are_refused_with_the_place_to_fix(): void
    {
        $this->tenant('leb');
        $this->tenant('lec');
        [$this->operator] = $this->enrolledOperator();
        $other = $this->inside('lec', fn (): string => $this->shopPhoto());
        $listingId = $this->inside('leb', fn (): string => app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => 'ニット', 'unit_amount' => 9900])->id);
        $patch = fn (array $body) => $this->send('PATCH', "/api/tenants/leb/listings/{$listingId}", ['version' => 1] + $body);

        $patch(['description_blocks' => [['type' => 'html', 'html' => '<script>alert(1)</script>']]])->assertStatus(422)->assertJsonValidationErrors('description_blocks.0');
        $patch(['description_blocks' => [['type' => 'paragraph', 'text' => '']]])->assertStatus(422)->assertJsonValidationErrors('description_blocks.0.text');
        $patch(['description_blocks' => [['type' => 'paragraph', 'text' => 'ok'], ['type' => 'heading', 'level' => 5, 'text' => 'x']]])->assertStatus(422)->assertJsonValidationErrors('description_blocks.1.level');
        // ほかの店の写真は、ID を知っていても選べない
        $patch(['description_blocks' => [['type' => 'image', 'media_id' => $other]]])->assertStatus(422)->assertJsonValidationErrors('description_blocks.0.media_id');
        // 入口で: 形・個数・NUL 文字・壊れた文字
        $patch(['description_blocks' => '<p>段落</p>'])->assertStatus(422)->assertJsonValidationErrors('description_blocks');
        $patch(['description_blocks' => array_fill(0, ProductDescription::MAX_BLOCKS + 1, ['type' => 'paragraph', 'text' => 'x'])])->assertStatus(422)->assertJsonValidationErrors('description_blocks');
        $patch(['description_blocks' => [['type' => 'paragraph', 'text' => "a\0b"]]])->assertStatus(422)->assertJsonValidationErrors('description_blocks.0.text');
        $patch(['specs' => [['label' => '素材', 'value' => "a\x07b"]]])->assertStatus(422)->assertJsonValidationErrors('specs.0.value');
        $patch(['specs' => 'x'])->assertStatus(422)->assertJsonValidationErrors('specs');
        $patch(['summary' => "一\n行"])->assertStatus(422)->assertJsonValidationErrors('summary');
        $patch(['summary' => str_repeat('あ', ProductDescription::MAX_SUMMARY + 1)])->assertStatus(422)->assertJsonValidationErrors('summary');
        $raw = $this->withCookie((string) config('admin.session_cookie'), (string) $this->sessionId)
            ->call('PATCH', "http://admin.localhost/api/tenants/leb/listings/{$listingId}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                '{"version":1,"description_blocks":[{"type":"paragraph","text":"'."\xC3\x28".'"}]}');
        $this->assertSame(422, $raw->status());

        // どれも保存されず、記録も残らない
        $this->inside('leb', function () use ($listingId): void {
            $this->assertSame(1, Listing::query()->findOrFail($listingId)->version);
            $this->assertSame(0, DomainEvent::query()->where('type', 'listing.updated')->count());
        });
    }

    public function test_staff_who_can_only_read_cannot_change_the_description(): void
    {
        $this->tenant('led');
        $listingId = $this->inside('led', fn (): string => app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => 'ニット', 'unit_amount' => 9900])->id);
        [$this->operator] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($this->operator, 'led', 'read', 'test');

        $this->send('GET', "/api/tenants/led/listings/{$listingId}")->assertOk()->assertJsonPath('listing.description_blocks', []);
        $this->send('PATCH', "/api/tenants/led/listings/{$listingId}", ['version' => 1, 'description_blocks' => [['type' => 'paragraph', 'text' => '書き換え']]])->assertForbidden();
        $this->inside('led', fn () => $this->assertSame([], Listing::query()->findOrFail($listingId)->description_blocks));
    }

    public function test_the_photo_library_shows_which_product_uses_a_photo_and_refuses_to_remove_it(): void
    {
        $this->tenant('lee');
        [$this->operator] = $this->enrolledOperator();
        [$photo, $listingId] = $this->inside('lee', function (): array {
            $photo = $this->shopPhoto();

            return [$photo, app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', [
                'title' => '<b>ニット</b>', 'unit_amount' => 9900, 'description_blocks' => [['type' => 'image', 'media_id' => $photo]],
            ])->id];
        });

        $this->send('GET', '/api/tenants/lee/shop-media')->assertOk()->assertJsonPath('items.0.used_by', ["listing:{$listingId}"]);
        $this->send('DELETE', "/api/tenants/lee/shop-media/{$photo}")->assertStatus(422)
            ->assertJsonPath('errors.media.0', 'この写真は商品「<b>ニット</b>」の説明で使われているため削除できません。先にそちらで別の写真に変更するか、写真を外してください。');
    }

    /**
     * 見直しの指摘 1: 文字の向きを変える印の入った前の説明を移行しても、ショップに出て、管理画面で読めて、保存し直しても消えない。
     * 印の残った保存済みのブロック（DB を直に書いたなど）は、読むときに印だけを外し、管理画面はそれを知らせる
     */
    public function test_a_description_with_direction_marks_survives_the_migration_the_shop_and_saving_again(): void
    {
        $this->tenant('lef');
        $this->withoutVite();

        [$migrated, $stored] = $this->inside('lef', function (): array {
            $sellerId = (string) Seller::query()->value('id');
            $migration = require base_path('database/migrations/tenant/2026_10_29_000700_add_description_blocks_to_listings.php');
            $migration->down();
            $migrated = strtolower((string) Str::ulid());
            DB::table('listings')->insert(['id' => $migrated, 'seller_id' => $sellerId, 'kind' => 'product', 'offer_side' => 'sell', 'pricing' => 'fixed', 'status' => 'active',
                'title' => '前の商品', 'description' => "\u{202E}綿\u{2066}100%\u{2069}の\nニット", 'unit_amount' => 100, 'currency' => 'JPY', 'created_at' => now(), 'updated_at' => now()]);
            $migration->up();

            // 印の残った保存済みのブロック（読むときに印だけを外す）と、形の崩れたブロック（捨てる）
            $stored = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => '保存済み', 'unit_amount' => 100, 'status' => ListingStatus::Active,
                'description_blocks' => [['type' => 'heading', 'text' => '素材'], ['type' => 'paragraph', 'text' => '綿']]])->id;
            DB::table('listings')->where('id', $stored)->update(['description_blocks' => json_encode([
                ['type' => 'heading', 'level' => 2, 'text' => '素材'],
                ['type' => 'paragraph', 'text' => "綿\u{202E}100%"],
                ['type' => 'marquee', 'text' => '知らない種類'],
            ])]);

            return [$migrated, $stored];
        });

        // ショップ: どちらの説明も、印を外して出る（説明のメタ情報にも印を残さない）
        foreach ([$migrated => "綿100%の\nニット", $stored => '綿100%'] as $id => $text) {
            $html = (string) $this->get("http://lef.localhost/products/{$id}")->assertOk()->getContent();
            tenancy()->end();
            $this->assertStringContainsString($text, $html);
            $this->assertDoesNotMatchRegularExpression('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $html);
        }

        // 管理画面（ショップのセッションの Cookie と混ざらないよう、ショップを見た後にログインする）
        [$this->operator] = $this->enrolledOperator();

        // 移行した説明: 読めて、知らせは出ない。画面で読んだブロックをそのまま保存し直しても残る
        $this->send('GET', "/api/tenants/lef/listings/{$migrated}")->assertOk()
            ->assertJsonPath('listing.description_blocks', [['type' => 'paragraph', 'text' => "綿100%の\nニット"]])
            ->assertJsonPath('listing.description_repaired', false);
        $this->send('PATCH', "/api/tenants/lef/listings/{$migrated}", ['version' => 1, 'title' => '前の商品（改）', 'description_blocks' => [['type' => 'paragraph', 'text' => "綿100%の\nニット"]]])
            ->assertOk()->assertJsonPath('listing.description_blocks.0.text', "綿100%の\nニット");

        // 印の残った保存済みのブロック: 印だけを外して見せ、崩れたブロックは捨てたことを知らせる
        $this->send('GET', "/api/tenants/lef/listings/{$stored}")->assertOk()
            ->assertJsonPath('listing.description_blocks.1.text', '綿100%')
            ->assertJsonCount(2, 'listing.description_blocks')
            ->assertJsonPath('listing.description_repaired', true);
        // 説明に触れない保存（題だけ）では、保存済みのブロックを書き換えない
        $this->send('PATCH', "/api/tenants/lef/listings/{$stored}", ['version' => 1, 'title' => '保存済み（改）'])->assertOk();

        $this->inside('lef', function () use ($migrated, $stored): void {
            $this->assertSame("綿100%の\nニット", Listing::query()->findOrFail($migrated)->description_blocks[0]['text']);
            $this->assertCount(3, json_decode((string) DB::table('listings')->where('id', $stored)->value('description_blocks'), true));
        });
    }

    /**
     * 見直しの指摘 3: 商品の画面の写真の選び方は、使っている所を数えずに一覧を読む（全部の商品の説明を回さない）
     */
    public function test_the_photo_list_can_be_read_without_counting_where_each_photo_is_used(): void
    {
        $this->tenant('leg');
        [$this->operator] = $this->enrolledOperator();
        $photo = $this->inside('leg', function (): string {
            $photo = $this->shopPhoto();
            app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => '鉢', 'unit_amount' => 100, 'description_blocks' => [['type' => 'image', 'media_id' => $photo]]]);

            return $photo;
        });

        $this->send('GET', '/api/tenants/leg/shop-media?usage=none')->assertOk()->assertJsonPath('items.0.id', $photo)->assertJsonMissingPath('items.0.used_by');
        // 知らない値は今までどおり（数える）
        $this->send('GET', '/api/tenants/leg/shop-media?usage=other')->assertOk()->assertJsonCount(1, 'items.0.used_by');
        $this->send('GET', '/api/tenants/leg/shop-media')->assertOk()->assertJsonCount(1, 'items.0.used_by');
    }

    private function shopPhoto(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'le');
        file_put_contents($path, $this->jpeg(40, 30));
        $this->files[] = $path;

        return app(ShopMediaService::class)->add($path, null, 'cli:test')->id;
    }
}
