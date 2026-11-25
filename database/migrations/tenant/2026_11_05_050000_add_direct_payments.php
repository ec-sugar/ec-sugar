<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 銀行振込・代金引換（ADR 0074）。Stripe を通さない「店が受け取る支払い」。
 * - 店の設定（payment_method_settings の 1 行）: 銀行振込を使うか・払う期限の日数（1〜14）・振込先（店の鍵で暗号化した JSON）、
 *   代金引換を使うか・手数料の段（税込みの円。JSON）・上限の額
 * - 支払いの記録（payment_attempts）: 決済代行でない記録（provider = direct・kind = direct）と、手段 bank_transfer・cod。
 *   代引きは払う期限が無い（届けた時に払う）ので、入金待ちでも pay_by が無くてよい。振込先は注文の時の写し（店の鍵で暗号化）
 * - 注文の払い方（checkout_orders.pays_with）に bank_transfer・cod
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_method_settings', function (Blueprint $table): void {
            $table->boolean('bank_transfer')->default(false);
            $table->smallInteger('bank_days')->default(7);
            $table->text('bank_account')->nullable();
            $table->boolean('cod')->default(false);
            $table->jsonb('cod_fees')->default('[]');
            $table->integer('cod_limit')->default(300000);
        });

        DB::statement('ALTER TABLE payment_method_settings ADD CONSTRAINT payment_method_settings_bank_days CHECK (bank_days BETWEEN 1 AND 14)');
        DB::statement('ALTER TABLE payment_method_settings ADD CONSTRAINT payment_method_settings_cod_limit CHECK (cod_limit BETWEEN 1 AND 1000000)');
        // 使うなら振込先がある・手数料の段がある（アプリの確かめをすり抜けても、振込先の無い銀行振込を出さない）
        DB::statement('ALTER TABLE payment_method_settings ADD CONSTRAINT payment_method_settings_bank_account CHECK (NOT bank_transfer OR bank_account IS NOT NULL)');
        DB::statement("ALTER TABLE payment_method_settings ADD CONSTRAINT payment_method_settings_cod_fees CHECK (jsonb_typeof(cod_fees) = 'array' AND (NOT cod OR jsonb_array_length(cod_fees) BETWEEN 1 AND 10))");

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->text('bank_account')->nullable();
        });

        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_provider');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_provider CHECK (provider IN ('stripe', 'direct'))");
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_kind');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_kind CHECK (kind IN ('checkout', 'stored', 'direct'))");
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_method');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_method CHECK (method IS NULL OR method IN ('card', 'apple_pay', 'google_pay', 'konbini', 'paypay', 'other', 'bank_transfer', 'cod'))");
        // 店が受け取る支払いは、店が受け取る記録だけ（決済代行の記録と取り違えない）
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_direct CHECK ((provider = 'direct') = (kind = 'direct') AND (kind <> 'direct' OR method IN ('bank_transfer', 'cod')))");
        // 入金待ちには払いの期限がある。代引きだけは届けた時に払うので期限が無い
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_awaiting_pay_by');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_awaiting_pay_by CHECK (status <> 'awaiting' OR pay_by IS NOT NULL OR (kind = 'direct' AND method = 'cod'))");
        // 1 つの注文に、店が受け取る支払いの記録は 1 つだけ
        DB::statement("CREATE UNIQUE INDEX payment_attempts_one_direct ON payment_attempts (transaction_id) WHERE kind = 'direct'");

        DB::statement('ALTER TABLE checkout_orders DROP CONSTRAINT checkout_orders_pays_with');
        DB::statement("ALTER TABLE checkout_orders ADD CONSTRAINT checkout_orders_pays_with CHECK (pays_with IN ('checkout', 'stored', 'bank_transfer', 'cod'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE checkout_orders DROP CONSTRAINT checkout_orders_pays_with');
        DB::statement("ALTER TABLE checkout_orders ADD CONSTRAINT checkout_orders_pays_with CHECK (pays_with IN ('checkout', 'stored'))");

        DB::statement('DROP INDEX IF EXISTS payment_attempts_one_direct');
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_awaiting_pay_by');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_awaiting_pay_by CHECK (status <> 'awaiting' OR pay_by IS NOT NULL)");
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT IF EXISTS payment_attempts_direct');
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_method');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_method CHECK (method IS NULL OR method IN ('card', 'apple_pay', 'google_pay', 'konbini', 'paypay', 'other'))");
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_kind');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_kind CHECK (kind IN ('checkout', 'stored'))");
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_provider');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_provider CHECK (provider IN ('stripe'))");

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('bank_account');
        });

        DB::statement('ALTER TABLE payment_method_settings DROP CONSTRAINT IF EXISTS payment_method_settings_cod_fees');
        DB::statement('ALTER TABLE payment_method_settings DROP CONSTRAINT IF EXISTS payment_method_settings_bank_account');
        DB::statement('ALTER TABLE payment_method_settings DROP CONSTRAINT IF EXISTS payment_method_settings_cod_limit');
        DB::statement('ALTER TABLE payment_method_settings DROP CONSTRAINT IF EXISTS payment_method_settings_bank_days');

        Schema::table('payment_method_settings', function (Blueprint $table): void {
            $table->dropColumn(['bank_transfer', 'bank_days', 'bank_account', 'cod', 'cod_fees', 'cod_limit']);
        });
    }
};
