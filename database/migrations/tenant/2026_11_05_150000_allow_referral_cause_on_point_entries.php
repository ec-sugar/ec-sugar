<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ポイントの帳簿の決まった理由に、App「友だち紹介」が付けた分（adjust、cause = referral）を足す（ADR 0075）。
 * 理由の一覧は PointService::GRANT_CAUSES と同じ。店の人の手の足し引き（adjust、cause は空）はそのまま。
 * 返品・一部返金の理由（revoke・release の partial。ADR 0048、2026_11_05_051000）は前の移行のまま残す
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE point_entries DROP CONSTRAINT point_entries_cause_known');
        DB::statement("ALTER TABLE point_entries ADD CONSTRAINT point_entries_cause_known CHECK (cause IS NULL OR (kind = 'expire' AND cause IN ('inactive', 'left')) OR (kind = 'revoke' AND cause IN ('refund', 'partial')) OR (kind = 'release' AND cause IN ('shipping', 'partial')) OR (kind = 'adjust' AND cause = 'referral'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE point_entries DROP CONSTRAINT point_entries_cause_known');
        DB::statement("ALTER TABLE point_entries ADD CONSTRAINT point_entries_cause_known CHECK (cause IS NULL OR (kind = 'expire' AND cause IN ('inactive', 'left')) OR (kind = 'revoke' AND cause IN ('refund', 'partial')) OR (kind = 'release' AND cause IN ('shipping', 'partial')))");
    }
};
