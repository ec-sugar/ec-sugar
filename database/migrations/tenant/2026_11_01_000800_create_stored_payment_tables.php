<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 預けたカード（ADR 0045）。決済の部品の表（核の台帳には決済代行の ID を置かない。ADR 0026）。
 *
 * - stored_payment_customers: カードを預ける先（Stripe の Customer）。お客さんの台帳 × 決済代行 × アカウント（連結アカウント。店の鍵なら空）で 1 つ。
 *   カードを預ける前（1 回目の支払いの画面を開く時）に要るので、カードの行とは分ける（ADR 0045 の 2 の補い）
 * - stored_payment_methods: 預けたカード。カードの番号は持たない。持つのはカードの ID・ブランド・末尾 4 桁・期限（PCI DSS で持ってよい値。暗号化しない）
 * - payment_attempts.saves_card_for: この支払いの画面でカードを預けるお客さん（払われた知らせでカードを記録する）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_payment_customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->string('provider', 16);
            $table->string('account', 64)->default('');
            $table->string('customer_ref', 255);
            $table->timestampsTz();

            $table->unique(['customer_account_id', 'provider', 'account']);
        });

        Schema::create('stored_payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->string('provider', 16);
            $table->string('account', 64)->default('');
            $table->string('customer_ref', 255);
            $table->string('method_ref', 255);
            $table->string('status', 16)->default('usable');
            $table->string('brand', 32)->nullable();
            $table->char('last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'account', 'method_ref']);
            $table->index(['customer_account_id', 'status']);
        });

        DB::statement("ALTER TABLE stored_payment_methods ADD CONSTRAINT stored_payment_methods_status CHECK (status IN ('usable', 'needs_update', 'detached'))");
        DB::statement("ALTER TABLE stored_payment_methods ADD CONSTRAINT stored_payment_methods_last4 CHECK (last4 IS NULL OR last4 ~ '^[0-9]{4}$')");

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->foreignUlid('saves_card_for')->nullable()->constrained('customer_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('saves_card_for');
        });

        Schema::dropIfExists('stored_payment_methods');
        Schema::dropIfExists('stored_payment_customers');
    }
};
