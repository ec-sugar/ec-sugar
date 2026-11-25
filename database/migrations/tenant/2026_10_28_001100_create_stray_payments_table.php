<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 返金が要る支払い。同じ注文が 2 つの支払いの画面で二重に払われた・取り消した注文に支払いが届いた、のとき。
 * このお金は台帳に入れていない（支払いにしなかった）ので、返金も台帳に入れず、ここと記録（イベント）で追う
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stray_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('attempt_id')->unique()->constrained('payment_attempts')->restrictOnDelete();
            $table->string('reason', 16);               // duplicate | late
            $table->bigInteger('amount');
            $table->string('payment_ref', 255)->nullable(); // Stripe の支払いの ID（pi_）
            $table->string('status', 16)->default('pending'); // pending | refunded
            $table->string('refund_ref', 255)->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE stray_payments ADD CONSTRAINT stray_payments_reason CHECK (reason IN ('duplicate', 'late'))");
        DB::statement("ALTER TABLE stray_payments ADD CONSTRAINT stray_payments_status CHECK (status IN ('pending', 'refunded') AND (status = 'refunded') = (refund_ref IS NOT NULL))");
        DB::statement('ALTER TABLE stray_payments ADD CONSTRAINT stray_payments_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stray_payments');
    }
};
