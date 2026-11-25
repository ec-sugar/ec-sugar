<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店の設定の見せ方の項目を、控えの表へ移して店の設定から外す移行（ADR 0028 区切り 2c 後半）。
 * 値は消さずに控えへ移り（テーマを作り直した後に区画へ写せる）、戻すと元の列に戻る。
 */
final class LegacyStorefrontMigrationTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string MIGRATION = 'database/migrations/tenant/2026_10_29_000200_move_legacy_storefront_settings.php';

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_presentation_settings_move_to_the_archive_and_come_back_on_rollback(): void
    {
        $this->tenant('lsa');

        $this->inside('lsa', function (): void {
            // 移行の前の形に戻し、前の形の店の設定を置く
            $this->migrateDown();
            // 店を作るときにできる設定の行（から）は、この移行の前の店には無い。前の形の店にそろえて消す
            DB::table('shop_settings')->delete();
            $hero = strtolower((string) Str::ulid());
            DB::table('shop_media')->insert(['id' => $hero, 'width' => 10, 'height' => 5, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            $storefront = ['announcement' => '送料無料', 'hero' => ['heading' => "秋の\n新作", 'body' => null], 'promises' => []];
            $sections = ['categories' => [], 'looks' => null, 'favorites' => [], 'seasonal' => null];
            $id = strtolower((string) Str::ulid());
            DB::table('shop_settings')->insert([
                'id' => $id, 'storefront' => json_encode($storefront), 'version' => 3, 'sections' => json_encode($sections),
                'hero_media_id' => $hero, 'shipping_fee' => 550, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->migrateUp();

            // 店の設定には店の事実だけが残る
            foreach (['storefront', 'version', 'sections', 'hero_media_id'] as $column) {
                $this->assertFalse(Schema::hasColumn('shop_settings', $column), $column);
            }
            $this->assertSame(550, (int) DB::table('shop_settings')->value('shipping_fee'));
            // 控えには同じ値（バージョンは要らないので持たない）
            $archived = DB::table('legacy_storefront_settings')->sole();
            $this->assertSame($id, $archived->id);
            $this->assertEquals($storefront, json_decode((string) $archived->storefront, true));
            $this->assertEquals($sections, json_decode((string) $archived->sections, true));
            $this->assertSame($hero, $archived->hero_media_id);
            // 控えの写真は外部キーで守らない（写真の置き場から消せる）
            DB::table('shop_media')->where('id', $hero)->delete();

            // 戻すと元の列に戻る。消えた写真は外す（外部キーを満たす）
            $this->migrateDown();
            $this->assertFalse(Schema::hasTable('legacy_storefront_settings'));
            $row = DB::table('shop_settings')->sole();
            $this->assertEquals($storefront, json_decode((string) $row->storefront, true));
            $this->assertEquals($sections, json_decode((string) $row->sections, true));
            $this->assertNull($row->hero_media_id);
            $this->migrateUp();
        });
    }

    public function test_a_shop_that_never_saved_its_settings_still_migrates(): void
    {
        $this->tenant('lsb');

        $this->inside('lsb', function (): void {
            $this->migrateDown();
            // 店を作るときにできる設定の行（から）は、この移行の前の店には無い。前の形の店にそろえて消す
            DB::table('shop_settings')->delete();
            $this->assertSame(0, DB::table('shop_settings')->count());
            $this->migrateUp();
            $this->assertSame(0, DB::table('legacy_storefront_settings')->count());
            $this->migrateDown();
            $this->migrateUp();
        });
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
