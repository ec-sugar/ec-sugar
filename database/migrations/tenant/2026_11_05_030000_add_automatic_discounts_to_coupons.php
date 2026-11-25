<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 自動で効く割引とまとめ買いの割引（ADR 0065）。クーポンと同じ表に載せ、数える・戻す・使った記録・割り振りは今の仕組みのまま。
 *
 * - method: code（コードを入れて使うクーポン）・automatic（コード無しで、条件に合えば効く割引）。コードは code の時だけ持つ
 * - label: 自動の割引のお客さんに見せる名前（40 文字まで。「秋のセール 全品 10%」）。自動の割引だけが持つ
 * - min_quantity: 対象の商品の数の合計がこれ以上（クーポンにも自動の割引にも付けられる）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->string('method', 16)->default('code');
            $table->string('label', 40)->nullable();
            $table->integer('min_quantity')->nullable();
            // カートを見るたびに、使っている自動の割引を引く
            $table->index(['method', 'status']);
        });

        DB::statement('ALTER TABLE coupons ALTER COLUMN code DROP NOT NULL');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_method_known CHECK (method IN ('code', 'automatic'))");
        // コードは code の時だけ必須（自動の割引はコードを持たない）
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_code_by_method CHECK ((method = 'code') = (code IS NOT NULL))");
        // お客さんに見せる名前は自動の割引だけが持ち、空にしない
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_label_by_method CHECK ((method = 'automatic') = (label IS NOT NULL) AND (label IS NULL OR btrim(label) <> ''))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_min_quantity CHECK (min_quantity IS NULL OR min_quantity BETWEEN 1 AND 1000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS coupons_min_quantity');
        DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS coupons_label_by_method');
        DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS coupons_code_by_method');
        DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS coupons_method_known');
        DB::table('coupons')->where('method', 'automatic')->delete();
        DB::statement('ALTER TABLE coupons ALTER COLUMN code SET NOT NULL');

        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropIndex(['method', 'status']);
            $table->dropColumn(['method', 'label', 'min_quantity']);
        });
    }
};
