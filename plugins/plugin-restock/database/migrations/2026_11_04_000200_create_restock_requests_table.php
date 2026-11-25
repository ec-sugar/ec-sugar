<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 再入荷のお知らせの登録（App「再入荷のお知らせ」、ADR 0025・0050）。プラグインの表なので名前は restock で始め、核の表は変えない（商品・種類・お客さんの台帳を参照するだけ）。
 *
 * - 1 行 = 1 つの種類への 1 人の登録。状態は pending（確かめのメール待ち）→ waiting（入荷を待つ）→ notified（知らせた）か cancelled（取り消した）
 * - メールは店の鍵で暗号化し（email）、引くのは email_index（ブラインドインデックス）だけ（ADR 0010。お客さんの台帳と同じ形）。
 *   知らせた・取り消した登録からはメールと索引を消す（終わった登録に連絡先を残さない。数だけが残る）
 * - 確かめ・取り消しのリンクの合い言葉（token）は、ハッシュ（token_hash）だけを置く。メールに載せるまでの間だけ、合い言葉そのものを暗号化して mail_token に置く（送ったら消す）
 * - 1 人が 1 つの種類に持てる進行中の登録は 1 つ（部分の一意の索引。同時の 2 つの送信の 2 枚目の守り）
 * - 状態と、進行中の登録にメールがあること・確かめ待ちに期限があることは DB でも守る
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restock_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->constrained('listings')->restrictOnDelete();
            $table->foreignUlid('variant_id')->constrained('listing_variants')->restrictOnDelete();
            // ログインした会員が登録した時だけ（退会したら取り消す）
            $table->foreignUlid('customer_account_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->text('email')->nullable();                 // 暗号化。終わった登録では空
            $table->char('email_index', 64)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->text('mail_token')->nullable();            // 暗号化。メールを送るまでの間だけ
            $table->string('status', 16);
            $table->timestampTz('expires_at')->nullable();     // 確かめの期限（pending だけ）
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['variant_id', 'status', 'confirmed_at']);
            $table->index(['email_index', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE restock_requests ADD CONSTRAINT restock_requests_status_check CHECK (status IN ('pending', 'waiting', 'notified', 'cancelled'))");
        DB::statement("ALTER TABLE restock_requests ADD CONSTRAINT restock_requests_active_check CHECK (status NOT IN ('pending', 'waiting') OR (email IS NOT NULL AND email_index IS NOT NULL))");
        DB::statement("ALTER TABLE restock_requests ADD CONSTRAINT restock_requests_pending_check CHECK (status <> 'pending' OR expires_at IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX restock_requests_active_unique ON restock_requests (variant_id, email_index) WHERE status IN ('pending', 'waiting')");
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_requests');
    }
};
