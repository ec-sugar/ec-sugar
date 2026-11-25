<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 写真ごとの大きさ（作り直して置いた大と中の WebP の合計、バイト）。写真の置き場の上限（MediaQuota）で店の合計を数える。
 * 前からある写真は 0（公開の前なので本番の写真は無い。開発の店の写真は数えない）
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['listing_images', 'shop_media'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->bigInteger('bytes')->default(0);
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_bytes_non_negative CHECK (bytes >= 0)");
        }
    }

    public function down(): void
    {
        foreach (['listing_images', 'shop_media'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_bytes_non_negative");
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('bytes');
            });
        }
    }
};
