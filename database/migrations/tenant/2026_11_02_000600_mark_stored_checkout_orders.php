<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 払い方の列（000400）より前に作った、預けたカードで請求する注文（定期便の回）に印を付ける。stored の支払いの記録がある注文だけ
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE checkout_orders SET pays_with = 'stored' WHERE pays_with = 'checkout' AND transaction_id IN (SELECT transaction_id FROM payment_attempts WHERE kind = 'stored')");
    }

    public function down(): void {}
};
