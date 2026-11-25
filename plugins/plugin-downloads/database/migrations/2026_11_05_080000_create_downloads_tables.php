<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App「ダウンロード販売」（ADR 0084）の表。どれも個人情報を持たない（お客さんは注文でつながるだけ）。
 *
 * - downloads_listings: 商品ごとの期限の日数と回数（空なら App の設定）
 * - downloads_files: 商品に置いた売るファイル。置き場（downloads）の場所は店の ID が頭（tenants/<店>/files/<ID>.<拡張子>）。名前は店の人が上げた時の名前（表示だけ）。
 *   店の人が消しても、渡したリンクの期限までは置いておく（removed_at。新しい注文には渡さない）。置き場から消したら purged_at
 * - downloads_grants: 注文 × ファイルのリンク（払いが確かになった時に作る）。回数・上限・期限・最後の日時・止めた理由。回数は 0〜上限を DB でも守る
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downloads_listings', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->primary()->constrained('listings')->cascadeOnDelete();
            $table->smallInteger('days')->nullable();          // 期限の日数（空なら App の設定）
            $table->smallInteger('max_count')->nullable();     // 1 つのファイルの回数（空なら App の設定）
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE downloads_listings ADD CONSTRAINT downloads_listings_values CHECK ((days IS NULL OR days BETWEEN 1 AND 365) AND (max_count IS NULL OR max_count BETWEEN 1 AND 100))');

        Schema::create('downloads_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->constrained('listings')->restrictOnDelete();
            $table->string('name', 200);                       // 店の人が上げた時の名前（表示とお客さんが受け取る時の名前）
            $table->string('extension', 10);
            $table->string('mime', 100);                       // 中身から判定した型（渡す時の Content-Type）
            $table->bigInteger('bytes');
            $table->char('sha256', 64);
            $table->string('path', 255);                       // 置き場 downloads の中の場所（名前は作り直したもの）
            $table->timestampTz('removed_at')->nullable();     // 店の人が消した（新しい注文には渡さない）
            $table->timestampTz('purged_at')->nullable();      // 置き場から消した（渡したリンクの期限がみな過ぎた後）
            $table->timestampsTz();
            $table->index(['listing_id', 'removed_at']);
        });

        DB::statement("ALTER TABLE downloads_files ADD CONSTRAINT downloads_files_values CHECK (bytes > 0 AND length(name) > 0 AND extension ~ '^[a-z0-9]{1,10}$' AND sha256 ~ '^[0-9a-f]{64}$' AND path ~ '^tenants/[a-z0-9][a-z0-9_-]*/files/[0-9a-z]{26}\\.[a-z0-9]{1,10}$' AND (purged_at IS NULL OR removed_at IS NOT NULL))");

        Schema::create('downloads_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('file_id')->constrained('downloads_files')->restrictOnDelete();
            $table->integer('count')->default(0);              // ダウンロードした回数（押して渡した回数）
            $table->integer('max_count');                      // 上限（作った時の設定。店の人が回数を戻すと count が 0 に）
            $table->timestampTz('expires_at');                 // この時刻ちょうどから渡さない
            $table->timestampTz('last_downloaded_at')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->string('stop_reason', 16)->nullable();     // cancel 注文の取消・refund 返金・staff 店の人
            $table->timestampsTz();
            $table->unique(['transaction_id', 'file_id']);
        });

        DB::statement("ALTER TABLE downloads_grants ADD CONSTRAINT downloads_grants_values CHECK (max_count BETWEEN 1 AND 1000 AND count BETWEEN 0 AND max_count
            AND (stopped_at IS NULL) = (stop_reason IS NULL) AND (stop_reason IS NULL OR stop_reason IN ('cancel', 'refund', 'staff')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads_grants');
        Schema::dropIfExists('downloads_files');
        Schema::dropIfExists('downloads_listings');
    }
};
