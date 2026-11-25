<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 支払いの試み。決済代行の支払いの画面（Stripe の Checkout Session）1 つにつき 1 行。
 * 金額はこちらの取引から決めて渡したもの。支払いの確定は Webhook（区切り 3）で、ここの status を paid にする
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->string('provider', 16);            // stripe
            $table->string('provider_ref', 255)->unique(); // Checkout Session の ID（cs_…）
            $table->text('checkout_url');               // 支払いの画面（決済代行のドメイン）
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 16)->default('open'); // open | paid | expired
            $table->timestampTz('expires_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->index(['transaction_id', 'status']);
        });

        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_provider CHECK (provider IN ('stripe'))");
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status CHECK (status IN ('open', 'paid', 'expired'))");
        DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_amount CHECK (amount > 0)');
        // 1 つの取引で払われるのは 1 回だけ
        DB::statement("CREATE UNIQUE INDEX payment_attempts_one_paid ON payment_attempts (transaction_id) WHERE status = 'paid'");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
