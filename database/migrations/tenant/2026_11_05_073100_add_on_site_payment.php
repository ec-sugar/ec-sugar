<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 予約の当日払い（ADR 0056）。店が受け取る支払い（ADR 0074）の手段に on_site を足す。
 * - 支払いの記録（payment_attempts）の手段に on_site。代引きと同じく、受け渡し（来店）の時に払うので入金待ちでも払う期限が無い
 * - 注文の払い方（checkout_orders.pays_with）に on_site（決済代行の見回り・銀行振込の期限切れの見回りには混ざらない）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_method');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_method CHECK (method IS NULL OR method IN ('card', 'apple_pay', 'google_pay', 'konbini', 'paypay', 'other', 'bank_transfer', 'cod', 'on_site'))");
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_direct');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_direct CHECK ((provider = 'direct') = (kind = 'direct') AND (kind <> 'direct' OR method IN ('bank_transfer', 'cod', 'on_site')))");
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_awaiting_pay_by');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_awaiting_pay_by CHECK (status <> 'awaiting' OR pay_by IS NOT NULL OR (kind = 'direct' AND method IN ('cod', 'on_site')))");

        DB::statement('ALTER TABLE checkout_orders DROP CONSTRAINT checkout_orders_pays_with');
        DB::statement("ALTER TABLE checkout_orders ADD CONSTRAINT checkout_orders_pays_with CHECK (pays_with IN ('checkout', 'stored', 'bank_transfer', 'cod', 'on_site'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE checkout_orders DROP CONSTRAINT checkout_orders_pays_with');
        DB::statement("ALTER TABLE checkout_orders ADD CONSTRAINT checkout_orders_pays_with CHECK (pays_with IN ('checkout', 'stored', 'bank_transfer', 'cod'))");

        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_awaiting_pay_by');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_awaiting_pay_by CHECK (status <> 'awaiting' OR pay_by IS NOT NULL OR (kind = 'direct' AND method = 'cod'))");
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_direct');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_direct CHECK ((provider = 'direct') = (kind = 'direct') AND (kind <> 'direct' OR method IN ('bank_transfer', 'cod')))");
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_method');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_method CHECK (method IS NULL OR method IN ('card', 'apple_pay', 'google_pay', 'konbini', 'paypay', 'other', 'bank_transfer', 'cod'))");
    }
};
