<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * カードを替える画面（ADR 0045 の 1 の openCardUpdate。の後半）。お客さんが決済代行のカード登録の画面（Stripe の setup のモード）を開いた記録。
 * 登録の知らせ（checkout.session.completed）は、ここにある画面の、作った時と同じアカウントのものだけを受ける。カードの値は持たない
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_setups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('account', 128);
            $table->string('session_ref', 255)->nullable()->unique();
            $table->string('status', 16)->default('open');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['customer_account_id', 'status']);
        });

        DB::statement("ALTER TABLE card_setups ADD CONSTRAINT card_setups_status CHECK (status IN ('open', 'completed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('card_setups');
    }
};
