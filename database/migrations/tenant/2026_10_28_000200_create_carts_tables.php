<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 店頭のカート（ADR 0021）。取引の前の下書きなので、記録の鎖のロックとイベントの外に置く（CartService だけが書く）。
 * お客さんとは推測できない合い言葉の Cookie で紐付け、ここには合い言葉の SHA-256 だけを置く。個人情報は入れない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->index();   // 最後の操作から 30 日
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_token_hash_hex CHECK (token_hash ~ '^[0-9a-f]{64}$')");

        Schema::create('cart_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->smallInteger('quantity');
            $table->bigInteger('added_unit_amount');      // 入れた時の価格（変わったら知らせるため。計算には使わない）
            $table->timestampsTz();

            $table->unique(['cart_id', 'listing_id']);
        });

        DB::statement('ALTER TABLE cart_lines ADD CONSTRAINT cart_lines_quantity_range CHECK (quantity BETWEEN 1 AND 10)');
        DB::statement('ALTER TABLE cart_lines ADD CONSTRAINT cart_lines_amount_nonnegative CHECK (added_unit_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_lines');
        Schema::dropIfExists('carts');
    }
};
