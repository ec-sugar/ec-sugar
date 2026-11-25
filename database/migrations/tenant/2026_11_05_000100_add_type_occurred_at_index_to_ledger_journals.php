<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 売上の集計（ADR 0060）は、台帳の支払いと返金の仕訳を「お金が動いた時刻」の範囲で数える。
 * 今の索引（取引 × 種別）では範囲で引けず、表を全部なめるので、種別 × 時刻の索引を足す（追記専用の表でも索引は足せる）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_journals', function (Blueprint $table): void {
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_journals', function (Blueprint $table): void {
            $table->dropIndex(['type', 'occurred_at']);
        });
    }
};
