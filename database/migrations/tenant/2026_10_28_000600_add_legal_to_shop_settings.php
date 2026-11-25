<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特定商取引法に基づく表記。個人の店では所在地や電話が店主の個人情報になるので、テナント鍵で暗号化して置く（ADR 0010）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->text('legal')->nullable(); // 暗号化 JSON（list<{label, value}>）
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn('legal');
        });
    }
};
