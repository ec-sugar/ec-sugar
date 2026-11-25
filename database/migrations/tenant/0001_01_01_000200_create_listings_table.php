<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 出品物。種類（kind）はプラグインが増やせるので文字列。金額は最小通貨単位の整数。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->string('kind', 32);                        // product | (プラグインが追加)
            $table->string('offer_side', 4);                   // sell | buy
            $table->string('pricing', 16);                     // fixed | quoted | recurring
            $table->string('status', 16)->default('draft');    // draft | active | archived
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->bigInteger('unit_amount')->nullable();     // 最小通貨単位。quoted のときは null
            $table->char('currency', 3)->default('JPY');
            $table->string('sku', 64)->nullable();
            $table->jsonb('attributes_data')->nullable();      // 種類ごとの追加属性
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['seller_id', 'status']);
            $table->index(['seller_id', 'kind']);
            $table->unique(['seller_id', 'sku']);               // Postgres は NULL 同士を重複とみなさない
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
