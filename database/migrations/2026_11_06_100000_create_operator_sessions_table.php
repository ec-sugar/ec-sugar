<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 運営のログイン中のセッション（ADR 0083）。ログインごとに 1 行。セッションには行の ID だけを置き、
 * 毎リクエストでこの行が生きているかを確かめる（パスキーを消したら、そのパスキーで入ったセッションも切る・ほかの端末を全部ログアウト）。
 * 接続元の住所は置かない（監査ログにある）。端末はブラウザと OS の種類だけ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->constrained('operators')->cascadeOnDelete();
            // 入ったパスキー（パスキーを消しても行は残すので外部キーにしない）
            $table->ulid('passkey_id')->nullable();
            $table->string('device', 40);
            // いつもと違う接続元からのログイン（本人へメールで知らせた。ホームにも出す）
            $table->boolean('unusual')->default(false);
            $table->timestampTz('created_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->index(['operator_id', 'ended_at']);
            $table->index(['passkey_id', 'ended_at']);
        });
        DB::statement("ALTER TABLE operator_sessions ADD CONSTRAINT operator_sessions_end CHECK (
            (ended_at IS NULL AND end_reason IS NULL)
            OR (ended_at IS NOT NULL AND end_reason IN ('logout', 'ended_by_self', 'others', 'passkey_removed', 'disabled', 'expired'))
        )");
        DB::statement("ALTER TABLE operator_sessions ADD CONSTRAINT operator_sessions_device CHECK (device ~ '^[A-Za-z0-9 ./-]{1,40}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_sessions');
    }
};
