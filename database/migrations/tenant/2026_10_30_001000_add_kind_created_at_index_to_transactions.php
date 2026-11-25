<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 物販の注文を受けた時刻の範囲で数える問い合わせ（ホームの数 ShopHome と、運営の様子の集め直し ShopDigests。ADR 0042）を速くする索引
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'created_at']);
        });
    }
};
