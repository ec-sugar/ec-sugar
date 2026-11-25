<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 監査ログを「種類 × 時刻」で引く索引（ADR 0103）。おかしな動きの知らせが毎分、決まった種類のこの 1 時間だけを数えるため。
 * 監査ログは管理画面の要求ごとに増える表なので、書き込みを止めない CONCURRENTLY で作る（トランザクションの外でしか作れない）
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS audit_events_type_occurred_at ON audit_events (type, occurred_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS audit_events_type_occurred_at');
    }
};
