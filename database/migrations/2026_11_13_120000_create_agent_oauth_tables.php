<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI エージェントの OAuth 2.1（ADR 0101 の要点の 5）。claude.ai などの「コネクタを追加」から、運営が許可してつなぐ。
 *
 * - agent_oauth_clients: 動的なクライアント登録（RFC 7591）で登録された公開のクライアント（秘密を持たない）。名前は名乗っただけで信じない
 * - agent_oauth_requests: 許可の頼み（10 分）。許可したら認可コード（ハッシュだけ・10 分・1 回だけ）を同じ行に持つ
 * - agent_oauth_grants: 許可の系統（1 人の運営 × 1 つの店 × 1 つのクライアント）。取り消すと、その系統のアクセストークンもリフレッシュトークンも効かない。
 *   絶対の期限（書ける 90 日・読むだけ 365 日）を持つ
 * - agent_oauth_refresh_tokens: リフレッシュトークン（ハッシュだけ・30 日・使うたびに入れ替え。使った印のあるものがまた来たら系統ごと取り消す）
 * - アクセストークンは今の AI エージェントの鍵の表（agent_tokens）の行で、OAuth で作った印（系統とクライアント）を持つ。期限は 1 時間
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_oauth_clients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->jsonb('redirect_uris');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
            $table->index('created_at');
        });
        DB::statement("ALTER TABLE agent_oauth_clients ADD CONSTRAINT agent_oauth_clients_name CHECK (btrim(name) <> '' AND name !~ '[\\x01-\\x1f\\x7f-\\x9f]')");
        DB::statement("ALTER TABLE agent_oauth_clients ADD CONSTRAINT agent_oauth_clients_uris CHECK (jsonb_typeof(redirect_uris) = 'array' AND jsonb_array_length(redirect_uris) BETWEEN 1 AND 5)");

        Schema::create('agent_oauth_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->constrained('agent_oauth_clients')->restrictOnDelete();
            $table->foreignUlid('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->string('tenant_id', 63);
            $table->string('scope', 8);
            $table->string('client_name', 40);
            $table->string('redirect_host', 255);
            // 系統の絶対の期限（書ける 90 日・読むだけ 365 日。管理画面で作る鍵の最長と同じ。過ぎたら入れ替えを断る）
            $table->timestampTz('expires_at');
            $table->timestampTz('last_refreshed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable();
            $table->timestampsTz();
            $table->index(['operator_id', 'tenant_id', 'revoked_at']);
        });
        DB::statement("ALTER TABLE agent_oauth_grants ADD CONSTRAINT agent_oauth_grants_scope CHECK (scope IN ('read', 'write'))");
        DB::statement("ALTER TABLE agent_oauth_grants ADD CONSTRAINT agent_oauth_grants_tenant CHECK (tenant_id ~ '^[a-z0-9][a-z0-9_-]{0,62}$')");
        DB::statement("ALTER TABLE agent_oauth_grants ADD CONSTRAINT agent_oauth_grants_name CHECK (btrim(client_name) <> '' AND client_name !~ '[\\x01-\\x1f\\x7f-\\x9f]')");
        DB::statement('ALTER TABLE agent_oauth_grants ADD CONSTRAINT agent_oauth_grants_revoked CHECK ((revoked_at IS NULL) = (revoked_reason IS NULL))');
        // ＋1 分は、作った時刻と期限の時刻を別々に取る差
        DB::statement("ALTER TABLE agent_oauth_grants ADD CONSTRAINT agent_oauth_grants_term CHECK (expires_at > created_at AND expires_at <= created_at + CASE WHEN scope = 'write' THEN interval '90 days 1 minute' ELSE interval '365 days 1 minute' END)");

        Schema::create('agent_oauth_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('handle_hash', 64)->unique();
            $table->foreignUlid('client_id')->constrained('agent_oauth_clients')->cascadeOnDelete();
            $table->string('redirect_uri', 512);
            $table->string('state', 512)->nullable();
            $table->char('code_challenge', 43);
            $table->string('requested_scope', 8);
            $table->timestampTz('expires_at');
            // 決めた（許可か拒否）。許可なら運営・店・種類と、認可コードのハッシュ・期限
            $table->timestampTz('decided_at')->nullable();
            $table->boolean('approved')->nullable();
            $table->foreignUlid('operator_id')->nullable()->constrained('operators')->cascadeOnDelete();
            $table->string('tenant_id', 63)->nullable();
            $table->string('scope', 8)->nullable();
            $table->char('code_hash', 64)->nullable()->unique();
            $table->timestampTz('code_expires_at')->nullable();
            // コードを使った（1 回だけ）。使って作った許可の系統（コードがまた来たら、この系統を取り消す）
            $table->timestampTz('used_at')->nullable();
            $table->foreignUlid('grant_id')->nullable()->constrained('agent_oauth_grants')->nullOnDelete();
            $table->timestampsTz();
            $table->index('expires_at');
        });
        DB::statement("ALTER TABLE agent_oauth_requests ADD CONSTRAINT agent_oauth_requests_challenge CHECK (code_challenge ~ '^[A-Za-z0-9_-]{43}$')");
        DB::statement("ALTER TABLE agent_oauth_requests ADD CONSTRAINT agent_oauth_requests_scopes CHECK (requested_scope IN ('read', 'write') AND (scope IS NULL OR scope IN ('read', 'write')))");
        // 許可したものだけがコードを持ち、コードは 10 分（＋時刻の取り方の差の 1 分）まで
        DB::statement('ALTER TABLE agent_oauth_requests ADD CONSTRAINT agent_oauth_requests_decision CHECK ('
            .'(decided_at IS NULL AND approved IS NULL AND code_hash IS NULL AND operator_id IS NULL) '
            .'OR (decided_at IS NOT NULL AND approved = false AND code_hash IS NULL) '
            ."OR (decided_at IS NOT NULL AND approved = true AND code_hash IS NOT NULL AND operator_id IS NOT NULL AND tenant_id IS NOT NULL AND scope IS NOT NULL AND code_expires_at <= decided_at + interval '11 minutes'))");
        DB::statement('ALTER TABLE agent_oauth_requests ADD CONSTRAINT agent_oauth_requests_used CHECK (used_at IS NULL OR approved = true)');

        Schema::create('agent_oauth_refresh_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('grant_id')->constrained('agent_oauth_grants')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');
            $table->index('grant_id');
        });
        DB::statement("ALTER TABLE agent_oauth_refresh_tokens ADD CONSTRAINT agent_oauth_refresh_tokens_hash CHECK (token_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE agent_oauth_refresh_tokens ADD CONSTRAINT agent_oauth_refresh_tokens_term CHECK (expires_at > created_at AND expires_at <= created_at + interval '30 days 1 minute')");

        // アクセストークンは鍵の表の行。OAuth で作った行は系統とクライアントを持ち、期限は 1 時間（＋時刻の取り方の差の 1 分）まで
        Schema::table('agent_tokens', function (Blueprint $table): void {
            $table->foreignUlid('oauth_grant_id')->nullable()->constrained('agent_oauth_grants')->cascadeOnDelete();
            $table->string('oauth_client_id', 26)->nullable();
            $table->index('oauth_grant_id');
        });
        DB::statement('ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_oauth CHECK ((oauth_grant_id IS NULL) = (oauth_client_id IS NULL))');
        DB::statement("ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_oauth_term CHECK (oauth_grant_id IS NULL OR expires_at <= created_at + interval '1 hour 1 minute')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agent_tokens DROP CONSTRAINT IF EXISTS agent_tokens_oauth_term');
        DB::statement('ALTER TABLE agent_tokens DROP CONSTRAINT IF EXISTS agent_tokens_oauth');
        Schema::table('agent_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('oauth_grant_id');
            $table->dropColumn('oauth_client_id');
        });
        Schema::dropIfExists('agent_oauth_refresh_tokens');
        Schema::dropIfExists('agent_oauth_requests');
        Schema::dropIfExists('agent_oauth_grants');
        Schema::dropIfExists('agent_oauth_clients');
    }
};
