<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 支払いの画面を頼む中身の記録。Stripe に頼む前に、何回目か・冪等キー・有効期限を残す。
 * 答えが届かずにやり直すときは、同じ中身で頼む（Stripe は同じ冪等キーで違う中身を断る）。画面が記録できたら attempt_id を付ける
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('idempotency_key', 191)->unique();
            $table->timestampTz('expires_at');
            $table->foreignUlid('attempt_id')->nullable()->constrained('payment_attempts')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['transaction_id', 'attempt']);
        });

        DB::statement('ALTER TABLE checkout_plans ADD CONSTRAINT checkout_plans_attempt CHECK (attempt > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_plans');
    }
};
