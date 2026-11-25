<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 「Stripe とつなぐ」（ADR 0022）。つないだ店は provider = stripe_connect の行を持つ（credentials は連結アカウントの ID だけ。鍵は運営のサーバーの秘密）。
 * connect_status は登録の状態（onboarding: 登録の途中 / active: 支払いを受けられる / restricted: 止められている）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table): void {
            $table->string('connect_status', 16)->nullable();
        });

        DB::statement('ALTER TABLE payment_gateways DROP CONSTRAINT payment_gateways_provider');
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_provider CHECK (provider IN ('stripe', 'stripe_connect'))");
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_connect_status CHECK ((provider = 'stripe_connect') = (connect_status IS NOT NULL) AND (connect_status IS NULL OR connect_status IN ('onboarding', 'active', 'restricted')))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_gateways DROP CONSTRAINT payment_gateways_connect_status');
        DB::statement("DELETE FROM payment_gateways WHERE provider = 'stripe_connect'");
        DB::statement('ALTER TABLE payment_gateways DROP CONSTRAINT payment_gateways_provider');
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_provider CHECK (provider IN ('stripe'))");

        Schema::table('payment_gateways', function (Blueprint $table): void {
            $table->dropColumn('connect_status');
        });
    }
};
