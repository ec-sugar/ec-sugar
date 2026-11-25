<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ショップの注文の払い方（の見直し）。checkout はお客さんが支払いの画面で払う注文、stored は預けたカードで請求する注文（定期便の回）。
 * 払われていない注文の片付けは checkout だけを取り消す（stored は請求の仕組みが待つ・取り消す。請求の前にカードが無くて支払いの記録が無い時も）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_orders', function (Blueprint $table): void {
            $table->string('pays_with', 16)->default('checkout');
        });
        DB::statement("ALTER TABLE checkout_orders ADD CONSTRAINT checkout_orders_pays_with CHECK (pays_with IN ('checkout', 'stored'))");
    }

    public function down(): void
    {
        Schema::table('checkout_orders', function (Blueprint $table): void {
            $table->dropColumn('pays_with');
        });
    }
};
