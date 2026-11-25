<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * お問い合わせ（App「お問い合わせ」、ADR 0025・0051）。プラグインの表なので名前は contact で始め、核の表は変えない（商品・注文・お客さんの台帳を参照するだけ）。
 *
 * - contact_inquiries: 1 行 = 1 件のお問い合わせ。種類（商品について・注文について・そのほか）・付いた商品と注文・ログインした会員・名前・メール・本文・状態。
 *   名前・メール・本文は店の鍵で暗号化し（ADR 0010）、メールは引くための索引（email_index。ブラインドインデックス）も置く。
 *   状態は new（新着）→ open（対応中）→ closed（済み）。済んでから店の決めた期間がたったら、行ごと消す（返信も消える）
 * - contact_replies: 店からの返信（メールで送った文）。本文は店の鍵で暗号化（お客さんの名前などを書くため）。送れた時刻（sent_at）
 *
 * 規則は DB でも守る: 種類と状態の言葉、済みと済んだ時刻はいつもそろう。付いた商品・注文は種類と別（お客さんが種類を選び直しても付けたまま）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_inquiries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 16);
            $table->foreignUlid('listing_id')->nullable()->constrained('listings')->restrictOnDelete();
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            // ログインした会員が送った時だけ（メールは会員のログインで確かめたもの）
            $table->foreignUlid('customer_account_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->text('name');          // 暗号化
            $table->text('email');         // 暗号化
            $table->char('email_index', 64);
            $table->text('body');          // 暗号化
            $table->string('status', 16);
            $table->timestampTz('last_replied_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['status', 'closed_at']);
            $table->index('email_index');
        });

        DB::statement("ALTER TABLE contact_inquiries ADD CONSTRAINT contact_inquiries_kind_check CHECK (kind IN ('product', 'order', 'other'))");
        DB::statement("ALTER TABLE contact_inquiries ADD CONSTRAINT contact_inquiries_status_check CHECK (status IN ('new', 'open', 'closed'))");
        DB::statement("ALTER TABLE contact_inquiries ADD CONSTRAINT contact_inquiries_closed_check CHECK ((status = 'closed') = (closed_at IS NOT NULL))");

        Schema::create('contact_replies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('inquiry_id')->constrained('contact_inquiries')->cascadeOnDelete();
            $table->text('body');          // 暗号化
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['inquiry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_replies');
        Schema::dropIfExists('contact_inquiries');
    }
};
