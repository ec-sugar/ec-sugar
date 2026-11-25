<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 店ごとの様子に、流し損ねた知らせの数を足す（ADR 0089）。届け直しを待つ記録（event_waiting）と、何度も落ちて止めた記録（event_stopped）の件数だけ。
 * どの注文か・どの受け手かは中央に置かない（店の中で見る）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE shop_digests ADD COLUMN event_waiting integer NOT NULL DEFAULT 0 CHECK (event_waiting >= 0)');
        DB::statement('ALTER TABLE shop_digests ADD COLUMN event_stopped integer NOT NULL DEFAULT 0 CHECK (event_stopped >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_digests DROP COLUMN event_waiting');
        DB::statement('ALTER TABLE shop_digests DROP COLUMN event_stopped');
    }
};
