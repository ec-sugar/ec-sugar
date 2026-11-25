<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 返品・一部返金の理由に resale（チケットのリセールで売れた分を、手数料を引いて元の人へ返す。App「デジタルチケット」が付ける。ADR 0069）を足す。
 * 店の人は選ばない（ReturnReason::choosable の外）。ほかの理由は前の移行（2026_11_05_051000）のまま
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE order_returns DROP CONSTRAINT order_returns_reason');
        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_reason CHECK (reason IN ('returned', 'defective', 'wrong_item', 'goodwill', 'price_adjustment', 'external', 'other', 'resale'))");
    }

    public function down(): void
    {
        // リセールの返金の記録が残っていれば戻さない（追記だけの表なので消せない）
        if (DB::table('order_returns')->where('reason', 'resale')->exists()) {
            throw new RuntimeException('リセールの返金の記録があるので戻せません');
        }

        DB::statement('ALTER TABLE order_returns DROP CONSTRAINT order_returns_reason');
        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_reason CHECK (reason IN ('returned', 'defective', 'wrong_item', 'goodwill', 'price_adjustment', 'external', 'other'))");
    }
};
