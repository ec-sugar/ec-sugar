<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支払いの画面を作った Stripe のアカウント。つないだ店（Connect）の連結アカウントの ID、店の鍵を貼る形なら null。
 * 返金・問い合わせ・画面を閉じる操作は、払われたアカウントの鍵で行う（設定を鍵の形からつなぐ形へ移しても、前の支払いを別のアカウントに頼まない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('stripe_account', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('stripe_account');
        });
    }
};
