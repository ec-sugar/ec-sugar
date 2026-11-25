<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ドメインイベント（追記専用、ハッシュ連鎖）。監査・帳票・通知・AI 評価データの源泉（ADR 0012）。
 *
 * 追記専用はトリガーで強制する（UPDATE / DELETE / TRUNCATE は例外）。
 * 所有者ならトリガーを外せるので、改竄の検知はハッシュ連鎖（テナント鍵の HMAC）が受け持つ。
 * sugar_forbid_mutation() は Ledger の表でも使う。
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

        Schema::create('domain_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->bigInteger('sequence')->unique();          // 1 から連番。連鎖の順序
            $table->string('type', 64);                        // transaction.phase_changed など
            $table->string('aggregate_type', 32);              // transaction | fulfillment | ...
            $table->ulid('aggregate_id');
            $table->jsonb('payload');                          // 平文の個人情報を入れない（ADR 0010）
            $table->string('actor', 64)->nullable();           // system | party:<ulid> など
            $table->timestampTz('occurred_at');
            $table->char('prev_hash', 64)->unique();           // 分岐（同じ親を持つ 2 件）を DB で拒否
            $table->char('hash', 64)->unique();

            $table->index(['aggregate_type', 'aggregate_id', 'sequence']);
            $table->index(['type', 'sequence']);
        });

        DB::statement('CREATE TRIGGER domain_events_append_only BEFORE UPDATE OR DELETE ON domain_events FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER domain_events_no_truncate BEFORE TRUNCATE ON domain_events FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
        DB::statement('DROP FUNCTION IF EXISTS sugar_forbid_mutation()');
    }
};
