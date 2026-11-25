<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Shipping\Regions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 送料の表を作る移行（ADR 0064 の 5）: 今の全国一律の送料と無料の線を、全国同じ額の「通常便」1 つに写す。古い列は残す。戻すと表が消える
 */
final class ShippingMethodsMigrationTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string MIGRATION = 'database/migrations/tenant/2026_11_05_000300_create_shipping_methods.php';

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_flat_fee_becomes_one_nationwide_method(): void
    {
        $this->tenant('smm');

        $this->inside('smm', function (): void {
            $this->migrateDown();
            $this->assertFalse(Schema::hasTable('shipping_methods'));
            $this->assertFalse(Schema::hasColumn('listings', 'shipping_method_id'));

            // 前の形の店: 全国一律 550 円・11,000 円以上で無料
            DB::table('shop_settings')->update(['shipping_fee' => 550, 'free_shipping_over' => 11000]);
            $this->migrateUp();

            $methods = app(ShippingMethods::class)->all();
            $this->assertCount(1, $methods);
            $this->assertSame(['通常便', 1, 11000], [$methods[0]['name'], $methods[0]['position'], $methods[0]['free_over']]);
            $this->assertSame(Regions::flat(550), $methods[0]['rates']);
            // 古い列は残す（戻せるように。読まない）
            $this->assertSame(550, (int) DB::table('shop_settings')->value('shipping_fee'));
        });
    }

    public function test_a_shop_without_settings_gets_a_free_method(): void
    {
        $this->tenant('smn');

        $this->inside('smn', function (): void {
            $this->migrateDown();
            DB::table('shop_settings')->delete();
            $this->migrateUp();

            $methods = app(ShippingMethods::class)->all();
            $this->assertSame([null, [0]], [$methods[0]['free_over'], array_values(array_unique($methods[0]['rates']))]);
            $this->assertTrue(app(ShippingMethods::class)->unset());
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
