<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 送料。全国一律の送料と、この金額以上で無料（null なら無料にしない）。金額は円の整数
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->bigInteger('shipping_fee')->default(0);
            $table->bigInteger('free_shipping_over')->nullable();
        });

        DB::statement('ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_shipping_fee_range CHECK (shipping_fee BETWEEN 0 AND 100000)');
        DB::statement('ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_free_shipping_range CHECK (free_shipping_over IS NULL OR free_shipping_over BETWEEN 1 AND 10000000)');
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn(['shipping_fee', 'free_shipping_over']);
        });
    }
};
