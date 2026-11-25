<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 返金を作った時刻で引く索引（ADR 0103）。運営の数を集める回（10 分ごと）が、この 1 時間ほどの返金の山を数えるため
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS refunds_created_at ON refunds (created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS refunds_created_at');
    }
};
