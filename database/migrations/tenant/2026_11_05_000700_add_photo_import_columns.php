<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商品の CSV で写真もまとめて取り込む（docs/design/product-csv.md の「写真」）。
 *
 * listing_images:
 * - source_digest は作り直す前の元のファイルの SHA-256。同じ写真（中身が同じ）を同じ商品へ二重に作らない
 * - source_url_digest は取りに行った URL の SHA-256（URL そのものは残さない）。入れ直しで同じ URL を取りに行き直さない
 *
 * product_imports:
 * - archive は一緒に上げた ZIP を展開した置き場の名前（ULID。写真の置き場の tenants/{店}/imports/{archive}/）、archive_files はその中身の一覧
 *   （ZIP の中の名前 => 置いたファイルと中身のハッシュ、か、使えない理由）。済んだ・断った・古くなったら置き場ごと消す
 * - photo_next は反映の後半（写真）の続きの位置。null なら商品の段
 * - photos は写真の反映の結果（足した・付いていた・取れなかった数と理由）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_images', function (Blueprint $table): void {
            $table->char('source_digest', 64)->nullable();
            $table->char('source_url_digest', 64)->nullable();
            $table->index(['listing_id', 'source_digest']);
            $table->index(['listing_id', 'source_url_digest']);
        });

        Schema::table('product_imports', function (Blueprint $table): void {
            $table->char('archive', 26)->nullable();
            $table->jsonb('archive_files')->nullable();
            $table->unsignedInteger('photo_next')->nullable();
            $table->jsonb('photos')->nullable();
        });

        // 置き場の名前は ULID の形だけ（置き場の外を指せない）
        DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_archive_ulid CHECK (archive IS NULL OR archive ~ '^[0-9a-hjkmnp-tv-z]{26}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_imports DROP CONSTRAINT IF EXISTS product_imports_archive_ulid');

        Schema::table('product_imports', function (Blueprint $table): void {
            $table->dropColumn(['archive', 'archive_files', 'photo_next', 'photos']);
        });

        Schema::table('listing_images', function (Blueprint $table): void {
            $table->dropIndex(['listing_id', 'source_digest']);
            $table->dropIndex(['listing_id', 'source_url_digest']);
            $table->dropColumn(['source_digest', 'source_url_digest']);
        });
    }
};
