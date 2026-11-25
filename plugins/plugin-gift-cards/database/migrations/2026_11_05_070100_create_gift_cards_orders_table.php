<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App「ギフトカード」の注文（ADR 0076）。ギフトカードを売る注文ごとに 1 行: 額面・その時の有効期限の日数・贈る相手のメール（任意）とメッセージ（任意）。
 * 贈る相手のメールとメッセージは個人情報になりうるので、店の鍵で暗号化して持つ（ADR 0010。列は暗号文）。発行したカードは核の gift_cards（売った注文で引く）。
 * 1 日に売る額の上限（盗んだカードでの買い込み・贈る相手へのメールの踏み台を抑える）のため、買った人のメールと接続元を店の鍵の HMAC（ブラインドインデックス）で持つ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards_orders', function (Blueprint $table): void {
            $table->foreignUlid('transaction_id')->primary()->constrained('transactions')->restrictOnDelete();
            $table->integer('amount');                          // 額面（円）
            $table->integer('valid_days');                      // 発行からの有効期限の日数（買った時の設定）
            $table->text('recipient_email')->nullable();        // 贈る相手のメール（暗号文）
            $table->text('message')->nullable();                // メッセージ（暗号文）
            $table->char('buyer_index', 64)->index();           // 買った人のメールのブラインドインデックス（1 日の上限）
            $table->char('source_index', 64)->index();          // 接続元（IPv6 は /64）の店の鍵の HMAC（1 日の上限）
            $table->timestampTz('created_at');
        });

        DB::statement('ALTER TABLE gift_cards_orders ADD CONSTRAINT gift_cards_orders_values CHECK (amount BETWEEN 1000 AND 50000 AND valid_days BETWEEN 1 AND 1095)');
        DB::statement('CREATE TRIGGER gift_cards_orders_append_only BEFORE UPDATE OR DELETE ON gift_cards_orders FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards_orders');
    }
};
