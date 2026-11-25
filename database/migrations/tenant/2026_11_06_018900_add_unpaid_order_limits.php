<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * まだ払われていない注文の回数の上限（ADR 0085）。
 * - 店の設定「銀行振込・代金引換は会員だけ」（payment_method_settings.direct_members_only。既定は切る＝今までどおりゲストも選べる）
 * - ショップの注文の印（checkout_orders）: 接続元（source_index。IPv6 は /64）・2 段目の接続元（source_wide_index。IPv6 の /48、IPv4 は空）・
 *   数えるときだけ正規化したメール（email_mark）。どれも店の鍵の HMAC（16 進 64 文字）で、平文の IP アドレス・メールは持たない。
 *   同じ接続元・メールの入金待ちとメールの 1 時間の確定の数を数えるためだけに使い、入金待ちとして数える日数を過ぎた印は見回りが消す
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_method_settings', function (Blueprint $table): void {
            $table->boolean('direct_members_only')->default(false);
        });

        Schema::table('checkout_orders', function (Blueprint $table): void {
            $table->char('source_index', 64)->nullable();
            $table->char('source_wide_index', 64)->nullable();
            $table->char('email_mark', 64)->nullable();
            $table->index(['source_index', 'created_at']);
            $table->index(['source_wide_index', 'created_at']);
            $table->index(['email_mark', 'created_at']);
        });

        // 印は HMAC の 16 進 64 文字だけ（アプリの誤りで平文の IP アドレス・メールが入っても、DB が断る）
        foreach (['source_index', 'source_wide_index', 'email_mark'] as $column) {
            DB::statement("ALTER TABLE checkout_orders ADD CONSTRAINT checkout_orders_{$column}_hex CHECK ({$column} IS NULL OR {$column} ~ '^[0-9a-f]{64}$')");
        }
    }

    public function down(): void
    {
        foreach (['source_index', 'source_wide_index', 'email_mark'] as $column) {
            DB::statement("ALTER TABLE checkout_orders DROP CONSTRAINT IF EXISTS checkout_orders_{$column}_hex");
        }

        Schema::table('checkout_orders', function (Blueprint $table): void {
            $table->dropIndex(['source_index', 'created_at']);
            $table->dropIndex(['source_wide_index', 'created_at']);
            $table->dropIndex(['email_mark', 'created_at']);
            $table->dropColumn(['source_index', 'source_wide_index', 'email_mark']);
        });

        Schema::table('payment_method_settings', function (Blueprint $table): void {
            $table->dropColumn('direct_members_only');
        });
    }
};
