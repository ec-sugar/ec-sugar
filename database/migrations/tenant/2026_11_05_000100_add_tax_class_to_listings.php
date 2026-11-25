<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商品の消費税の区分（ADR 0063。standard = 標準 10%・reduced = 軽減 8%）。率は区分から核（App\Core\Tax\TaxClass）が決める。
 * 今ある商品は標準のまま（既定の値で埋まる。本番の商品の値段・税は変わらない）。作った注文の明細は注文の時の率を持っているので触らない
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('tax_class', 16)->default('standard');
        });
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_tax_class_known CHECK (tax_class IN ('standard', 'reduced'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE listings DROP CONSTRAINT IF EXISTS listings_tax_class_known');
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn('tax_class');
        });
    }
};
