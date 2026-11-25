<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * クーポン（ADR 0054）。値引きは注文の調整の行（kind = discount）で、ここは決まりと使った記録だけを持つ。
 * 使った記録は注文を作る時に台帳のロックの中で書き、払われずに消えた注文では released にして数を戻す。個人情報は入れない（お客さんは台帳の ID だけ）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 32)->unique();            // 大文字にそろえて持つ
            $table->string('name', 100);                     // 店の人のメモ（お客さんには出さない）
            $table->string('kind', 16);                      // fixed（円）・percent（%）・free_shipping
            $table->bigInteger('value')->default(0);         // 円か %（送料無料は 0）
            $table->bigInteger('max_discount')->nullable();  // 定率の上限の額（円）
            $table->string('target', 16)->default('all');    // all・categories・listings
            $table->jsonb('target_ids')->default('[]');
            $table->bigInteger('min_subtotal')->nullable();  // 値引きの前の小計（円）
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->integer('usage_limit')->nullable();      // 全体で使える回数
            $table->integer('per_customer_limit')->nullable(); // 1 人が使える回数（null は無制限）
            $table->boolean('members_only')->default(false);
            $table->string('status', 16)->default('active'); // active・paused
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_code_format CHECK (code ~ '^[A-Z0-9]+(-[A-Z0-9]+)*$' AND char_length(code) BETWEEN 3 AND 32)");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_kind_known CHECK (kind IN ('fixed', 'percent', 'free_shipping'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_value_range CHECK ((kind = 'fixed' AND value BETWEEN 1 AND 100000000) OR (kind = 'percent' AND value BETWEEN 1 AND 100) OR (kind = 'free_shipping' AND value = 0))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_max_discount CHECK (max_discount IS NULL OR (kind = 'percent' AND max_discount BETWEEN 1 AND 100000000))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_target_known CHECK (target IN ('all', 'categories', 'listings'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_target_ids_array CHECK (jsonb_typeof(target_ids) = 'array' AND (target = 'all') = (jsonb_array_length(target_ids) = 0))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_min_subtotal CHECK (min_subtotal IS NULL OR min_subtotal BETWEEN 1 AND 100000000)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_period CHECK (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_limits CHECK ((usage_limit IS NULL OR usage_limit >= 1) AND (per_customer_limit IS NULL OR per_customer_limit >= 1))');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_status_known CHECK (status IN ('active', 'paused'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_name_not_blank CHECK (btrim(name) <> '')");

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->foreignUlid('transaction_id')->unique()->constrained('transactions')->restrictOnDelete(); // 1 つの注文に 1 つ
            $table->foreignUlid('customer_account_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->bigInteger('amount');                    // 値引きの額（正の円。送料の分も含む）
            $table->jsonb('allocations')->default('[]');     // 商品の行（明細の位置）ごとの割り振り。一部の返金・税率ごとの合計に使う
            $table->string('status', 16)->default('reserved'); // reserved（払う前）・used（払った）・released（払われずに消えた）
            $table->timestampsTz();

            $table->index(['coupon_id', 'status']);
            $table->index(['coupon_id', 'customer_account_id']);
        });

        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_amount_nonnegative CHECK (amount >= 0)');
        DB::statement("ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_status_known CHECK (status IN ('reserved', 'used', 'released'))");

        // カートに入れたコード（注文の前の下書き。確定の時に決まりで照らし直す）
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('coupon_code', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn('coupon_code');
        });
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
