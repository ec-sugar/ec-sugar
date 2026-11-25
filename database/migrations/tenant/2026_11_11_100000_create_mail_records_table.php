<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 店が送ったメールの記録（ADR 0095）。注文の知らせ・会員のログインのコード・App のメール・試しのメールを、送る口（メールの transport）で 1 通 1 行に残す。
 *
 * - 状態は sending（送っている）・sent（送った）・failed（送れなかった）。送れなかった理由は決まった語（reason）と SMTP の応答の番号だけ。
 *   例外の文・SMTP の応答の文（宛先のアドレスが入ることがある）・本文・秘密は残さない
 * - 宛先と件名は店の鍵で暗号化する（ADR 0010）。宛先の最初の 1 つはブラインドインデックス（recipient_index）で、お客さんの台帳（customer_accounts.email_index）と同じ値になる
 * - 同じキューの仕事のやり直しは同じ行を直す（job_id。画面には出さない）。メルマガの一斉の配信は送れなかった分だけを残す
 * - 状態（取引・在庫・お金）でなく、監査の源泉でもない「送ったか」の記録なので、連鎖のロックとイベントは使わない（conventions 3 の例外）。古いものは 90 日で消す
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // <core か App の名前>.<何のメールか>（例 core.order_paid・restock.available）
            $table->string('kind', 64);
            $table->text('subject');                        // 件名（暗号化）
            $table->string('status', 16);
            $table->string('reason', 16)->nullable();       // 送れなかった理由の語
            $table->smallInteger('smtp_code')->nullable();  // SMTP の応答の番号（550 など）
            $table->string('smtp_status', 11)->nullable();  // 拡張の状態の番号（5.1.1 など）
            $table->smallInteger('attempts')->default(1);
            $table->text('recipients');                     // 宛先の一覧（暗号化した JSON）
            $table->char('recipient_index', 64)->nullable(); // 最初の宛先のブラインドインデックス
            $table->smallInteger('recipient_count')->default(1);
            $table->ulid('transaction_id')->nullable();     // 関係する注文（外部キーは付けない。記録が送ることを止めないように）
            $table->uuid('job_id')->nullable();             // 送ったキューの仕事（やり直しを同じ行にまとめる）
            $table->char('trace_id', 26)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });

        DB::statement("ALTER TABLE mail_records ADD CONSTRAINT mail_records_kind CHECK (kind ~ '^[a-z][a-z0-9-]{0,31}\\.[a-z][a-z0-9_-]{0,31}$')");
        DB::statement("ALTER TABLE mail_records ADD CONSTRAINT mail_records_status CHECK (status IN ('sending', 'sent', 'failed') AND (status = 'sent') = (sent_at IS NOT NULL) AND (status = 'failed') = (reason IS NOT NULL))");
        DB::statement("ALTER TABLE mail_records ADD CONSTRAINT mail_records_reason CHECK (reason IS NULL OR reason IN ('rejected', 'temporary', 'auth', 'connection', 'timeout', 'error'))");
        DB::statement('ALTER TABLE mail_records ADD CONSTRAINT mail_records_smtp_code CHECK (smtp_code IS NULL OR smtp_code BETWEEN 200 AND 599)');
        DB::statement("ALTER TABLE mail_records ADD CONSTRAINT mail_records_smtp_status CHECK (smtp_status IS NULL OR smtp_status ~ '^[245]\\.[0-9]{1,3}\\.[0-9]{1,3}$')");
        DB::statement('ALTER TABLE mail_records ADD CONSTRAINT mail_records_counts CHECK (attempts >= 1 AND recipient_count >= 0)');
        DB::statement("ALTER TABLE mail_records ADD CONSTRAINT mail_records_trace_id_shape CHECK (trace_id IS NULL OR trace_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$')");

        DB::statement('CREATE INDEX mail_records_recent ON mail_records (created_at DESC, id DESC)');
        DB::statement('CREATE INDEX mail_records_status_recent ON mail_records (status, created_at DESC)');
        DB::statement('CREATE INDEX mail_records_kind_recent ON mail_records (kind, created_at DESC)');
        DB::statement('CREATE INDEX mail_records_recipient ON mail_records (recipient_index) WHERE recipient_index IS NOT NULL');
        DB::statement('CREATE INDEX mail_records_transaction ON mail_records (transaction_id) WHERE transaction_id IS NOT NULL');
        DB::statement('CREATE INDEX mail_records_job ON mail_records (job_id) WHERE job_id IS NOT NULL');
        DB::statement('CREATE INDEX mail_records_trace_id ON mail_records (trace_id) WHERE trace_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_records');
    }
};
