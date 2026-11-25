<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AI エージェントの案の中身の指紋（ADR 0101 の作って決めたこと、見直しの低 7）。道具と形をそろえた引数の HMAC（店の鍵。引数は暗号化しているので、
 * 平文のハッシュにすると送り状の番号などを当て推量で引けてしまう）。同じ中身の待ちの案を 2 つ作らない（待ちの間だけの一意の索引）。
 * 前からの行は空のまま（待ちの重なりの確かめに入らないだけ）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE agent_proposals ADD COLUMN fingerprint char(64)');
        DB::statement("ALTER TABLE agent_proposals ADD CONSTRAINT agent_proposals_fingerprint CHECK (fingerprint IS NULL OR fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("CREATE UNIQUE INDEX agent_proposals_pending_fingerprint ON agent_proposals (fingerprint) WHERE status = 'pending' AND fingerprint IS NOT NULL");
        DB::statement("CREATE INDEX agent_proposals_pending_token ON agent_proposals (token_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS agent_proposals_pending_token');
        DB::statement('DROP INDEX IF EXISTS agent_proposals_pending_fingerprint');
        DB::statement('ALTER TABLE agent_proposals DROP CONSTRAINT IF EXISTS agent_proposals_fingerprint');
        DB::statement('ALTER TABLE agent_proposals DROP COLUMN IF EXISTS fingerprint');
    }
};
