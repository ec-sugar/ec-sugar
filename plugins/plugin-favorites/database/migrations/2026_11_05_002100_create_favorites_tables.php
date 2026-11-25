<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * お気に入り（App「お気に入り」、ADR 0071）。プラグインの表なので名前は favorites で始め、核の表は変えない（商品・会員の台帳を参照するだけ）。
 * どの表にも名前・メール・住所は置かない（会員は台帳の ID で指す。メールは送る時に台帳の暗号化された値から読む。ADR 0010）。
 *
 * - favorites_items: 会員 × 商品で 1 行（一意。同時に 2 回押しても 1 行）。1 人 500 件までは App が会員ごとの錠の中で数える
 * - favorites_settings: 会員ごとの「値下げ・再入荷を知らせる」の選択（既定はどちらも切）
 * - favorites_prices: お気に入りにある商品の、前の日に見た値段と売り切れか（毎日の処理が前の日と比べ、日に 1 回だけ書き換える）
 * - favorites_notices: 会員 × 日で 1 行の、送った知らせの記録（一意。日に 1 通まで）。載せた商品と変わり方（ID と金額だけ）と、配信の停止の合い言葉のハッシュ
 *
 * 会員や商品が消えたら一緒に消す（App の表のために核の消す操作を止めない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['customer_account_id', 'listing_id']);
            $table->index(['listing_id']);
        });

        Schema::create('favorites_settings', function (Blueprint $table): void {
            $table->foreignUlid('customer_account_id')->primary()->constrained('customer_accounts')->cascadeOnDelete();
            $table->boolean('price_drop')->default(false);
            $table->boolean('restock')->default(false);
            $table->timestampsTz();
        });

        Schema::create('favorites_prices', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->primary()->constrained('listings')->cascadeOnDelete();
            $table->bigInteger('price');
            $table->boolean('sold_out');
            $table->date('seen_on');
            $table->timestampTz('updated_at');
        });

        Schema::create('favorites_notices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->date('notice_on');
            $table->jsonb('items');                                    // [{listing_id, kind: price_drop|restock, before, after}]
            $table->string('status', 16);                              // queued → sent・failed・skipped
            $table->char('unsubscribe_hash', 64)->nullable()->unique(); // 送る時に作る
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['customer_account_id', 'notice_on']);
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE favorites_prices ADD CONSTRAINT favorites_prices_price_check CHECK (price >= 0)');
        DB::statement("ALTER TABLE favorites_notices ADD CONSTRAINT favorites_notices_status_check CHECK (status IN ('queued', 'sent', 'failed', 'skipped'))");
        DB::statement("ALTER TABLE favorites_notices ADD CONSTRAINT favorites_notices_sent_check CHECK (status <> 'sent' OR (sent_at IS NOT NULL AND unsubscribe_hash IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites_notices');
        Schema::dropIfExists('favorites_prices');
        Schema::dropIfExists('favorites_settings');
        Schema::dropIfExists('favorites_items');
    }
};
