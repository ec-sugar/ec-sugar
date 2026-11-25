<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 年齢確認の App の表（ADR 0077）。店の DB に置く。使わない店では空のまま（ADR 0025 の結果）。
 *
 * - age_check_listings: 年齢の確かめが要る商品と、その年齢（20・18・15。1 商品に 1 行。要らなくしたら行を消す）
 * - age_check_members: 会員が注文で確かめた年齢（「何歳以上か」だけ。20・18・15）。年は経っても減らないので、これ以下の品では生年月日を聞かない。
 *   書くのは注文の確定の錠の中（核の order.age_verified と同じトランザクション）。退会で消す
 *
 * **生年月日は持たない**（どの表にも列を作らない。テストが見張る）。規則は DB でも守る
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('age_check_listings', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->primary()->constrained('listings')->cascadeOnDelete();
            $table->unsignedSmallInteger('age');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE age_check_listings ADD CONSTRAINT age_check_listings_age CHECK (age IN (15, 18, 20))');

        Schema::create('age_check_members', function (Blueprint $table): void {
            $table->foreignUlid('customer_account_id')->primary()->constrained('customer_accounts')->cascadeOnDelete();
            $table->unsignedSmallInteger('age');
            $table->timestampTz('verified_at');
        });
        DB::statement('ALTER TABLE age_check_members ADD CONSTRAINT age_check_members_age CHECK (age IN (15, 18, 20))');
    }

    public function down(): void
    {
        Schema::dropIfExists('age_check_members');
        Schema::dropIfExists('age_check_listings');
    }
};
