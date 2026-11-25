<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 店の写真の合計（バイト）を運営の様子に。上限の 9 割を超えたら「早めに」、上限に届いたら「急ぎ」
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_digests', function (Blueprint $table): void {
            $table->bigInteger('media_bytes')->default(0);
        });
        DB::statement('ALTER TABLE shop_digests ADD CONSTRAINT shop_digests_media_bytes CHECK (media_bytes >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_digests DROP CONSTRAINT IF EXISTS shop_digests_media_bytes');
        Schema::table('shop_digests', function (Blueprint $table): void {
            $table->dropColumn('media_bytes');
        });
    }
};
