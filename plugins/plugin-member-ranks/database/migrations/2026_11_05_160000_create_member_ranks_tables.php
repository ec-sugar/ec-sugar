<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会員ランク（App「会員ランク」、ADR 0079）。プラグインの表なので名前は member_ranks で始め、核の表は変えない（外部キーだけ）。
 *
 * - member_ranks_members: 会員の台帳ごとの今のランク（0 はランクなし、1〜3 は店が決めた順の位置）。毎日の決め直しと、店の人の手の上げ下げ（pinned_until まで毎日の決め直しで変えない）
 * - member_ranks_changes: ランクが変わった記録（追記だけ。上がった知らせを送ったかの印 notice・noticed_at だけを後から書く）
 * - member_ranks_listings: 会員だけ（min_rank 0）・ランクだけ（1〜3 以上）の商品
 *
 * 名前・メール・購入の額は持たない（額は台帳から毎回数える）。書き込みは連鎖のロックとイベントの決まりのとおり（ランクは会員価格＝お金に効くため、例外にしない。
 * 知らせを送った印だけは「状態でない・お金に触れない・イベントの要らない」印で、1 文の条件つきの更新で書く）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_ranks_members', function (Blueprint $table): void {
            $table->foreignUlid('customer_account_id')->primary()->constrained('customer_accounts')->restrictOnDelete();
            $table->smallInteger('rank');
            $table->timestampTz('ranked_at');                 // 今のランクになった日時
            $table->timestampTz('pinned_until')->nullable();  // 店の人が手で決めたランクを、この日時まで毎日の決め直しで変えない
            $table->timestampsTz();

            $table->index('rank');
        });

        DB::statement('ALTER TABLE member_ranks_members ADD CONSTRAINT member_ranks_members_rank_check CHECK (rank BETWEEN 0 AND 3)');

        Schema::create('member_ranks_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->smallInteger('from_rank');
            $table->smallInteger('to_rank');
            $table->string('cause', 16);                      // auto（毎日の決め直し）・manual（店の人の手）・left（会員でなくなった）
            $table->string('notice', 16)->default('none');    // 上がった知らせ: none（送らない）・queued・sent・failed・skipped
            $table->timestampTz('noticed_at')->nullable();
            $table->timestampTz('created_at');

            $table->index(['customer_account_id', 'created_at']);
            $table->index(['notice', 'created_at']);
        });

        DB::statement('ALTER TABLE member_ranks_changes ADD CONSTRAINT member_ranks_changes_rank_check CHECK (from_rank BETWEEN 0 AND 3 AND to_rank BETWEEN 0 AND 3 AND from_rank <> to_rank)');
        DB::statement("ALTER TABLE member_ranks_changes ADD CONSTRAINT member_ranks_changes_cause_check CHECK (cause IN ('auto', 'manual', 'left'))");
        // 知らせるのは上がった時だけ
        DB::statement("ALTER TABLE member_ranks_changes ADD CONSTRAINT member_ranks_changes_notice_check CHECK (notice IN ('none', 'queued', 'sent', 'failed', 'skipped') AND (notice = 'none' OR to_rank > from_rank))");

        Schema::create('member_ranks_listings', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->primary()->constrained('listings')->cascadeOnDelete();
            $table->smallInteger('min_rank');                 // 0 は会員だけ、1〜3 はそのランク以上
            $table->timestampsTz();

            $table->index('min_rank');
        });

        DB::statement('ALTER TABLE member_ranks_listings ADD CONSTRAINT member_ranks_listings_rank_check CHECK (min_rank BETWEEN 0 AND 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('member_ranks_listings');
        Schema::dropIfExists('member_ranks_changes');
        Schema::dropIfExists('member_ranks_members');
    }
};
