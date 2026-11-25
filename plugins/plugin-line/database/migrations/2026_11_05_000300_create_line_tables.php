<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LINE 連携（App「LINE 連携」、ADR 0070）。プラグインの表なので名前は line で始め、核の表は変えない。
 *
 * - line_links: 会員と LINE の利用者のつなぎ（1 行 = 1 人の会員 = 1 つの LINE）。LINE の利用者の ID（sub）は個人に結びつく識別子として
 *   店の鍵で暗号化し（line_user）、引くのは line_user_index（店の鍵の HMAC）の完全一致だけ（ADR 0010）。
 *   1 つの LINE は 1 人の会員だけ・1 人の会員に 1 つの LINE だけを、DB の一意でも守る。退会・外す・台帳を消すと行も消える。
 *   friend は「お店の公式アカウントと友だちか」（送ってよい相手か）。friend_at_ms はその印を最後に変えた知らせの時刻（ミリ秒の整数。
 *   LINE の知らせの時刻はミリ秒なので、秒に丸めると同じ秒の前後が見分けられない。古い知らせで戻さない）
 * - line_messages: 送った知らせの記録（同じ注文の同じ知らせは 1 回だけ。dedupe_key の一意）。宛先の ID も本文も持たない（数と結果だけ）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->unique()->constrained('customer_accounts')->cascadeOnDelete();
            $table->text('line_user');                       // 暗号化（LINE の利用者の ID）
            $table->char('line_user_index', 64)->unique();   // 店の鍵の HMAC
            $table->boolean('friend')->default(false);
            $table->bigInteger('friend_at_ms')->nullable();
            $table->timestampTz('linked_at');
            $table->timestampsTz();

            $table->index('friend');
        });

        Schema::create('line_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('dedupe_key', 120)->unique();     // 知らせの種類|注文|受渡
            $table->string('kind', 32);
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->string('status', 16);                    // pending（送っている）→ sent・failed・skipped（上限で送らなかった）
            $table->string('error', 32)->nullable();         // 送れなかった理由の言葉（相手の答えの番号など。本文は残さない）
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE line_messages ADD CONSTRAINT line_messages_kind_check CHECK (kind IN ('order_paid', 'order_shipped'))");
        DB::statement("ALTER TABLE line_messages ADD CONSTRAINT line_messages_status_check CHECK (status IN ('pending', 'sent', 'failed', 'skipped'))");
        DB::statement("ALTER TABLE line_messages ADD CONSTRAINT line_messages_sent_check CHECK (status <> 'sent' OR sent_at IS NOT NULL)");
        DB::statement("ALTER TABLE line_messages ADD CONSTRAINT line_messages_error_check CHECK (error IS NULL OR error ~ '^[a-z0-9_]{1,32}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('line_messages');
        Schema::dropIfExists('line_links');
    }
};
