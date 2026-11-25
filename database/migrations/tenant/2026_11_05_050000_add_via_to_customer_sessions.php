<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会員の印に「どの入り方で入ったか」を残す（ADR 0070 の作って決めたこと の見直し 軽 5）。
 * code（メールのコード）・passkey（パスキー）・App の名前（LINE でログインなら line）。誰の・どの端末かは入れない（入り方の種類だけ）。
 * 前からある印は空のまま（入り方が分からない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_sessions', function (Blueprint $table): void {
            $table->string('via', 32)->nullable();
        });

        DB::statement("ALTER TABLE customer_sessions ADD CONSTRAINT customer_sessions_via_check CHECK (via IS NULL OR via ~ '^[a-z][a-z0-9-]{0,31}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customer_sessions DROP CONSTRAINT IF EXISTS customer_sessions_via_check');

        Schema::table('customer_sessions', function (Blueprint $table): void {
            $table->dropColumn('via');
        });
    }
};
