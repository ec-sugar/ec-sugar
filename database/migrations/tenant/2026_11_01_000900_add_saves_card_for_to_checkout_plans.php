<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支払いの画面の予約にも「カードを預けるお客さん」を残す（見直し 2026-11-01 の中 5）。
 * 預ける画面とふつうの画面を混ぜて使い回さない（同じ冪等キーで中身が違う頼みを作らない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_plans', function (Blueprint $table): void {
            $table->foreignUlid('saves_card_for')->nullable()->constrained('customer_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('saves_card_for');
        });
    }
};
