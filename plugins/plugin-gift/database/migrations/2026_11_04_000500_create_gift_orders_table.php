<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 贈り物の表（ADR 0055）。プラグインの表なので名前は gift で始め、核の表は変えない（取引を参照するだけ）。
 *
 * - gift_orders: 贈り物の注文 1 つに 1 行（取引で一意）。差出人の表示名とメッセージ（店の鍵で暗号化）・状態・
 *   受け取りのリンクの token の sha256（引くための一意の索引）と、買う人に出し直すための token（店の鍵で暗号化）・期限・知らせた時刻
 * - 状態: preparing（払いの確認待ち）→ waiting（受け取り待ち）→ claimed（受け取り済み）。取り消しは cancelling（途中）→
 *   cancelled（取り消した）・expired（期限で返金した）、返金できなければ stopped（止めた。店の人が片付ける）
 * 状態と形は DB の制約でも守る（受け取り待ち・受け取り済みには token と期限がある・払いの確認待ちには token が無い・
 * 受け取った時刻は受け取り済みとその後の取り消しにだけ・閉じた時刻は取り消したものにだけ）。
 * 払いの確認待ちのまま注文が取り消された（払われずに期限が過ぎた）ものは、token の無いまま cancelled にする
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->text('sender');
            $table->text('message');
            $table->string('status', 16)->default('preparing');
            $table->char('token_hash', 64)->nullable()->unique();
            $table->text('token_sealed')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'expires_at']);
        });

        DB::statement('ALTER TABLE gift_orders ADD CONSTRAINT gift_orders_shape CHECK ('
            ."status IN ('preparing', 'waiting', 'claimed', 'cancelling', 'stopped', 'cancelled', 'expired')"
            ." AND (status <> 'preparing' OR (token_hash IS NULL AND token_sealed IS NULL AND expires_at IS NULL))"
            ." AND (status NOT IN ('waiting', 'claimed') OR (token_hash IS NOT NULL AND token_sealed IS NOT NULL AND expires_at IS NOT NULL))"
            .' AND (token_hash IS NULL) = (token_sealed IS NULL)'
            ." AND (token_hash IS NULL OR token_hash ~ '^[0-9a-f]{64}$')"
            ." AND ((status = 'claimed') = (claimed_at IS NOT NULL) OR status IN ('cancelling', 'stopped', 'cancelled', 'expired'))"
            ." AND ((status IN ('cancelled', 'expired')) = (closed_at IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_orders');
    }
};
