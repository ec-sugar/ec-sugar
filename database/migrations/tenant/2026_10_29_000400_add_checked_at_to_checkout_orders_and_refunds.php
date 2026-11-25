<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 定期の確かめで「最後に確かめた時刻」を持つ（別の窓のレビュー 4）。
 * 払われない注文の期限切れと、途中の返金の確かめ直しは、1 回に見る数に上限がある。古い順に見ると、決まりきらないもの（Stripe がまだ払えると言う・保留のまま）が
 * 先頭に溜まったとき、後ろが永遠に見られない。確かめた時刻が古い順（まだのものが先）に見て、決まらなかったものは後ろへ回す。
 * 中身（取引・返金の状態）ではなく、見回りの順番のためだけの印。足すだけなので、古いイメージでもそのまま動く。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_orders', function (Blueprint $table): void {
            $table->timestampTz('expiry_checked_at')->nullable();
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->timestampTz('checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_orders', function (Blueprint $table): void {
            $table->dropColumn('expiry_checked_at');
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn('checked_at');
        });
    }
};
