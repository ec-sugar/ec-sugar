<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 返金の記録に「止めて人が見る」（held）を足す。決済代行の言う返金の額が頼んだ額と違ったときの状態。
 * 途中の返金と同じく、取引ごと・返金が要る支払いごとに 1 つだけ（新しい返金を作らない）。終わった状態ではない（settled_at は空）。
 * 決済代行の返金の ID は残す（人が確かめるため）。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_status');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status CHECK (status IN ('requested', 'pending', 'held', 'succeeded', 'failed', 'reversed') AND (status IN ('succeeded', 'failed', 'reversed')) = (settled_at IS NOT NULL))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_held_reason CHECK (status <> 'held' OR COALESCE(failure_reason, '') = 'amount_mismatch')");
        DB::statement('DROP INDEX refunds_one_active_cancel');
        DB::statement('DROP INDEX refunds_one_active_stray');
        DB::statement("CREATE UNIQUE INDEX refunds_one_active_cancel ON refunds (transaction_id) WHERE purpose = 'cancel' AND status IN ('requested', 'pending', 'held')");
        DB::statement("CREATE UNIQUE INDEX refunds_one_active_stray ON refunds (stray_payment_id) WHERE purpose = 'stray' AND status IN ('requested', 'pending', 'held')");
    }

    public function down(): void
    {
        // 止めている返金があれば戻さない（途中に戻すと、額の違いの印が消え、確かめ直しが自動で進めてしまう）
        if (DB::table('refunds')->where('status', 'held')->exists()) {
            throw new RuntimeException('額が違って止めている返金があるので戻せません。Stripe で確かめて片付けてから戻してください。');
        }

        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_held_reason');
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_status');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status CHECK (status IN ('requested', 'pending', 'succeeded', 'failed', 'reversed') AND (status IN ('succeeded', 'failed', 'reversed')) = (settled_at IS NOT NULL))");
        DB::statement('DROP INDEX refunds_one_active_cancel');
        DB::statement('DROP INDEX refunds_one_active_stray');
        DB::statement("CREATE UNIQUE INDEX refunds_one_active_cancel ON refunds (transaction_id) WHERE purpose = 'cancel' AND status IN ('requested', 'pending')");
        DB::statement("CREATE UNIQUE INDEX refunds_one_active_stray ON refunds (stray_payment_id) WHERE purpose = 'stray' AND status IN ('requested', 'pending')");
    }
};
