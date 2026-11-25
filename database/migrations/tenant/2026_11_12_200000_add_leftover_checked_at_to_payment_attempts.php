<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 払われた注文に残った支払いの画面・払込票の見回り（storefront:expire-unpaid-orders の closeLeftoversOfPaid）が「最後に確かめた時刻」を持つ。
 * 1 回に見る数に上限があり、並びが無いと、閉じられないもの（店の Stripe の鍵が無い・レジで処理中のまま）がいつも先頭に来て後ろの注文を塞いだ。
 * 確かめた時刻が古い順（まだのものが先）に見て、閉じられなかったものも印を付けて後ろへ回す（checkout_orders.expiry_checked_at と同じ考え）。
 * 支払いの画面は注文の行（checkout_orders）を持たない取引（ギフトカード・チケットなど）にもあるので、印は支払いの記録の側に置く。
 * 中身（支払いの状態）ではなく、見回りの順番のためだけの印。足すだけなので、古いイメージでもそのまま動く
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->timestampTz('leftover_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('leftover_checked_at');
        });
    }
};
