<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\StaleListingException;
use App\Core\Media\Services\ShopMediaInUseException;
use App\Core\Media\Services\ShopMediaService;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 商品の説明のブロック・仕様の表・短い説明（ADR 0032）。保存はサービス経由で、写真は同じ店のショップの写真のものだけ、
 * 前の形の説明（listings.description）とは両方向に写し合う（前のバージョンへ戻しても動く）。
 */
final class ListingDescriptionTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    private const string MIGRATION = 'database/migrations/tenant/2026_10_29_000700_add_description_blocks_to_listings.php';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_blocks_specs_and_the_summary_are_saved_through_the_service_and_recorded_without_the_text(): void
    {
        $this->tenant('lda');

        $this->inside('lda', function (): void {
            $photo = $this->shopPhoto();
            $service = app(ListingService::class);
            $listing = $service->create($this->seller(), 'product', [
                'title' => 'ニット',
                'unit_amount' => 9900,
                'description_blocks' => [['type' => 'heading', 'text' => '素材'], ['type' => 'paragraph', 'text' => '綿 100%'], ['type' => 'image', 'media_id' => strtoupper($photo)]],
                'specs' => [['label' => '原産国', 'value' => '日本']],
                'summary' => ' ふんわり軽い ',
            ], 'operator:01jbx0000000000000000000aa');

            $fresh = Listing::query()->findOrFail($listing->id);
            $this->assertEquals([
                ['type' => 'heading', 'level' => 2, 'text' => '素材'],
                ['type' => 'paragraph', 'text' => '綿 100%'],
                ['type' => 'image', 'media_id' => $photo, 'caption' => null],
            ], $fresh->description_blocks);
            $this->assertSame([['label' => '原産国', 'value' => '日本']], $fresh->specs);
            $this->assertSame('ふんわり軽い', $fresh->summary);
            // 前の形の説明には、ブロックの文をつないだものが入る（前のバージョンへ戻したとき・検索エンジン向け）
            $this->assertSame("素材\n\n綿 100%", $fresh->description);
            $this->assertSame(1, $fresh->version);

            // 変えるとバージョンが進み、記録には「変わった」だけ（文は残さない）
            $updated = $service->update($fresh, 1, [
                'description_blocks' => [['type' => 'note', 'tone' => 'warning', 'text' => '手洗いしてください']],
                'summary' => '春の新作',
            ], 'operator:01jbx0000000000000000000aa');
            $this->assertSame(2, $updated->version);
            $event = DomainEvent::query()->where('type', 'listing.updated')->sole();
            $this->assertSame(['changed' => true], $event->payload['changes']['description_blocks']);
            $this->assertSame(['changed' => true], $event->payload['changes']['summary']);
            $this->assertSame(['changed' => true], $event->payload['changes']['description']);
            $this->assertStringNotContainsString('手洗い', (string) json_encode($event->payload, JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString('春の新作', (string) json_encode($event->payload, JSON_UNESCAPED_UNICODE));
            $this->assertSame('手洗いしてください', Listing::query()->findOrFail($listing->id)->description);

            // 同じ中身を項目の順を変えて送っても、何も変わらない（バージョンも記録も進まない）
            $same = $service->update($updated, 2, ['description_blocks' => [['text' => '手洗いしてください', 'tone' => 'warning', 'type' => 'note']], 'specs' => [['value' => '日本', 'label' => '原産国']]]);
            $this->assertSame(2, $same->version);
            $this->assertSame(1, DomainEvent::query()->where('type', 'listing.updated')->count());

            // 前の形の説明を送る（前のバージョンの画面）と、1 つの段落になる
            $service->update($same, 2, ['description' => "一行目\n二行目"]);
            $this->assertEquals([['type' => 'paragraph', 'text' => "一行目\n二行目"]], Listing::query()->findOrFail($listing->id)->description_blocks);
        });
    }

    public function test_photos_must_be_in_the_photo_library_of_the_same_shop(): void
    {
        $this->tenant('ldb');
        $this->tenant('ldc');
        $other = $this->inside('ldc', fn (): string => $this->shopPhoto());

        $this->inside('ldb', function () use ($other): void {
            $mine = $this->shopPhoto();
            $service = app(ListingService::class);
            $listing = $service->create($this->seller(), 'product', ['title' => '鉢', 'unit_amount' => 100]);

            foreach (['ほかの店の写真' => $other, '無い写真' => strtolower((string) Str::ulid())] as $name => $id) {
                try {
                    $service->update($listing, 1, ['description_blocks' => [['type' => 'image', 'media_id' => $mine], ['type' => 'image', 'media_id' => $id]]]);
                    $this->fail("{$name}を受け付けてしまった");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('description_blocks.1.media_id', $e->errors(), $name);
                }

                try {
                    $service->create($this->seller(), 'product', ['title' => '鉢 2', 'unit_amount' => 100, 'description_blocks' => [['type' => 'image', 'media_id' => $id]]]);
                    $this->fail("{$name}で作れてしまった");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('description_blocks.0.media_id', $e->errors(), $name);
                }
            }

            // 何も変わらず、記録も残らない
            $this->assertSame(1, Listing::query()->count());
            $this->assertSame(1, Listing::query()->findOrFail($listing->id)->version);
            $this->assertSame([], Listing::query()->findOrFail($listing->id)->description_blocks);
            $this->assertSame(0, DomainEvent::query()->where('type', 'listing.updated')->count());

            // 同じ店の写真なら通る
            $service->update($listing, 1, ['description_blocks' => [['type' => 'image', 'media_id' => $mine]]]);
            $this->assertSame(2, Listing::query()->findOrFail($listing->id)->version);
        });
    }

    public function test_a_photo_used_in_a_description_cannot_be_removed_from_the_library(): void
    {
        $this->tenant('ldd');

        $this->inside('ldd', function (): void {
            $photo = $this->shopPhoto();
            $media = app(ShopMediaService::class);
            $service = app(ListingService::class);
            $listing = $service->create($this->seller(), 'product', ['title' => '鉢', 'unit_amount' => 100, 'description_blocks' => [['type' => 'image', 'media_id' => $photo]]]);
            $this->assertSame(["listing:{$listing->id}"], $media->usages($photo));

            try {
                $media->remove($photo);
                $this->fail('説明に使っている写真を消せてしまった');
            } catch (ShopMediaInUseException $e) {
                $this->assertSame(["listing:{$listing->id}"], $e->usages);
            }

            // 説明から外せば消せる
            $service->update($listing, 1, ['description_blocks' => []]);
            $this->assertSame([], $media->usages($photo));
            $media->remove($photo);

            // ほかの写真を使う商品は、この写真の使用に数えない（1 枚分だけを索引で調べる。見直しの指摘 3）
            $other = $this->shopPhoto();
            $third = $this->shopPhoto();
            $service->create($this->seller(), 'product', ['title' => '別の写真', 'unit_amount' => 100, 'description_blocks' => [['type' => 'paragraph', 'text' => 'x'], ['type' => 'image', 'media_id' => $other]]]);
            // 1 枚分は、その写真を指す説明だけを索引で開く（全部の写真のブロックを回さない）
            $bindings = [];
            Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$bindings): void {
                if (str_contains($query->sql, 'description_blocks @>')) {
                    $bindings[] = $query->bindings;
                }
            });
            $this->assertSame([], $media->usages($third));
            $this->assertSame([[(string) json_encode([['type' => 'image', 'media_id' => $third]])]], $bindings);
            $this->assertCount(1, $media->usages($other));
            $this->assertSame(1, DB::table('pg_indexes')->where('tablename', 'listings')->where('indexname', 'listings_description_blocks_images')->count());

            // 消した（ゴミ箱の）商品の説明は数えない（ショップに出ないので）
            $second = $this->shopPhoto();
            $gone = $service->create($this->seller(), 'product', ['title' => '消す商品', 'unit_amount' => 100, 'description_blocks' => [['type' => 'image', 'media_id' => $second]]]);
            $gone->delete();
            $this->assertSame([], $media->usages($second));
        });
    }

    public function test_choosing_a_photo_waits_for_a_removal_that_holds_the_photo(): void
    {
        $this->tenant('lde');

        $this->inside('lde', function (): void {
            $photo = $this->shopPhoto();
            $listing = app(ListingService::class)->create($this->seller(), 'product', ['title' => '鉢', 'unit_amount' => 100]);

            // 別の接続で、写真の行を押さえたまま確定しない（消す側の途中）
            config(['database.connections.tenant_other' => config('database.connections.tenant')]);
            $other = DB::connection('tenant_other');
            $other->beginTransaction();

            try {
                $other->table('shop_media')->where('id', $photo)->lockForUpdate()->first();
                DB::statement("SET lock_timeout = '500ms'");

                try {
                    app(ListingService::class)->update($listing, 1, ['description_blocks' => [['type' => 'image', 'media_id' => $photo]]]);
                    $this->fail('消されている途中の写真を選べてしまった');
                } catch (QueryException $e) {
                    $this->assertSame('55P03', $e->getCode()); // lock_not_available: 消す側の確定を待つ
                }
            } finally {
                $other->rollBack();
                DB::statement('SET lock_timeout = 0');
                DB::purge('tenant_other');
            }

            $this->assertSame(1, Listing::query()->findOrFail($listing->id)->version);
        });
    }

    public function test_two_people_saving_the_same_version_keeps_only_the_first(): void
    {
        $this->tenant('ldf');

        $this->inside('ldf', function (): void {
            $service = app(ListingService::class);
            $listing = $service->create($this->seller(), 'product', ['title' => '鉢', 'unit_amount' => 100]);
            $service->update($listing, 1, ['description_blocks' => [['type' => 'paragraph', 'text' => '先の人']]]);

            $this->expectException(StaleListingException::class);

            try {
                $service->update($listing, 1, ['description_blocks' => [['type' => 'paragraph', 'text' => '後の人']]]);
            } finally {
                $this->assertEquals([['type' => 'paragraph', 'text' => '先の人']], Listing::query()->findOrFail($listing->id)->description_blocks);
            }
        });
    }

    public function test_the_old_description_column_still_works_for_an_older_version_of_the_app(): void
    {
        $this->tenant('ldg');

        $this->inside('ldg', function (): void {
            $sellerId = $this->seller()->id;
            // 前のバージョンのアプリは description だけを書く。DB が段落に写す
            $id = strtolower((string) Str::ulid());
            DB::table('listings')->insert(['id' => $id, 'seller_id' => $sellerId, 'kind' => 'product', 'offer_side' => 'sell', 'pricing' => 'fixed', 'status' => 'active',
                'title' => '前の形', 'description' => " 前の説明\r\n2 行目 ", 'unit_amount' => 100, 'currency' => 'JPY', 'created_at' => now(), 'updated_at' => now()]);
            $this->assertSame([['text' => "前の説明\n2 行目", 'type' => 'paragraph']], $this->blocksOf($id));

            DB::table('listings')->where('id', $id)->update(['description' => '書き換えた']);
            $this->assertSame([['text' => '書き換えた', 'type' => 'paragraph']], $this->blocksOf($id));
            DB::table('listings')->where('id', $id)->update(['description' => '  ']);
            $this->assertSame([], $this->blocksOf($id));

            // 今のアプリはブロックと文を一緒に書く（DB は写し直さない）
            $listing = Listing::query()->findOrFail($id);
            app(ListingService::class)->update($listing, 1, ['description_blocks' => [['type' => 'heading', 'text' => '見出し'], ['type' => 'paragraph', 'text' => '本文']]]);
            $this->assertSame([['text' => '見出し', 'type' => 'heading', 'level' => 2], ['text' => '本文', 'type' => 'paragraph']], $this->blocksOf($id));
            // 説明に関係ない項目の変更も、ブロックをそのままにする
            DB::table('listings')->where('id', $id)->update(['title' => '題だけ']);
            $this->assertCount(2, $this->blocksOf($id));
        });
    }

    /**
     * 写し（description）が保存済みの値とずれていても（写し方が後のバージョンで変わったなど）、同じブロックを保存し直して
     * description だけを書かない（書くと DB が「前のアプリが書いた」と見なし、ブロックを 1 つの段落にまとめてしまう）
     */
    public function test_saving_the_same_blocks_never_rewrites_only_the_copied_text(): void
    {
        $this->tenant('ldj');

        $this->inside('ldj', function (): void {
            $service = app(ListingService::class);
            $blocks = [['type' => 'heading', 'level' => 2, 'text' => '見出し'], ['type' => 'paragraph', 'text' => '本文']];
            $listing = $service->create($this->seller(), 'product', ['title' => '鉢', 'unit_amount' => 100, 'description_blocks' => $blocks]);

            // 写しだけを古い形にする（トリガーを止めて直に書く）
            DB::statement('ALTER TABLE listings DISABLE TRIGGER listings_description_sync');
            DB::table('listings')->where('id', $listing->id)->update(['description' => '古い写し']);
            DB::statement('ALTER TABLE listings ENABLE TRIGGER listings_description_sync');

            $service->update(Listing::query()->findOrFail($listing->id), 1, ['title' => '鉢（改）', 'description_blocks' => $blocks]);

            $this->assertCount(2, $this->blocksOf($listing->id));
            $this->assertSame('古い写し', DB::table('listings')->where('id', $listing->id)->value('description'));
        });
    }

    /**
     * デプロイの前から開いたままの古い管理画面は description だけを送る。変わっていなければ、ブロックを 1 段落に作り直さない（見直しの指摘 2）
     */
    public function test_an_unchanged_description_from_an_old_screen_keeps_the_blocks(): void
    {
        $this->tenant('ldk');

        $this->inside('ldk', function (): void {
            $service = app(ListingService::class);
            $listing = $service->create($this->seller(), 'product', ['title' => '鉢', 'unit_amount' => 100,
                'description_blocks' => [['type' => 'heading', 'text' => '見出し'], ['type' => 'paragraph', 'text' => '本文']]]);
            $text = (string) Listing::query()->findOrFail($listing->id)->description;

            // 題だけを変えた古い画面の保存（description は読んだまま）
            $updated = $service->update(Listing::query()->findOrFail($listing->id), 1, ['title' => '鉢（改）', 'description' => $text]);
            $this->assertSame(2, $updated->version);
            $this->assertCount(2, $this->blocksOf($listing->id));
            $event = DomainEvent::query()->where('type', 'listing.updated')->sole();
            $this->assertSame(['title'], array_keys($event->payload['changes']));

            // 何も変えずに保存し直すと、バージョンも記録も進まない
            $same = $service->update($updated, 2, ['description' => $text]);
            $this->assertSame(2, $same->version);

            // 説明を書き換えたときだけ、1 つの段落になる（前の形の画面でできることは、それだけ）
            $service->update($same, 2, ['description' => '書き換えた']);
            $this->assertSame([['text' => '書き換えた', 'type' => 'paragraph']], $this->blocksOf($listing->id));
        });
    }

    /**
     * 移行の後も description の列には元の文（向きを変える印つき）が残る。古い画面がそれを読んだまま送り返しても、
     * 厳しい確かめで断らず、題だけの変更を通してブロックを変えない（短い見直しの (A)）
     */
    public function test_an_old_screen_sending_back_the_original_text_with_direction_marks_is_not_refused(): void
    {
        $this->tenant('ldl');

        $this->inside('ldl', function (): void {
            $sellerId = $this->seller()->id;
            $this->migrateDown();
            $id = strtolower((string) Str::ulid());
            $original = "\u{202E}綿\u{2066}100%\u{2069}のニット";
            DB::table('listings')->insert(['id' => $id, 'seller_id' => $sellerId, 'kind' => 'product', 'offer_side' => 'sell', 'pricing' => 'fixed', 'status' => 'active',
                'title' => '前の商品', 'description' => $original, 'unit_amount' => 100, 'currency' => 'JPY', 'created_at' => now(), 'updated_at' => now()]);
            $this->migrateUp();
            $this->assertSame($original, DB::table('listings')->where('id', $id)->value('description'));
            $blocks = $this->blocksOf($id);

            $updated = app(ListingService::class)->update(Listing::query()->findOrFail($id), 1, ['title' => '前の商品（改）', 'description' => $original]);

            $this->assertSame(2, $updated->version);
            $this->assertSame('前の商品（改）', $updated->title);
            $this->assertSame($blocks, $this->blocksOf($id));
            $this->assertSame($original, DB::table('listings')->where('id', $id)->value('description'));

            // 違う文で印を付けて送れば、今までどおり厳しく確かめて断る
            try {
                app(ListingService::class)->update($updated, 2, ['description' => "書き換え\u{202E}た"]);
                $this->fail('向きを変える印の入った新しい文を受け付けてしまった');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('description', $e->errors());
            }
        });
    }

    public function test_the_database_refuses_shapes_the_app_would_refuse(): void
    {
        $this->tenant('ldh');

        $this->inside('ldh', function (): void {
            $listing = app(ListingService::class)->create($this->seller(), 'product', ['title' => '鉢', 'unit_amount' => 100]);
            $write = fn (array $values): Closure => static fn () => DB::table('listings')->where('id', $listing->id)->update($values);

            foreach ([
                'ブロックが並びでない' => ['description_blocks' => '{"type":"paragraph"}'],
                'ブロックが多すぎる' => ['description_blocks' => json_encode(array_fill(0, 101, ['type' => 'paragraph', 'text' => 'x']))],
                '仕様の表が並びでない' => ['specs' => '{}'],
                '仕様の表が多すぎる' => ['specs' => json_encode(array_fill(0, 31, ['label' => 'a', 'value' => 'b']))],
                '短い説明に改行' => ['summary' => "一\n行"],
                '短い説明が空' => ['summary' => ''],
                '短い説明が長すぎる' => ['summary' => str_repeat('あ', 201)],
                // 個数は上限の中でも、大きさで 512KB を越える（見直しの指摘 4）
                'ブロックが大きすぎる' => ['description_blocks' => json_encode(array_fill(0, 100, ['type' => 'paragraph', 'text' => str_repeat('x', 6000)]))],
                '仕様の表が大きすぎる' => ['specs' => json_encode(array_fill(0, 30, ['label' => 'a', 'value' => str_repeat('x', 18000)]))],
            ] as $name => $values) {
                try {
                    DB::transaction($write($values));
                    $this->fail("{$name}を DB が受け付けてしまった");
                } catch (QueryException $e) {
                    // check_violation（長さは列の型が先に断る: string_data_right_truncation）
                    $this->assertContains($e->getCode(), ['23514', '22001'], $name);
                }
            }
        });
    }

    public function test_the_migration_copies_the_old_description_into_a_paragraph_and_rolls_back(): void
    {
        $this->tenant('ldi');

        $this->inside('ldi', function (): void {
            $sellerId = $this->seller()->id;
            $this->migrateDown();
            $this->assertFalse(Schema::hasColumn('listings', 'description_blocks'));

            // Windows や Office から貼った文には、文字の向きを変える印が付きうる（見直しの指摘 1）
            $rows = ['with' => " 説明\r\nの文\u{0001} ", 'empty' => '   ', 'null' => null, 'bidi' => "\u{202E}説明\u{2066}の文\u{2069}", 'only_marks' => "\u{202A}\u{202C}"];
            $ids = [];

            foreach ($rows as $key => $description) {
                $ids[$key] = strtolower((string) Str::ulid());
                DB::table('listings')->insert(['id' => $ids[$key], 'seller_id' => $sellerId, 'kind' => 'product', 'offer_side' => 'sell', 'pricing' => 'fixed',
                    'status' => 'active', 'title' => $key, 'description' => $description, 'unit_amount' => 100, 'currency' => 'JPY', 'created_at' => now(), 'updated_at' => now()]);
            }

            $this->migrateUp();
            // 前後の空白を除き、改行をそろえ、制御文字を外して 1 つの段落に。空の説明は空の並び
            $this->assertSame([['text' => "説明\nの文", 'type' => 'paragraph']], $this->blocksOf($ids['with']));
            $this->assertSame([], $this->blocksOf($ids['empty']));
            $this->assertSame([], $this->blocksOf($ids['null']));
            // 向きを変える印も外す（保存のときに断る文字を、写した段落に残さない）。印だけの説明は空
            $this->assertSame([['text' => '説明の文', 'type' => 'paragraph']], $this->blocksOf($ids['bidi']));
            $this->assertSame([], $this->blocksOf($ids['only_marks']));
            $this->assertSame([], json_decode((string) DB::table('listings')->where('id', $ids['with'])->value('specs'), true));
            // 前の列は消さない（二段の移行）
            $this->assertSame(" 説明\r\nの文\u{0001} ", DB::table('listings')->where('id', $ids['with'])->value('description'));

            // 戻すと新しい列が消え、前の列は残る。流し直しても同じ
            $this->migrateDown();
            $this->assertFalse(Schema::hasColumn('listings', 'description_blocks'));
            $this->assertFalse(Schema::hasColumn('listings', 'specs'));
            $this->assertFalse(Schema::hasColumn('listings', 'summary'));
            $this->assertSame(" 説明\r\nの文\u{0001} ", DB::table('listings')->where('id', $ids['with'])->value('description'));
            $this->migrateUp();
            $this->assertSame([['text' => "説明\nの文", 'type' => 'paragraph']], $this->blocksOf($ids['with']));
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blocksOf(string $id): array
    {
        $blocks = json_decode((string) DB::table('listings')->where('id', $id)->value('description_blocks'), true);
        $this->assertIsArray($blocks);

        return $blocks;
    }

    private function seller(): Seller
    {
        return Seller::query()->firstOrFail();
    }

    private function shopPhoto(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ld');
        file_put_contents($path, $this->jpeg(40, 30));
        $this->files[] = $path;

        return app(ShopMediaService::class)->add($path, '写真', 'cli:test')->id;
    }

    private function migrateUp(): void
    {
        $migration = $this->migration();
        $this->assertTrue(method_exists($migration, 'up'));
        $migration->up();
    }

    private function migrateDown(): void
    {
        $migration = $this->migration();
        $this->assertTrue(method_exists($migration, 'down'));
        $migration->down();
    }

    private function migration(): Migration
    {
        $migration = require base_path(self::MIGRATION);
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
