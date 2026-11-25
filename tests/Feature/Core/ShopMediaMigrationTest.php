<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Media\Models\ShopMedia;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 今のヒーローの写真（shop_settings.hero_image）を、ショップの写真の置き場へ移す移行（ADR 0028 区切り 1）。
 * 移した後も、写真は同じ ID でショップから配る（店の設定のヒーローは区切り 2c で控えへ移し、SEO の表が写真を指すので、この移行を確かめる前にその移行を新しい方から戻す）。
 */
final class ShopMediaMigrationTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    private const string MIGRATION = 'database/migrations/tenant/2026_10_28_003000_create_shop_media_table.php';

    /**
     * この後の移行のうち、この移行の形に触れるもの。この移行を確かめる前に、新しい方から順に戻す（印の表があれば戻す）
     *   - 検索と共有の見え方: shop_seo が共有の写真として shop_media を外部キーで指す
     *   - App「お知らせ・ブログ」: blog_posts が一覧の写真として shop_media を外部キーで指す
     *   - 店の設定の見せ方の項目を控えへ移す（区切り 2c）: shop_settings のヒーローの列を控えへ移す
     *
     * @var array<string, string> 印の表 => 移行
     */
    private const array LATER = [
        'shop_seo' => 'database/migrations/tenant/2026_11_05_014000_add_seo.php',
        'blog_posts' => 'plugins/plugin-blog/database/migrations/2026_11_05_000300_create_blog_tables.php',
        'legacy_storefront_settings' => 'database/migrations/tenant/2026_10_29_000200_move_legacy_storefront_settings.php',
    ];

    private const string HERO_ID = '01jbxq3k9w2m8n4p6r7s9t0v1x';

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->disk = FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_current_hero_photo_moves_to_the_shop_photos_and_the_shop_looks_the_same(): void
    {
        $this->tenant('mga');
        $checksum = hash('sha256', 'large');

        $this->inside('mga', function () use ($checksum): void {
            $this->legacyHero(['id' => self::HERO_ID, 'width' => 2000, 'height' => 1000, 'checksum' => $checksum]);
            $this->disk->put('tenants/mga/shop/hero-'.self::HERO_ID.'-large.webp', 'large-bytes');
            $this->disk->put('tenants/mga/shop/hero-'.self::HERO_ID.'-medium.webp', 'medium-bytes');

            $this->migrateUp();
        });

        $this->inside('mga', function () use ($checksum): void {
            $media = ShopMedia::query()->sole();
            $this->assertSame([self::HERO_ID, 2000, 1000, $checksum, null], [$media->id, $media->width, $media->height, $media->checksum, $media->alt]);
            $this->assertSame(self::HERO_ID, DB::table('shop_settings')->value('hero_media_id'));
            $this->assertFalse(Schema::hasColumn('shop_settings', 'hero_image'));
        });

        // ファイルは新しい置き場へ写す。元のファイルは消さない（取り消し・down のときに戻れる）
        $this->assertSame('large-bytes', $this->disk->get('tenants/mga/shop/media/'.self::HERO_ID.'-large.webp'));
        $this->assertSame('medium-bytes', $this->disk->get('tenants/mga/shop/media/'.self::HERO_ID.'-medium.webp'));
        $this->assertTrue($this->disk->exists('tenants/mga/shop/hero-'.self::HERO_ID.'-large.webp'));

        // 道筋だけが /media/shop/{id} になる
        $served = $this->get('http://mga.localhost/media/shop/'.self::HERO_ID.'/large')->assertOk()->assertHeader('Content-Type', 'image/webp');
        tenancy()->end();
        $this->assertSame('large-bytes', $served->getContent());
        $this->assertSame('"'.$checksum.'-large"', $served->headers->get('ETag'));

        // 戻すと、元の列に元の写真が戻る
        $this->inside('mga', function () use ($checksum): void {
            $this->migrateDown();
            $this->assertFalse(Schema::hasTable('shop_media'));
            $this->assertEquals(['id' => self::HERO_ID, 'width' => 2000, 'height' => 1000, 'checksum' => $checksum], json_decode((string) DB::table('shop_settings')->value('hero_image'), true));
            $this->migrateUp();
            $this->assertSame(self::HERO_ID, DB::table('shop_settings')->value('hero_media_id'));
        });
    }

    public function test_shops_without_a_hero_or_with_missing_files_still_migrate(): void
    {
        $this->tenant('mgb');

        $this->inside('mgb', function (): void {
            // ヒーローの無い店と、ファイルが前から欠けていた店（配る経路は前も 404 だった）
            $this->legacyHero(null);
            $this->migrateUp();
            $this->assertSame(0, ShopMedia::query()->count());
            $this->assertNull(DB::table('shop_settings')->value('hero_media_id'));

            $this->migrateDown();
            DB::table('shop_settings')->delete();
            $this->legacyHero(['id' => self::HERO_ID, 'width' => 10, 'height' => 5, 'checksum' => str_repeat('b', 64)]);
            $this->migrateUp();
            $this->assertSame(self::HERO_ID, DB::table('shop_settings')->value('hero_media_id'));
        });
        $this->assertSame([], array_values(array_filter($this->disk->allFiles(), static fn (string $f): bool => $f !== '.owner')));
        $this->get('http://mgb.localhost/media/shop/'.self::HERO_ID.'/large')->assertNotFound();
        tenancy()->end();
    }

    public function test_a_broken_hero_stops_the_migration_and_nothing_changes(): void
    {
        $this->tenant('mgc');

        $this->inside('mgc', function (): void {
            $this->legacyHero(['id' => self::HERO_ID, 'width' => 'wide', 'height' => 1000, 'checksum' => str_repeat('c', 64)]);

            try {
                DB::transaction(function (): void {
                    $this->migrateUp();
                });
                $this->fail('形の崩れたヒーローを黙って移した');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('形が崩れている', $e->getMessage());
            }

            // DDL もまとめて取り消される（PostgreSQL）。元の列と値はそのまま
            $this->assertFalse(Schema::hasTable('shop_media'));
            $this->assertTrue(Schema::hasColumn('shop_settings', 'hero_image'));
            $this->assertSame('wide', json_decode((string) DB::table('shop_settings')->value('hero_image'), true)['width']);

            // 後片付け（tearDown で店を消すため、移行の後の形に戻す）。崩れた行のままでは同じ所で止まるので、先に除く
            DB::table('shop_settings')->delete();
            $this->migrateUp();
        });
    }

    /**
     * 移行の前の形に戻し、前の形の店の設定を 1 行作る
     *
     * @param  array<string, mixed>|null  $hero
     */
    private function legacyHero(?array $hero): void
    {
        foreach (self::LATER as $table => $path) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $later = require base_path($path);
            $this->assertInstanceOf(Migration::class, $later);
            $this->assertTrue(method_exists($later, 'down'));
            $later->down();
            $this->assertFalse(Schema::hasTable($table), $path.' の down が戻し切らない');
        }

        if (Schema::hasTable('shop_media')) {
            $this->migrateDown();
        }

        // 店を作るときにできる設定の行（から）は、この移行の前の店には無い。前の形の店にそろえて消す
        DB::table('shop_settings')->delete();
        DB::table('shop_settings')->insert([
            'id' => strtolower((string) Str::ulid()),
            'storefront' => json_encode(['announcement' => null, 'hero' => ['heading' => null, 'body' => null], 'promises' => []]),
            'version' => 0,
            'hero_image' => $hero === null ? null : json_encode($hero),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
