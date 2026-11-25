<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 支払いの手段を増やす（ADR 0058）。
 * - 店が選ぶ手段（カードのほかにコンビニ払い・PayPay）と、コンビニ払いの払いの期限の日数（1 行だけ）
 * - 支払いの画面に出した手段（methods。頼む前に記録し、送り直しも同じ中身で）と、払われた手段（method）
 * - 後から払う手段の「入金待ち」（awaiting）と払いの期限（pay_by）・払い方の案内の画面（instructions_url。店の鍵で暗号化）
 * - 注文の知らせのメールに「入金をお待ちしています」と「お支払いの期限が過ぎました」
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->boolean('konbini')->default(false);
            $table->boolean('paypay')->default(false);
            $table->smallInteger('konbini_days')->default(3);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE payment_method_settings ADD CONSTRAINT payment_method_settings_konbini_days CHECK (konbini_days BETWEEN 1 AND 30)');

        Schema::table('checkout_plans', function (Blueprint $table): void {
            $table->string('methods', 64)->default('card');
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('methods', 64)->default('card');
            $table->string('method', 16)->nullable();
            $table->timestampTz('pay_by')->nullable();
            $table->text('instructions_url')->nullable();
        });

        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_status');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status CHECK (status IN ('open', 'awaiting', 'paid', 'expired', 'failed'))");
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_method CHECK (method IS NULL OR method IN ('card', 'apple_pay', 'google_pay', 'konbini', 'paypay', 'other'))");
        // 入金待ちには払いの期限がある（期限の過ぎた入金待ちを見回りが片付ける）
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_awaiting_pay_by CHECK (status <> 'awaiting' OR pay_by IS NOT NULL)");
        DB::statement("CREATE INDEX payment_attempts_awaiting ON payment_attempts (transaction_id) WHERE status = 'awaiting'");

        DB::statement('ALTER TABLE sent_mails DROP CONSTRAINT sent_mails_kind');
        DB::statement("ALTER TABLE sent_mails ADD CONSTRAINT sent_mails_kind CHECK (kind IN ('order_paid', 'order_shipped', 'order_awaiting_payment', 'order_payment_lapsed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sent_mails DROP CONSTRAINT sent_mails_kind');
        DB::statement("ALTER TABLE sent_mails ADD CONSTRAINT sent_mails_kind CHECK (kind IN ('order_paid', 'order_shipped'))");

        DB::statement('DROP INDEX IF EXISTS payment_attempts_awaiting');
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT IF EXISTS payment_attempts_awaiting_pay_by');
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT IF EXISTS payment_attempts_method');
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_status');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status CHECK (status IN ('open', 'paid', 'expired', 'failed'))");

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['methods', 'method', 'pay_by', 'instructions_url']);
        });

        Schema::table('checkout_plans', function (Blueprint $table): void {
            $table->dropColumn('methods');
        });

        Schema::dropIfExists('payment_method_settings');
    }
};
