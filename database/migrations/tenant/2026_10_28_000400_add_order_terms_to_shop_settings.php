<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 注文の案内: 届く目安と返品の条件。注文の最終確認の画面に出す（特商法の最終確認の画面の項目）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->jsonb('order_terms')->nullable(); // {delivery, returns}
        });

        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_order_terms_object CHECK (order_terms IS NULL OR jsonb_typeof(order_terms) = 'object')");
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn('order_terms');
        });
    }
};
