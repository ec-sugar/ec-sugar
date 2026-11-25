<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 決済代行の鍵（ADR 0019）。店ごと（テナント DB）に 1 つの代行につき 1 行。鍵はテナント鍵で暗号化して置く
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider', 16)->unique();  // stripe
            $table->text('credentials');                // 暗号化 JSON（{secret_key, webhook_secret}）
            $table->string('mode', 8);                  // test | live（鍵の頭から）
            $table->char('key_hint', 4);                // 鍵の末尾 4 文字（Stripe の画面でも見せている部分）
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_provider CHECK (provider IN ('stripe'))");
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_mode CHECK (mode IN ('test', 'live'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
