<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * メルマガ・一斉メール（App「メルマガ・一斉メール」。ADR 0025）。プラグインの表なので名前は newsletter で始め、核の表は変えない。
 *
 * - newsletter_subscribers: 受け取ると選んだ人（1 行 = 1 つのメール）。pending（確かめのメール待ち）→ active（届ける）→ unsubscribed（止めた）。
 *   メールは店の鍵で暗号化し（email）、引くのは email_index（ブラインドインデックス）だけ（ADR 0010。お客さんの台帳と同じ形）。
 *   同意の記録（特定電子メール法 第 3 条 2 項）として、同意した日時（consented_at）と経路（source）・確かめた日時・止めた日時と経路を残す。
 *   止めた人のメールは、記録を残す期間（止めてから 1 か月。NewsletterSubscribers::RETAIN_DAYS）の後に消す
 * - newsletter_campaigns: 配信（題と本文は文だけ）。draft（下書き）→ scheduled（予約）→ sending（配信中）→ sent（配信済み）か stopped（止めた）
 * - newsletter_deliveries: 1 通ごとの送った記録（配信 × 購読者）。配信の停止のリンクの合い言葉はハッシュだけ（token_hash）
 *
 * 状態・進行中の購読にメールがあること・1 つのメールに 1 行・1 つの配信で 1 人に 1 通は DB でも守る
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // ログインした会員が選んだ時（会員のメールと同じアドレスの時だけ。退会したら止める）
            $table->foreignUlid('customer_account_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->text('email')->nullable();                 // 暗号化。記録を残す期間を過ぎた、止めた人では空
            $table->char('email_index', 64)->nullable();
            $table->string('status', 16);
            $table->string('source', 16);                      // 同意の経路: form（受付の画面）・checkout（注文の入力）・account（マイページ）
            $table->char('token_hash', 64)->nullable()->unique(); // 確かめのリンクの合い言葉のハッシュ（pending だけ）
            $table->text('mail_token')->nullable();            // 暗号化。確かめのメールを送るまでの間だけ
            $table->timestampTz('expires_at')->nullable();     // 確かめの期限（pending だけ）
            $table->timestampTz('consented_at');               // 本人が選んだ日時（受付の送信・注文の確定・マイページ）
            $table->timestampTz('confirmed_at')->nullable();   // 届けると決まった日時（ゲストは確かめのリンクを押した、会員は選んだ日時）
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->string('unsubscribed_via', 16)->nullable(); // link（停止の画面）・one_click（メールのソフトの停止）・account（マイページ）・leave（退会）
            $table->timestampsTz();

            $table->index(['status', 'confirmed_at']);
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'unsubscribed_at']);
            $table->index('customer_account_id');
        });

        DB::statement("ALTER TABLE newsletter_subscribers ADD CONSTRAINT newsletter_subscribers_status_check CHECK (status IN ('pending', 'active', 'unsubscribed'))");
        DB::statement("ALTER TABLE newsletter_subscribers ADD CONSTRAINT newsletter_subscribers_source_check CHECK (source IN ('form', 'checkout', 'account'))");
        DB::statement("ALTER TABLE newsletter_subscribers ADD CONSTRAINT newsletter_subscribers_via_check CHECK (unsubscribed_via IS NULL OR unsubscribed_via IN ('link', 'one_click', 'account', 'leave'))");
        DB::statement("ALTER TABLE newsletter_subscribers ADD CONSTRAINT newsletter_subscribers_live_check CHECK (status = 'unsubscribed' OR (email IS NOT NULL AND email_index IS NOT NULL))");
        DB::statement("ALTER TABLE newsletter_subscribers ADD CONSTRAINT newsletter_subscribers_pending_check CHECK (status <> 'pending' OR (expires_at IS NOT NULL AND token_hash IS NOT NULL))");
        DB::statement("ALTER TABLE newsletter_subscribers ADD CONSTRAINT newsletter_subscribers_active_check CHECK (status <> 'active' OR confirmed_at IS NOT NULL)");
        DB::statement("ALTER TABLE newsletter_subscribers ADD CONSTRAINT newsletter_subscribers_stopped_check CHECK (status <> 'unsubscribed' OR unsubscribed_at IS NOT NULL)");
        DB::statement('CREATE UNIQUE INDEX newsletter_subscribers_email_unique ON newsletter_subscribers (email_index) WHERE email_index IS NOT NULL');

        Schema::create('newsletter_campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 16);
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'scheduled_at']);
        });

        DB::statement("ALTER TABLE newsletter_campaigns ADD CONSTRAINT newsletter_campaigns_status_check CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'stopped'))");
        DB::statement("ALTER TABLE newsletter_campaigns ADD CONSTRAINT newsletter_campaigns_scheduled_check CHECK (status <> 'scheduled' OR scheduled_at IS NOT NULL)");
        DB::statement("ALTER TABLE newsletter_campaigns ADD CONSTRAINT newsletter_campaigns_started_check CHECK (status NOT IN ('sending', 'sent') OR started_at IS NOT NULL)");
        DB::statement('ALTER TABLE newsletter_campaigns ADD CONSTRAINT newsletter_campaigns_body_check CHECK (char_length(body) <= 5000)');

        Schema::create('newsletter_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('campaign_id')->constrained('newsletter_campaigns')->restrictOnDelete();
            $table->foreignUlid('subscriber_id')->constrained('newsletter_subscribers')->restrictOnDelete();
            $table->string('status', 16);                       // queued（キューに積んだ）→ sending（送っている）→ sent・failed・skipped（止めた・もう届けない人）
            $table->char('token_hash', 64)->nullable()->unique(); // 配信の停止のリンクの合い言葉のハッシュ（送る時に作る）
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable(); // このメールの停止のリンクで止めた日時（配信ごとの「止めた数」）
            $table->timestampsTz();

            $table->unique(['campaign_id', 'subscriber_id']);
            $table->index(['campaign_id', 'status']);
            $table->index(['status', 'updated_at']);
        });

        DB::statement("ALTER TABLE newsletter_deliveries ADD CONSTRAINT newsletter_deliveries_status_check CHECK (status IN ('queued', 'sending', 'sent', 'failed', 'skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_deliveries');
        Schema::dropIfExists('newsletter_campaigns');
        Schema::dropIfExists('newsletter_subscribers');
    }
};
