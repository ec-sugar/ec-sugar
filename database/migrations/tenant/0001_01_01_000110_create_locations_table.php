<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->string('kind', 16);                // warehouse | store | virtual
            $table->string('name', 120);
            $table->jsonb('address')->nullable();      // 拠点の所在地（個人情報ではない）
            $table->timestampsTz();

            $table->index(['seller_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
