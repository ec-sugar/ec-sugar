<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * お客さんへ送ったメールの履歴（注文ごとの「メール送信履歴」）。同じ注文の同じ知らせは 1 回だけ（送る前にこの行で押さえる）。
 * 宛先（メールアドレス）は残さない（暗号化したお客さんの行から送るときに読む）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_mails', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->string('kind', 32);                // order_paid | order_shipped
            $table->ulid('fulfillment_id')->nullable(); // 発送の知らせは受渡ごと
            $table->string('subject', 255);
            $table->string('status', 16)->default('sending'); // sending | sent
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at');
        });

        DB::statement("ALTER TABLE sent_mails ADD CONSTRAINT sent_mails_kind CHECK (kind IN ('order_paid', 'order_shipped'))");
        DB::statement("ALTER TABLE sent_mails ADD CONSTRAINT sent_mails_status CHECK (status IN ('sending', 'sent') AND (status = 'sent') = (sent_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX sent_mails_once ON sent_mails (transaction_id, kind, COALESCE(fulfillment_id, ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_mails');
    }
};
