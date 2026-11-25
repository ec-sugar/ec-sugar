<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 注文の便ごとの送料の決め方（ADR 0064）。方法の名前・その便の品の小計（値引きの後）・取った額・決め方を写して持つ。
 * 贈り物でいちばん高い額で取った便は、その時の 47 県の額（rates）も写し、受け取りの住所で決まる額（settled_fee）との差額を返金する。
 * 都道府県（送り先）は持たない（ADR 0010）。受渡には方法の名前を写す（発送の画面で便が分かる）。
 * 返金の記録に目的 shipping（送料の差額）を足す。1 注文に 1 回（断られた返金は数えない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipping', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->smallInteger('position');
            $table->string('method_id', 26)->nullable();     // 消された方法もあるので外部キーにしない
            $table->string('method_name', 30);
            $table->bigInteger('items_amount');               // この便の品の小計（値引きの後）
            $table->bigInteger('fee');                        // 取った額（無料なら 0）
            $table->string('basis', 16);                      // rate・highest・free_over・free_item・coupon
            $table->jsonb('rates')->nullable();               // highest のときだけ: 注文の時の 47 県の額
            $table->bigInteger('settled_fee')->nullable();    // highest の便の、受け取りの住所で決まった額
            $table->timestampsTz();

            $table->unique(['transaction_id', 'position']);
        });

        DB::statement('ALTER TABLE order_shipping ADD CONSTRAINT order_shipping_fee_range CHECK (fee BETWEEN 0 AND 100000)');
        DB::statement("ALTER TABLE order_shipping ADD CONSTRAINT order_shipping_basis_known CHECK (basis IN ('rate', 'highest', 'free_over', 'free_item', 'coupon'))");
        DB::statement("ALTER TABLE order_shipping ADD CONSTRAINT order_shipping_rates_for_highest CHECK ((basis = 'highest') = (rates IS NOT NULL) AND (rates IS NULL OR jsonb_typeof(rates) = 'object'))");
        DB::statement("ALTER TABLE order_shipping ADD CONSTRAINT order_shipping_settled CHECK (settled_fee IS NULL OR (basis = 'highest' AND settled_fee BETWEEN 0 AND fee))");

        Schema::table('fulfillments', function (Blueprint $table): void {
            $table->string('shipping_method', 30)->nullable();
        });

        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_purpose');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_purpose CHECK (purpose IN ('cancel', 'stray', 'external', 'shipping') AND (purpose = 'stray') = (stray_payment_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX refunds_one_shipping ON refunds (transaction_id) WHERE purpose = 'shipping' AND status <> 'failed'");
    }

    public function down(): void
    {
        // 送料の差額の返金の記録があれば、制約を戻せずに止まる（お金の記録は消さない）
        DB::statement('DROP INDEX IF EXISTS refunds_one_shipping');
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_purpose');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_purpose CHECK (purpose IN ('cancel', 'stray', 'external') AND (purpose = 'stray') = (stray_payment_id IS NOT NULL))");

        Schema::table('fulfillments', function (Blueprint $table): void {
            $table->dropColumn('shipping_method');
        });
        Schema::dropIfExists('order_shipping');
    }
};
