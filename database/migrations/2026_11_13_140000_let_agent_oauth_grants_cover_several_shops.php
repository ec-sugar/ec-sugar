<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI エージェントの OAuth の許可で、店を複数選べるようにする（ADR 0104。0101 の「1 人 × 1 店 × 1 クライアント」を置き換える）。
 *
 * - agent_oauth_grant_shops: 許可の系統が読める・変えられる店（許可の画面で選んだ店。1〜50）。系統の店は許可した時に決まり、後から増えない
 * - agent_oauth_grants.tenant_id は無くす（店は上の表に）
 * - agent_oauth_requests.tenant_id を tenant_ids（選んだ店の配列）に替える
 * - OAuth で出したアクセストークン（agent_tokens の行）は店を持たない（どの店かは呼び出しごとに、系統の店の中から選ぶ）。
 *   管理画面で作る鍵は今までどおり 1 店（ちょうど片方だけを持つ）
 */
return new class extends Migration
{
    public function up(): void
    {
        // 公開の前の例外（ADR 0006）: 系統と頼みの店を 1 つの列から子の表・配列へ移した（ADR 0104）。前のバージョンへ戻すと列が無く、
        // 戻している間は OAuth のつなぎ（/mcp の OAuth の鍵）だけが使えない（管理画面で作る鍵・管理画面・ショップは動く）。公開の版からは足す → 次の版で消す
        Schema::create('agent_oauth_grant_shops', function (Blueprint $table): void {
            $table->foreignUlid('grant_id')->constrained('agent_oauth_grants')->cascadeOnDelete();
            $table->string('tenant_id', 63);
            $table->primary(['grant_id', 'tenant_id']);
            $table->index('tenant_id');
        });
        DB::statement("ALTER TABLE agent_oauth_grant_shops ADD CONSTRAINT agent_oauth_grant_shops_tenant CHECK (tenant_id ~ '^[a-z0-9][a-z0-9_-]{0,62}$')");
        DB::statement('INSERT INTO agent_oauth_grant_shops (grant_id, tenant_id) SELECT id, tenant_id FROM agent_oauth_grants');

        DB::statement('ALTER TABLE agent_oauth_grants DROP CONSTRAINT IF EXISTS agent_oauth_grants_tenant');
        Schema::table('agent_oauth_grants', function (Blueprint $table): void {
            $table->dropIndex(['operator_id', 'tenant_id', 'revoked_at']);
            $table->dropColumn('tenant_id');
            $table->index(['operator_id', 'revoked_at']);
        });

        Schema::table('agent_oauth_requests', function (Blueprint $table): void {
            $table->jsonb('tenant_ids')->nullable();
        });
        DB::statement('UPDATE agent_oauth_requests SET tenant_ids = jsonb_build_array(tenant_id) WHERE tenant_id IS NOT NULL');
        DB::statement('ALTER TABLE agent_oauth_requests DROP CONSTRAINT agent_oauth_requests_decision');
        Schema::table('agent_oauth_requests', function (Blueprint $table): void {
            $table->dropColumn('tenant_id');
        });
        DB::statement("ALTER TABLE agent_oauth_requests ADD CONSTRAINT agent_oauth_requests_shops CHECK (tenant_ids IS NULL OR (jsonb_typeof(tenant_ids) = 'array' AND jsonb_array_length(tenant_ids) BETWEEN 1 AND 50))");
        DB::statement('ALTER TABLE agent_oauth_requests ADD CONSTRAINT agent_oauth_requests_decision CHECK ('
            .'(decided_at IS NULL AND approved IS NULL AND code_hash IS NULL AND operator_id IS NULL AND tenant_ids IS NULL) '
            .'OR (decided_at IS NOT NULL AND approved = false AND code_hash IS NULL AND tenant_ids IS NULL) '
            ."OR (decided_at IS NOT NULL AND approved = true AND code_hash IS NOT NULL AND operator_id IS NOT NULL AND tenant_ids IS NOT NULL AND scope IS NOT NULL AND code_expires_at <= decided_at + interval '11 minutes'))");

        DB::statement('ALTER TABLE agent_tokens ALTER COLUMN tenant_id DROP NOT NULL');
        DB::statement('UPDATE agent_tokens SET tenant_id = NULL WHERE oauth_grant_id IS NOT NULL');
        DB::statement('ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_shop CHECK ((oauth_grant_id IS NULL) = (tenant_id IS NOT NULL))');
    }

    public function down(): void
    {
        // 戻すときは、系統のいちばん小さい店の ID だけを残す（複数の店の系統は 1 店に縮む）
        DB::statement('ALTER TABLE agent_tokens DROP CONSTRAINT IF EXISTS agent_tokens_shop');
        DB::statement('UPDATE agent_tokens t SET tenant_id = (SELECT min(s.tenant_id) FROM agent_oauth_grant_shops s WHERE s.grant_id = t.oauth_grant_id) WHERE t.oauth_grant_id IS NOT NULL');
        DB::statement('DELETE FROM agent_tokens WHERE tenant_id IS NULL');
        DB::statement('ALTER TABLE agent_tokens ALTER COLUMN tenant_id SET NOT NULL');

        DB::statement('ALTER TABLE agent_oauth_requests DROP CONSTRAINT IF EXISTS agent_oauth_requests_decision');
        DB::statement('ALTER TABLE agent_oauth_requests DROP CONSTRAINT IF EXISTS agent_oauth_requests_shops');
        Schema::table('agent_oauth_requests', function (Blueprint $table): void {
            $table->string('tenant_id', 63)->nullable();
        });
        DB::statement('UPDATE agent_oauth_requests SET tenant_id = tenant_ids->>0 WHERE tenant_ids IS NOT NULL');
        Schema::table('agent_oauth_requests', function (Blueprint $table): void {
            $table->dropColumn('tenant_ids');
        });
        DB::statement('ALTER TABLE agent_oauth_requests ADD CONSTRAINT agent_oauth_requests_decision CHECK ('
            .'(decided_at IS NULL AND approved IS NULL AND code_hash IS NULL AND operator_id IS NULL) '
            .'OR (decided_at IS NOT NULL AND approved = false AND code_hash IS NULL) '
            ."OR (decided_at IS NOT NULL AND approved = true AND code_hash IS NOT NULL AND operator_id IS NOT NULL AND tenant_id IS NOT NULL AND scope IS NOT NULL AND code_expires_at <= decided_at + interval '11 minutes'))");

        Schema::table('agent_oauth_grants', function (Blueprint $table): void {
            $table->dropIndex(['operator_id', 'revoked_at']);
            $table->string('tenant_id', 63)->nullable();
        });
        DB::statement('UPDATE agent_oauth_grants g SET tenant_id = (SELECT min(s.tenant_id) FROM agent_oauth_grant_shops s WHERE s.grant_id = g.id)');
        DB::statement('DELETE FROM agent_oauth_grants WHERE tenant_id IS NULL');
        DB::statement('ALTER TABLE agent_oauth_grants ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement("ALTER TABLE agent_oauth_grants ADD CONSTRAINT agent_oauth_grants_tenant CHECK (tenant_id ~ '^[a-z0-9][a-z0-9_-]{0,62}$')");
        Schema::table('agent_oauth_grants', function (Blueprint $table): void {
            $table->index(['operator_id', 'tenant_id', 'revoked_at']);
        });
        Schema::dropIfExists('agent_oauth_grant_shops');
    }
};
