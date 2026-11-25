<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 予約販売の App の表（ADR 0073）。店の DB に置く。使わない店では空のまま（ADR 0025 の結果）。
 *
 * - preorder_listings: 商品ごとの予約販売の決まり（1 商品に 1 行。消さずに止める）。発売日・受付の終わり（その日まで受ける）・上限（空なら上限なし）。
 *   回（round）は予約の受付を始め直すたびに 1 つ進める（発売日を過ぎた後に次の予約を始める。前の回の予約は数えない）
 * - preorder_items: 注文ごとの予約（注文の確定の錠の中で書く）。数と、その注文に約束した発売日（店が発売日を変えると、発送の前の注文は新しい日に直す）。
 *   予約の数は、取り消し・返金で終わっていない注文の今の回の数の合計（払われずに消えた注文は取消になるので、数え直すだけで戻る）
 *
 * 個人情報は持たない（お客さんは注文でつながるだけ）。規則は DB でも守る
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preorder_listings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('round')->default(1);
            $table->date('release_date');
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE preorder_listings ADD CONSTRAINT preorder_listings_capacity CHECK (capacity IS NULL OR capacity BETWEEN 1 AND 100000)');
        DB::statement('ALTER TABLE preorder_listings ADD CONSTRAINT preorder_listings_ends_before_release CHECK (ends_on IS NULL OR ends_on < release_date)');
        DB::statement('ALTER TABLE preorder_listings ADD CONSTRAINT preorder_listings_round CHECK (round >= 1)');

        Schema::create('preorder_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('listing_id')->constrained('listings')->restrictOnDelete();
            $table->unsignedInteger('round');
            $table->unsignedInteger('quantity');
            $table->date('release_date');
            $table->timestampsTz();
            $table->unique(['transaction_id', 'listing_id']);
            $table->index(['listing_id', 'round']);
        });
        DB::statement('ALTER TABLE preorder_items ADD CONSTRAINT preorder_items_quantity CHECK (quantity BETWEEN 1 AND 1000000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('preorder_items');
        Schema::dropIfExists('preorder_listings');
    }
};
