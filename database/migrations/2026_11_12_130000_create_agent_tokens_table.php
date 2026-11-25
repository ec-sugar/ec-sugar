<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI エージェントの鍵（ADR 0100）。運営が自分の分を発行し、1 つの鍵は 1 人の運営 × 1 つの店 × 読むだけ。
 * 鍵そのものは持たず、SHA-256 のハッシュと見分けるための頭の 8 文字だけ。段階は持たない（毎回 OperatorAccess で引く）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->string('tenant_id', 63);
            $table->string('name', 40);
            $table->char('token_hash', 64)->unique();
            $table->string('prefix', 18);
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['operator_id', 'tenant_id', 'revoked_at']);
            $table->index(['tenant_id', 'revoked_at']);
        });
        DB::statement("ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_hash CHECK (token_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_tenant CHECK (tenant_id ~ '^[a-z0-9][a-z0-9_-]{0,62}$')");
        DB::statement("ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_name CHECK (btrim(name) <> '' AND name !~ '[\\x01-\\x1f\\x7f-\\x9f]')");
        DB::statement("ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_prefix CHECK (prefix ~ '^ecs_agent_[A-Za-z0-9_-]{8}$')");
        DB::statement('ALTER TABLE agent_tokens ADD CONSTRAINT agent_tokens_expiry CHECK (expires_at > created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tokens');
    }
};
