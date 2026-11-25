<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 運営全体の画面の数（ADR 0042）。店ごとの定期の処理が、店の文脈の中で数えた結果だけを中央に書く。
 * 中央は店の DB を読みに行かない。ここに置くのは日ごとの件数と金額（円の整数）・困りごとの件数・公開の状態だけで、
 * お客さん・注文の中身・商品名は置かない。店を消すと一緒に消える。書くのは ShopDigests だけ（何度書き直しても同じ結果になる）
 */
return new class extends Migration
{
    public function up(): void
    {
        // 日ごとの数（日は日本の時刻の日付）。数え方はホームの数（ShopHome）と同じ: 支払いの済んだ物販の注文、取消・返金は外す、テストの注文は件数だけ別に
        Schema::create('shop_daily_sales', function (Blueprint $table): void {
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->date('day');
            $table->integer('orders');
            $table->bigInteger('sales');
            $table->integer('test_orders');
            $table->timestampTz('collected_at');
            $table->primary(['tenant_id', 'day']);
            $table->index('day');
        });

        // 店ごとの様子（1 店 1 行）。困りごとは件数だけ（どの注文かは店の中のホームで見る）
        Schema::create('shop_digests', function (Blueprint $table): void {
            $table->string('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('status', 16)->nullable();          // preparing | open（まだ集めていなければ null）
            $table->integer('stray_payments')->default(0);      // 返金が要る支払い
            $table->integer('refund_issues')->default(0);       // 確かめが要る返金
            $table->integer('stuck_mails')->default(0);         // 送りかけのまま残った知らせのメール
            $table->boolean('chain_intact')->nullable();        // 記録の改竄の検証（まだなら null）
            $table->timestampTz('chain_checked_at')->nullable();
            $table->timestampTz('collected_at')->nullable();    // 最後に集められた時刻
            $table->timestampTz('failed_at')->nullable();       // 最後に集められなかった時刻（集められたら消す）
        });

        DB::statement('ALTER TABLE shop_daily_sales ADD CONSTRAINT shop_daily_sales_counts CHECK (orders >= 0 AND sales >= 0 AND test_orders >= 0)');
        DB::statement("ALTER TABLE shop_digests ADD CONSTRAINT shop_digests_status CHECK (status IS NULL OR status IN ('preparing', 'open'))");
        DB::statement('ALTER TABLE shop_digests ADD CONSTRAINT shop_digests_counts CHECK (stray_payments >= 0 AND refund_issues >= 0 AND stuck_mails >= 0)');
        DB::statement('ALTER TABLE shop_digests ADD CONSTRAINT shop_digests_chain CHECK ((chain_intact IS NULL) = (chain_checked_at IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_digests');
        Schema::dropIfExists('shop_daily_sales');
    }
};
