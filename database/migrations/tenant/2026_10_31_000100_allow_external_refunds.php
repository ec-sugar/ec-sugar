<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 決済代行の画面で直接した返金（外でした返金）を、止めた返金（held）として記録できるようにする（ADR 0027 の追記、ADR の見直し 2026-10-31）。
 * 目的に external、止めた理由に external_refund を足す。外でした返金は人が確かめるまで止めたまま（台帳へは自動で書かない）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_purpose');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_purpose CHECK (purpose IN ('cancel', 'stray', 'external') AND (purpose = 'stray') = (stray_payment_id IS NOT NULL))");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_held_reason');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_held_reason CHECK (status <> 'held' OR COALESCE(failure_reason, '') IN ('amount_mismatch', 'external_refund'))");
        // 外でした返金は止めたままで、理由も決まっている（知らせで勝手に成功へ進めて台帳に書かない）
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_external_held CHECK ((purpose = 'external') = (status = 'held' AND failure_reason = 'external_refund'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_external_held');
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_held_reason');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_held_reason CHECK (status <> 'held' OR COALESCE(failure_reason, '') = 'amount_mismatch')");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_purpose');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_purpose CHECK (purpose IN ('cancel', 'stray') AND (purpose = 'stray') = (stray_payment_id IS NOT NULL))");
    }
};
