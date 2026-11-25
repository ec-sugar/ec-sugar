<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 預けたカードの指紋（Stripe の card.fingerprint。同じ Stripe アカウントの中で同じカード番号なら同じ値。ADR 0080）。
 * カードの番号でも平文の個人情報でもない（番号から作った決済代行の識別子で、ほかのアカウントでは別の値）。
 * App「抽選販売」が、1 人が別の会員で同じカードを使って申し込んだのを 1 つにまとめるのに使う。前に預けたカードは空のまま
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stored_payment_methods', function (Blueprint $table): void {
            $table->string('fingerprint', 64)->nullable();
            $table->index(['account', 'fingerprint']);
        });
        DB::statement("ALTER TABLE stored_payment_methods ADD CONSTRAINT stored_payment_methods_fingerprint CHECK (fingerprint IS NULL OR fingerprint ~ '^[A-Za-z0-9]{1,64}$')");
    }

    public function down(): void
    {
        Schema::table('stored_payment_methods', function (Blueprint $table): void {
            $table->dropIndex(['account', 'fingerprint']);
            $table->dropColumn('fingerprint');
        });
    }
};
