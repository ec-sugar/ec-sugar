<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 止めた返金（held）を、店の人が Stripe で確かめてから片付ける（ADR 0048 の 4）。
 * 片付けの結果（recorded＝この額で返金として記録した・dismissed＝返金は無かった）と時刻を残す。
 * 外でした返金は、片付けるまでは止めたまま、片付けた後は成功か失敗（ADR 0027 の追記の決まりを広げる）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->string('resolution', 16)->nullable();
            $table->timestampTz('resolved_at')->nullable();
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_resolution CHECK ((resolution IS NULL) = (resolved_at IS NULL) AND (resolution IS NULL OR (resolution = 'recorded' AND status = 'succeeded') OR (resolution = 'dismissed' AND status = 'failed' AND failure_reason = 'dismissed')))");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_external_held');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_external_held CHECK (purpose <> 'external' OR (status = 'held' AND failure_reason = 'external_refund' AND resolution IS NULL) OR resolution IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_external_held');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_external_held CHECK ((purpose = 'external') = (status = 'held' AND failure_reason = 'external_refund'))");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_resolution');

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn(['resolution', 'resolved_at']);
        });
    }
};
