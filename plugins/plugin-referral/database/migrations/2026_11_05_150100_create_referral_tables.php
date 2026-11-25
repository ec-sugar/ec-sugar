<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 友だち紹介（App「友だち紹介」、ADR 0075）。プラグインの表なので名前は referral で始め、核の表は変えない（外部キーだけ）。
 *
 * - referral_codes: 会員ごとの紹介のリンクの印（推測されにくい 10 文字。会員が作り直すと前の印は使えなくなる）。会員に 1 つ
 * - referrals: 紹介された人（会員の台帳）ごとに 1 行（紹介されるのは 1 回だけ。一意の索引）。
 *   linked（会員になった。最初の注文を待つ）→ ordered（最初の注文が払われた。発送を待つ）→ rewarded（両方に付けた）、
 *   または review（確かめ待ち。店の人が付ける／付けないを選ぶ）→ rewarded・declined（付けないにした）、または refused（決まりで付けなかった。理由は reason）。
 *   名前・メール・住所・電話・接続元は持たない（接続元は店の鍵の HMAC〔source_index〕だけ。同じ接続元から多数を見分けるため。ADR 0010）
 *
 * 書き込みは連鎖のロックとイベントの決まりのとおり（ポイントを付ける＝お金に近いため、例外にしない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table): void {
            $table->foreignUlid('customer_account_id')->primary()->constrained('customer_accounts')->restrictOnDelete();
            $table->string('code', 10)->unique();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE referral_codes ADD CONSTRAINT referral_codes_code_shape CHECK (code ~ '^[2-9A-HJ-NP-Za-km-z]{10}$')");

        Schema::create('referrals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('referrer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignUlid('friend_account_id')->unique()->constrained('customer_accounts')->restrictOnDelete();
            $table->string('status', 16);
            $table->string('reason', 16)->nullable();            // 付けなかった理由（refused）・確かめ待ちの理由（review。店が付けないにした declined にも残す）
            $table->foreignUlid('transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete(); // 最初に払われた注文
            $table->char('source_index', 64)->nullable();        // 会員になった時の接続元の HMAC（店の鍵。平文は持たない）
            $table->integer('referrer_points')->default(0);      // 付けたポイント（紹介した人）
            $table->integer('friend_points')->default(0);        // 付けたポイント（紹介された人）
            $table->timestampTz('linked_at');
            $table->timestampTz('ordered_at')->nullable();
            $table->timestampTz('decided_at')->nullable();       // 付けた・付けなかった・付けないにした日時
            $table->timestampsTz();

            $table->index(['referrer_account_id', 'status', 'decided_at']);
            $table->index(['referrer_account_id', 'source_index']);
            $table->index(['status', 'updated_at']);
        });

        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_status_check CHECK (status IN ('linked', 'ordered', 'review', 'rewarded', 'declined', 'refused'))");
        // 理由は付けなかった（refused）と、確かめ待ち（review）・そこから店が付けないにした（declined。確かめ待ちにした理由を残す）にだけ
        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_reason_check CHECK ((status IN ('refused', 'review', 'declined')) = (reason IS NOT NULL) AND (reason IS NULL OR (status IN ('review', 'declined') AND reason = 'same_source') OR (status = 'refused' AND reason IN ('self', 'limit', 'disabled', 'points_off', 'not_member', 'not_new', 'cancelled'))))");
        DB::statement('ALTER TABLE referrals ADD CONSTRAINT referrals_not_self CHECK (referrer_account_id <> friend_account_id)');
        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_order_check CHECK (status NOT IN ('ordered', 'review', 'rewarded', 'declined') OR (transaction_id IS NOT NULL AND ordered_at IS NOT NULL))");
        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_points_check CHECK (referrer_points BETWEEN 0 AND 10000 AND friend_points BETWEEN 0 AND 10000 AND (status = 'rewarded' OR (referrer_points = 0 AND friend_points = 0)))");
        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_decided_check CHECK ((status IN ('rewarded', 'declined', 'refused')) = (decided_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
    }
};
