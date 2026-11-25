<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 店頭の注文の確定。注文の合い言葉の指紋 → 取引。二重送信で注文が 2 つにならないようにする（一意）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('key_hash', 64)->unique();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE checkout_orders ADD CONSTRAINT checkout_orders_key_hash_hex CHECK (key_hash ~ '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_orders');
    }
};
