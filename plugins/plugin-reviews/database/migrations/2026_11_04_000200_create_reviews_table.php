<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商品レビューの表（App「商品レビュー」、ADR 0025・0050）。プラグインの表なので名前は reviews で始め、核の表は変えない（注文と商品を参照するだけ）。
 *
 * - reviews: どの注文（transaction_id）のどの商品（listing_id）か・星（1〜5）・題・本文・表示名・状態・店からの返信
 * 書いた人は注文でつなぐだけで、名前・メール・届け先・お客さんの台帳の ID は持たない（ADR 0010。表示名はお客さんが「出してよい」と書いた名前で、既定は「購入者」）。
 * 規則は DB でも守る: 1 つの注文の 1 つの商品に 1 つ（一意）、星は 1〜5、文の長さと空でないこと、状態の言葉。
 * 消した（deleted）行は、文と表示名と返信を空にして残す（同じ注文の同じ商品にもう一度書かせない。中身は残さない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('listing_id')->constrained('listings')->restrictOnDelete();
            $table->smallInteger('rating');
            $table->string('title', 50)->nullable();
            $table->text('body')->nullable();
            $table->string('display_name', 20)->nullable();
            $table->string('status', 16);
            $table->text('reply')->nullable();
            $table->timestampTz('replied_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['transaction_id', 'listing_id']);
            $table->index(['listing_id', 'status', 'published_at']);
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_status_check CHECK (status IN ('pending', 'published', 'hidden', 'deleted'))");
        // CHECK は式が NULL のとき通るので、消していない行は文が NULL でないことを先に書く（NULL の文を通さない）
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_text_check CHECK (
            (status = 'deleted' AND title IS NULL AND body IS NULL AND display_name IS NULL AND reply IS NULL)
            OR (status <> 'deleted' AND title IS NOT NULL AND body IS NOT NULL AND display_name IS NOT NULL
                AND char_length(btrim(title)) > 0 AND char_length(btrim(body)) > 0 AND char_length(body) <= 1000
                AND char_length(btrim(display_name)) > 0)
        )");
        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_reply_check CHECK (reply IS NULL OR (char_length(btrim(reply)) > 0 AND char_length(reply) <= 1000))');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_published_check CHECK (status <> 'published' OR published_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
