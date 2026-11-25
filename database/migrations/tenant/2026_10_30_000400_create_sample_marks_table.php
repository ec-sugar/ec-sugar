<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * サンプルの印。`shop:sample` が入れた物（商品・カテゴリ・ショップの写真・テーマの中身・特商法の表記）を 1 つ 1 行で持つ。
 * 書くのは ShopSamples だけ（入れる・まとめて消す。どちらも EventStore::lock() の中）。
 *
 * - key はサンプルの中の名前（例 listing:canvas-tote）。一意なので、流し直しても同じ物を二度入れない。消した後も行は残す（流し直しで戻さない）
 * - snapshot は入れたときの形（商品のバージョン・写真の ID、テーマの改訂、表記の SHA-256）。お店が書き換えたかをこれと比べて見る
 * - 終わった印（ended_at）は、消した（removed）か、お店の物として残した（kept）か
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_marks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('subject_type', 32);
            $table->char('subject_id', 26)->nullable();
            $table->jsonb('snapshot');
            $table->timestampTz('ended_at')->nullable();
            $table->string('outcome', 16)->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'ended_at']);
        });

        DB::statement("ALTER TABLE sample_marks ADD CONSTRAINT sample_marks_key_format CHECK (key ~ '^[a-z][a-z0-9_]*(:[a-z0-9-]{1,48})?$')");
        DB::statement("ALTER TABLE sample_marks ADD CONSTRAINT sample_marks_subject_type_known CHECK (subject_type IN ('listing', 'category', 'shop_media', 'theme_version', 'shop_settings'))");
        DB::statement("ALTER TABLE sample_marks ADD CONSTRAINT sample_marks_snapshot_object CHECK (jsonb_typeof(snapshot) = 'object')");
        // 終わった印は必ず結果を持ち、終わっていない印は持たない
        DB::statement("ALTER TABLE sample_marks ADD CONSTRAINT sample_marks_outcome_known CHECK ((ended_at IS NULL AND outcome IS NULL) OR (ended_at IS NOT NULL AND outcome IN ('removed', 'kept')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_marks');
    }
};
