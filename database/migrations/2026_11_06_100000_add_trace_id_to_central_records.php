<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 処理を追う ID（trace_id。ADR 0087）を、中央の記録に添える列。どちらも空でよい。
 *
 * - audit_events.trace_id: 監査ログを書いた処理。ハッシュの本文には入れない（AuditLog）。追記だけのトリガーはそのまま効く
 * - incidents.last_trace_id: 最後に起きた時の処理（指紋ごとに 1 行で回数を数える表なので、最後の 1 つだけ）
 *
 * 値はこちらで作る ULID（小文字の 26 文字）だけ。形を DB でも確かめる
 */
return new class extends Migration
{
    private const string SHAPE = "'^[0-7][0-9a-hjkmnp-tv-z]{25}$'";

    public function up(): void
    {
        DB::statement('ALTER TABLE audit_events ADD COLUMN trace_id char(26) NULL');
        DB::statement('ALTER TABLE audit_events ADD CONSTRAINT audit_events_trace_id_shape CHECK (trace_id IS NULL OR trace_id ~ '.self::SHAPE.')');
        DB::statement('CREATE INDEX audit_events_trace_id ON audit_events (trace_id) WHERE trace_id IS NOT NULL');

        DB::statement('ALTER TABLE incidents ADD COLUMN last_trace_id char(26) NULL');
        DB::statement('ALTER TABLE incidents ADD CONSTRAINT incidents_last_trace_id_shape CHECK (last_trace_id IS NULL OR last_trace_id ~ '.self::SHAPE.')');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE incidents DROP COLUMN last_trace_id');
        DB::statement('ALTER TABLE audit_events DROP COLUMN trace_id');
    }
};
