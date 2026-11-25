<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * おかしな動きの知らせ（ADR 0103）。中央に置くのは「どの種類が・どの店で・何件・いつからいつまで」だけ。
 * 接続元（IP アドレス）・鍵・運営・お客さん・注文は置かない。書くのは Anomalies::detect だけ（operations:alert の中）。
 *
 * 店の様子（shop_digests）には、この 1 時間ほどの返金の山（件数と合計の円）を足す。数えるのは店の中（ShopDigests）で、中央へは数だけ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table): void {
            // 種類と店（例 anomaly_refunds:shop1、店に依らないものは anomaly_denials:）。困りごとの印（alerted_issues）と同じ形
            $table->string('signature', 128)->primary();
            $table->string('key', 40);
            // 外部キーは付けない（消えた店は名前の無い ID で出し、日が経てば消える。incidents と同じ）
            $table->string('tenant_id')->nullable();
            $table->integer('count');                            // 件数（種類ごとの数え方は Anomalies）
            $table->bigInteger('amount')->nullable();            // 返金の合計（円の整数）。ほかの種類は空
            $table->timestampTz('first_at');                     // この続きで最初に見つけた時刻
            $table->timestampTz('last_at');                      // 最後に見つけた時刻（ここから一定の間、運営の様子に出す）
            $table->index('last_at');
        });

        DB::statement("ALTER TABLE anomalies ADD CONSTRAINT anomalies_key CHECK (key IN ('anomaly_refunds', 'anomaly_payment_keys', 'anomaly_denials', 'anomaly_agent_writes', 'anomaly_access_changes'))");
        DB::statement('ALTER TABLE anomalies ADD CONSTRAINT anomalies_numbers CHECK (count >= 1 AND (amount IS NULL OR amount >= 0) AND first_at <= last_at)');

        DB::statement('ALTER TABLE shop_digests ADD COLUMN refund_peak_count integer NOT NULL DEFAULT 0 CHECK (refund_peak_count >= 0)');
        DB::statement('ALTER TABLE shop_digests ADD COLUMN refund_peak_amount bigint NOT NULL DEFAULT 0 CHECK (refund_peak_amount >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_digests DROP COLUMN refund_peak_amount');
        DB::statement('ALTER TABLE shop_digests DROP COLUMN refund_peak_count');
        Schema::dropIfExists('anomalies');
    }
};
