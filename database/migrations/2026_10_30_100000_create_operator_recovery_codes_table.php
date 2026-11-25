<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 運営の回復用のコード（ADR 0038）。保存は鍵つきのハッシュだけ（平文は作った時に 1 度だけ見せる）。1 つは 1 回だけ使える
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_recovery_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['operator_id', 'used_at']);
        });
        DB::statement("ALTER TABLE operator_recovery_codes ADD CONSTRAINT operator_recovery_codes_hash CHECK (code_hash ~ '^[0-9a-f]{64}$')");

        // 回復用のコードで戻った時。そこから 24 時間は、回復の前からあるパスキーを消せず、コードも作り直せない（盗んだコードで本人を締め出させない）
        Schema::table('operators', function (Blueprint $table): void {
            $table->timestampTz('recovered_at')->nullable();
        });
        // 回復した時にあったパスキーに付ける「この時まで消せない」の印（時刻の比べではなく、その時にあったものへ直に付ける）
        Schema::table('operator_passkeys', function (Blueprint $table): void {
            $table->timestampTz('protected_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operator_passkeys', function (Blueprint $table): void {
            $table->dropColumn('protected_until');
        });
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('recovered_at');
        });
        Schema::dropIfExists('operator_recovery_codes');
    }
};
