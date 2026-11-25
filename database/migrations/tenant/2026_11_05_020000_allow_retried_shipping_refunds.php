<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 断られた送料の差額の返金（ADR 0064 の 6）を、店の人が「差額をもう一度返金する」で頼み直したときの片付けの印（retried）を許す。
 * 断られた記録は Stripe の答え（status = failed と断りの符号）のまま残し、片付けの印だけを付ける。
 * 頼み直した新しい返金は別の行（一意の索引 refunds_one_shipping は失敗した行を数えないので、生きた差額の返金は 1 注文に 1 つのまま）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_resolution');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_resolution CHECK ((resolution IS NULL) = (resolved_at IS NULL) AND (resolution IS NULL OR (resolution = 'recorded' AND status = 'succeeded') OR (resolution = 'dismissed' AND status = 'failed' AND failure_reason = 'dismissed') OR (resolution = 'retried' AND status = 'failed' AND purpose = 'shipping')))");
    }

    public function down(): void
    {
        DB::statement("UPDATE refunds SET resolution = NULL, resolved_at = NULL WHERE resolution = 'retried'");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_resolution');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_resolution CHECK ((resolution IS NULL) = (resolved_at IS NULL) AND (resolution IS NULL OR (resolution = 'recorded' AND status = 'succeeded') OR (resolution = 'dismissed' AND status = 'failed' AND failure_reason = 'dismissed')))");
    }
};
