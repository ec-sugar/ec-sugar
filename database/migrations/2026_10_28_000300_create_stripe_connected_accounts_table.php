<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 店の Stripe の連結アカウント（ADR 0022）。運営の 1 か所で受けた通知の account から店を引くための対応で、中央 DB に置く。
 * 1 つの店に 1 つ、1 つの連結アカウントは 1 つの店だけ（取り違えて別の店の注文に触れない）。個人情報でも秘密でもない
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_connected_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tenant_id')->unique();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('account_id', 255)->unique(); // acct_…
            $table->boolean('livemode');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_connected_accounts');
    }
};
