<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 店頭の注文の記録は、取引ごとに 1 つ。一意の索引で 1 対 1 を DB でも守り、取引から引く読み（期限切れの見回り・ご注文の確認）を速くする。
 * 足すだけなので、古いイメージでもそのまま動く
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX checkout_orders_transaction_id_unique ON checkout_orders (transaction_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX checkout_orders_transaction_id_unique');
    }
};
