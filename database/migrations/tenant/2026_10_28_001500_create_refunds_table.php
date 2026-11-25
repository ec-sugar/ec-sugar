<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 返金の記録（ADR 0027）。取消と返金が要る支払いの返金を、Stripe へ頼む前に残し、答えで状態を進める。
 * 台帳に返金を書くのは succeeded になってから。途中の返金（requested・pending）は対象ごとに 1 つだけ。個人情報は入れない
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('attempt_id')->constrained('payment_attempts')->restrictOnDelete();
            $table->foreignUlid('stray_payment_id')->nullable()->constrained('stray_payments')->restrictOnDelete();
            $table->string('purpose', 16);             // cancel | stray
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('payment_ref', 255);         // 返金する支払いの ID（Stripe なら pi_）
            $table->string('idempotency_key', 191)->unique();
            $table->string('provider_ref', 255)->nullable()->unique(); // Stripe の返金の ID（re_）
            $table->string('status', 16)->default('requested'); // requested | pending | succeeded | failed
            $table->string('failure_reason', 64)->nullable();   // Stripe の失敗の符号（insufficient_funds など）
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
            $table->index(['transaction_id', 'purpose', 'status']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_purpose CHECK (purpose IN ('cancel', 'stray') AND (purpose = 'stray') = (stray_payment_id IS NOT NULL))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status CHECK (status IN ('requested', 'pending', 'succeeded', 'failed') AND (status IN ('succeeded', 'failed')) = (settled_at IS NOT NULL))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_provider_ref CHECK (status = 'requested' OR status = 'failed' OR provider_ref IS NOT NULL)");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount CHECK (amount > 0)');
        // 途中の返金は、取消なら取引ごと、返金が要る支払いなら支払いごとに 1 つだけ（二重に頼まない）
        DB::statement("CREATE UNIQUE INDEX refunds_one_active_cancel ON refunds (transaction_id) WHERE purpose = 'cancel' AND status IN ('requested', 'pending')");
        DB::statement("CREATE UNIQUE INDEX refunds_one_active_stray ON refunds (stray_payment_id) WHERE purpose = 'stray' AND status IN ('requested', 'pending')");
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
