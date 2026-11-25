<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 壊れたことの記録（ADR 0043）に、記録の受け手（注文の知らせ・App）が落ちた・止めたの種類を足す（ADR 0089）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE incidents DROP CONSTRAINT incidents_kind');
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_kind CHECK (kind IN ('http_error', 'job_failed', 'command_failed', 'event_failed'))");
    }

    public function down(): void
    {
        DB::table('incidents')->where('kind', 'event_failed')->delete();
        DB::statement('ALTER TABLE incidents DROP CONSTRAINT incidents_kind');
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_kind CHECK (kind IN ('http_error', 'job_failed', 'command_failed'))");
    }
};
