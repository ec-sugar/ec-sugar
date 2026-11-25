<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 運営アカウント（中央 DB、ADR 0013）。パスワードの列は持たない。ログインはパスキーだけ。
 * 最初のパスキーは CLI が発行する登録トークンで登録する（トークンは SHA-256 だけ保存、1 回限り）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('email', 254)->unique();            // 小文字に正規化して保存
            $table->timestampTz('disabled_at')->nullable();    // 消さずに止める（監査ログが指し続けるため）
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE operators ADD CONSTRAINT operators_email_lowercase CHECK (email = lower(email))');

        // laravel/passkeys の表と同じ形（列名 user_id はパッケージの前提）。主キーだけ ULID
        Schema::create('operator_passkeys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('operators')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('credential_id', 1024)->unique();
            $table->jsonb('credential');                       // 公開鍵と署名カウンタ（秘密は含まない）
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->index('user_id');
        });

        Schema::create('operator_enrollments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->constrained('operators')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();          // SHA-256。平文のトークンは保存しない
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');

            $table->index(['operator_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_enrollments');
        Schema::dropIfExists('operator_passkeys');
        Schema::dropIfExists('operators');
    }
};
