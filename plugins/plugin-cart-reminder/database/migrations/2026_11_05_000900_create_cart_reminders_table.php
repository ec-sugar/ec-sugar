<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * カゴ落ちのお知らせ（App「カゴ落ちのお知らせ」、ADR 0067）。プラグインの表なので名前は cart_reminder で始め、核の表は変えない（外部キーだけ）。
 *
 * cart_reminders: 払われずに消えたショップの注文ごとに 1 行（注文ごとの一意。同時に回っても 1 つの注文に 1 回）。
 * queued（送る前）→ sending（送っている）→ sent（送った）・failed（送れなかった。送り直さない）、または skipped（送らないと決めた。理由は reason）。
 * 宛先のメールは持たない（送る時に注文の買う人の暗号化された値から読む。ADR 0010）。リンクと配信の停止の合い言葉はハッシュだけ。
 * 送った後に、リンクから戻った日時（returned_at）と、そこから払われた注文（recovered_transaction_id）を数のために残す
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_reminders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->string('status', 16);
            $table->string('reason', 24)->nullable();               // 送らなかった理由（skipped だけ）
            $table->char('token_hash', 64)->nullable()->unique();      // 「カートに戻す」のリンクの合い言葉のハッシュ（送る時に作る）
            $table->char('unsubscribe_hash', 64)->nullable()->unique(); // 配信の停止のリンクの合い言葉のハッシュ（送る時に作る）
            $table->timestampTz('expires_at')->nullable();            // リンクの期限（送ってから 7 日）
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('returned_at')->nullable();           // リンクを初めて開いた日時
            $table->foreignUlid('recovered_transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->timestampTz('recovered_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();       // このメールの停止のリンクで止めた日時
            $table->timestampsTz();

            $table->index(['status', 'updated_at']);
            $table->index(['status', 'sent_at']);
            $table->index('returned_at');
        });

        DB::statement("ALTER TABLE cart_reminders ADD CONSTRAINT cart_reminders_status_check CHECK (status IN ('queued', 'sending', 'sent', 'failed', 'skipped'))");
        DB::statement("ALTER TABLE cart_reminders ADD CONSTRAINT cart_reminders_reason_check CHECK ((status = 'skipped') = (reason IS NOT NULL) AND (reason IS NULL OR reason IN ('no_email', 'no_consent', 'ordered_later', 'recent', 'unavailable', 'no_sender', 'disabled')))");
        DB::statement("ALTER TABLE cart_reminders ADD CONSTRAINT cart_reminders_sent_check CHECK (status <> 'sent' OR (sent_at IS NOT NULL AND token_hash IS NOT NULL AND unsubscribe_hash IS NOT NULL AND expires_at IS NOT NULL))");
        DB::statement('ALTER TABLE cart_reminders ADD CONSTRAINT cart_reminders_recovered_check CHECK ((recovered_transaction_id IS NULL) = (recovered_at IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_reminders');
    }
};
