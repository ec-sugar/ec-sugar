<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AI エージェントの鍵の「読むだけ／書ける」（ADR 0101）。前からある鍵は読むだけ（read）。
 * 書ける鍵（write）の期限は最長 90 日（作った時から。DB でも守る。1 分の余裕は、作った時刻と期限の時刻を別々に取る差）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE agent_tokens ADD COLUMN scope varchar(8) NOT NULL DEFAULT 'read'");
        DB::statement("ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_scope CHECK (scope IN ('read', 'write'))");
        DB::statement("ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_write_term CHECK (scope = 'read' OR expires_at <= created_at + interval '90 days 1 minute')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agent_tokens DROP CONSTRAINT IF EXISTS agent_tokens_write_term');
        DB::statement('ALTER TABLE agent_tokens DROP CONSTRAINT IF EXISTS agent_tokens_scope');
        DB::statement('ALTER TABLE agent_tokens DROP COLUMN IF EXISTS scope');
    }
};
