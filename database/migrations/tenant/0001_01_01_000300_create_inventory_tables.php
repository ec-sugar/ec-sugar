<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 在庫。数量管理（inventory_stocks）と個体管理（inventory_units）の両方を持つ（CONCEPT 5.6）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reserved')->default(0);
            $table->timestampsTz();

            $table->unique(['listing_id', 'location_id']);
        });

        Schema::create('inventory_units', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->nullable()->constrained('listings')->nullOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('identifier', 120)->nullable();     // シリアル番号など
            $table->string('condition', 16)->nullable();       // 状態ランク（意味は縦のプラグインが決める）
            $table->string('status', 16)->default('received'); // UnitStatus
            $table->ulid('acquired_transaction_id')->nullable(); // 入手元の取引。外部キーは取引の表と同時に付ける
            $table->jsonb('attributes_data')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['listing_id', 'status']);
            $table->index(['location_id', 'status']);
            $table->index('acquired_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_units');
        Schema::dropIfExists('inventory_stocks');
    }
};
