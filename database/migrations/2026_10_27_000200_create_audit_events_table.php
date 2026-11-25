<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 中央の監査ログ（追記専用、ハッシュ連鎖。ADR 0013）。運営のログイン・パスキーの増減・テナントへの立ち入りを残す。
 * テナント DB のドメインイベント（ADR 0012）と同じ作りで、鍵は APP_KEY から派生する。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_forbid_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% は追記専用です（% は禁止）', TG_TABLE_NAME, TG_OP;
            END;
            $$
            SQL);

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->bigInteger('sequence')->unique();
            $table->string('type', 64);                        // operator.login_succeeded など
            $table->ulid('operator_id')->nullable();           // 外部キーは付けない（記録は運営の行より長く残る）
            $table->string('tenant_id')->nullable();           // テナントに立ち入った操作のとき
            $table->string('ip', 45)->nullable();
            $table->jsonb('data');
            $table->timestampTz('occurred_at');
            $table->char('prev_hash', 64)->unique();
            $table->char('hash', 64)->unique();

            $table->index(['operator_id', 'sequence']);
            $table->index(['type', 'sequence']);
        });

        DB::statement('CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER audit_events_no_truncate BEFORE TRUNCATE ON audit_events FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::statement('DROP FUNCTION IF EXISTS sugar_forbid_mutation()');
    }
};
