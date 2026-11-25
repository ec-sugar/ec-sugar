<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 公開の前に試すテスト用の鍵（ADR 0096）。
 * - 決済代行の鍵の表に、ふだんの鍵と別のテスト用の鍵の行（provider = stripe_test。モードは test だけ）
 * - 支払いの試みに、テストのモードで作ったか（test_mode）。返金・問い合わせ・通知の突き合わせは、作ったときのモードの鍵で行う。
 *   今ある行は、支払いの画面の ID（cs_test_ / cs_live_）か、それが無い行（預けたカードの請求）は今の鍵のモードから埋める
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_gateways DROP CONSTRAINT payment_gateways_provider');
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_provider CHECK (provider IN ('stripe', 'stripe_connect', 'stripe_test'))");
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_test_key_mode CHECK (provider <> 'stripe_test' OR mode = 'test')");

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->boolean('test_mode')->default(false);
        });

        DB::statement("UPDATE payment_attempts SET test_mode = true WHERE provider = 'stripe' AND provider_ref LIKE 'cs\\_test\\_%'");
        DB::statement("UPDATE payment_attempts a SET test_mode = true FROM payment_gateways g
            WHERE a.provider = 'stripe' AND a.provider_ref NOT LIKE 'cs\\_%' AND g.mode = 'test'
              AND g.provider = CASE WHEN a.stripe_account IS NULL THEN 'stripe' ELSE 'stripe_connect' END");
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('test_mode');
        });

        DB::statement('ALTER TABLE payment_gateways DROP CONSTRAINT payment_gateways_test_key_mode');
        DB::statement("DELETE FROM payment_gateways WHERE provider = 'stripe_test'");
        DB::statement('ALTER TABLE payment_gateways DROP CONSTRAINT payment_gateways_provider');
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_provider CHECK (provider IN ('stripe', 'stripe_connect'))");
    }
};
