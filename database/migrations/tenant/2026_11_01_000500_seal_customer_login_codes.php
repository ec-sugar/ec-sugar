<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会員のログインのコードを送る仕事に、宛先とコードを載せない（見直し 2026-11-01、ADR 0010）。
 * 送る間だけ、宛先とコードを店の鍵で暗号化して行に置き、送ったら消す。仕事（キュー・失敗の記録）には店とコードの行の ID だけ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_login_codes', function (Blueprint $table): void {
            $table->text('email')->nullable();        // 暗号化。送ったら消す
            $table->text('sealed_code')->nullable();  // 暗号化。送ったら消す
        });
    }

    public function down(): void
    {
        Schema::table('customer_login_codes', function (Blueprint $table): void {
            $table->dropColumn(['email', 'sealed_code']);
        });
    }
};
