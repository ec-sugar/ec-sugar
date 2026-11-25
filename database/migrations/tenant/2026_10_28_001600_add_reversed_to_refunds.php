<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 返金の状態に reversed（成功の後に戻された）を足す（ADR 0027）。
 * Stripe はまれに、成功した返金を後から失敗にする（相手の銀行が受け取らないなど）。台帳は自動では戻さず、人が対応する（要対応に出す）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_status');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status CHECK (status IN ('requested', 'pending', 'succeeded', 'failed', 'reversed') AND (status IN ('succeeded', 'failed', 'reversed')) = (settled_at IS NOT NULL))");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_provider_ref');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_provider_ref CHECK (status = 'requested' OR status = 'failed' OR provider_ref IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement("UPDATE refunds SET status = 'succeeded' WHERE status = 'reversed'");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_status');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status CHECK (status IN ('requested', 'pending', 'succeeded', 'failed') AND (status IN ('succeeded', 'failed')) = (settled_at IS NOT NULL))");
    }
};
