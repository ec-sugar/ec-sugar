<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 受渡（CONCEPT 5.7）。1 取引に複数（分納・返送）。配送先は個人情報なので暗号化 JSON。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->string('method', 16);                      // shipping | pickup | digital | in_person | inbound | none
            $table->string('status', 16)->default('pending');  // pending | shipped | completed | cancelled
            $table->foreignUlid('location_id')->nullable()->constrained('locations')->restrictOnDelete(); // 出す拠点 / 受ける拠点
            $table->text('address')->nullable();               // 暗号化 JSON（宛名・住所・電話）
            $table->string('carrier', 32)->nullable();
            $table->string('tracking_number', 64)->nullable(); // 最初は手入力（送り状連携は後）
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['transaction_id', 'status']);
        });

        Schema::create('fulfillment_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('fulfillment_id')->constrained('fulfillments')->restrictOnDelete();
            $table->foreignUlid('transaction_line_id')->constrained('transaction_lines')->restrictOnDelete();
            $table->integer('quantity');
            $table->timestampsTz();

            $table->unique(['fulfillment_id', 'transaction_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_items');
        Schema::dropIfExists('fulfillments');
    }
};
