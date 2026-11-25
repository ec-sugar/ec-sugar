<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App「店への知らせ」の店の DB の表（ADR 0060 の 2）。
 *
 * - shop_notify_notices: 送った（送ろうとした）知らせの記録。宛先ごとに 1 行。同じ知らせを同じ宛先へ 2 回送らない（kind × dedupe_key × channel の一意）。
 *   facts は送った中身の材料（注文番号・金額・品数・商品名・管理画面へのリンク）で、名前・住所・メールなどの個人情報は入れない。
 *   宛先（Slack の URL・メールアドレス）はここに写さない（App の設定の秘密の項目に、店の鍵で暗号化して置くだけ）。error は決まった言葉だけ（相手の答えの中身は残さない）
 * - shop_notify_stock_marks: 在庫が少ないと知らせた種類の印（種類ごとに 1 行）。数が戻ったら消し、また減ったらもう 1 回知らせる
 *
 * どちらも状態でもお金でも在庫でもなく、イベントの要らない「送った記録」なので、連鎖のロックとイベントの決まりの例外（conventions.md の 3）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_notify_notices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 16);                       // paid・cancelled・low_stock・test
            $table->string('dedupe_key', 80);                 // 注文の ID・「種類の ID:印の ID」・試しの ID
            $table->string('channel', 8);                     // slack・mail
            $table->jsonb('facts')->default('{}');
            $table->string('status', 8)->default('pending');  // pending・sent・failed・skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 32)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->unique(['kind', 'dedupe_key', 'channel']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE shop_notify_notices ADD CONSTRAINT shop_notify_notices_kind_known CHECK (kind IN ('paid', 'cancelled', 'low_stock', 'test'))");
        DB::statement("ALTER TABLE shop_notify_notices ADD CONSTRAINT shop_notify_notices_channel_known CHECK (channel IN ('slack', 'mail'))");
        DB::statement("ALTER TABLE shop_notify_notices ADD CONSTRAINT shop_notify_notices_status_known CHECK (status IN ('pending', 'sent', 'failed', 'skipped'))");
        DB::statement("ALTER TABLE shop_notify_notices ADD CONSTRAINT shop_notify_notices_facts_object CHECK (jsonb_typeof(facts) = 'object')");

        Schema::create('shop_notify_stock_marks', function (Blueprint $table): void {
            $table->foreignUlid('variant_id')->primary()->constrained('listing_variants')->cascadeOnDelete();
            $table->ulid('mark_id');                          // 知らせの dedupe_key に使う（減るたびに新しい印）
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_notify_stock_marks');
        Schema::dropIfExists('shop_notify_notices');
    }
};
