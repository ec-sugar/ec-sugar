<?php

declare(strict_types=1);

use App\Core\Shop\Shipping\Regions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 送料の表（ADR 0064）。配送の方法（通常便・クール便など）ごとに、47 都道府県の額と「この金額以上で無料」を持つ。
 * 商品は使う方法（空ならいちばん上の方法）と「送料無料の商品」の印を持つ。
 * 今の全国一律の送料と無料の線は、全国同じ額の「通常便」1 つに写す（行の無い店は 0 円・無料の線なし）。古い列は残すが読まない（戻せるように）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 30)->unique();
            $table->smallInteger('position');
            $table->bigInteger('free_over')->nullable();   // この小計（値引きの後・税込み）以上で無料。null は無料にしない
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE shipping_methods ADD CONSTRAINT shipping_methods_free_over_range CHECK (free_over IS NULL OR free_over BETWEEN 1 AND 10000000)');
        DB::statement("ALTER TABLE shipping_methods ADD CONSTRAINT shipping_methods_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE shipping_methods ADD CONSTRAINT shipping_methods_position_range CHECK (position BETWEEN 1 AND 10)');

        Schema::create('shipping_rates', function (Blueprint $table): void {
            $table->foreignUlid('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->string('prefecture', 8);
            $table->bigInteger('fee');
            $table->primary(['method_id', 'prefecture']);
        });

        $known = implode(',', array_map(static fn (string $p): string => "'{$p}'", Regions::PREFECTURES));
        DB::statement('ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_fee_range CHECK (fee BETWEEN 0 AND 100000)');
        DB::statement("ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_prefecture_known CHECK (prefecture IN ({$known}))");

        Schema::table('listings', function (Blueprint $table): void {
            // 消した方法の商品は、いちばん上の方法に戻る
            $table->foreignUlid('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->boolean('free_shipping')->default(false);
        });

        // 今の送料を「通常便」1 つに写す
        $settings = DB::table('shop_settings')->first();
        $fee = (int) ($settings->shipping_fee ?? 0);
        $freeOver = $settings === null || $settings->free_shipping_over === null ? null : (int) $settings->free_shipping_over;
        $id = strtolower((string) Str::ulid());
        $now = now();

        DB::table('shipping_methods')->insert(['id' => $id, 'name' => '通常便', 'position' => 1, 'free_over' => $freeOver, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('shipping_rates')->insert(array_map(static fn (string $p): array => ['method_id' => $id, 'prefecture' => $p, 'fee' => $fee], Regions::PREFECTURES));
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipping_method_id');
            $table->dropColumn('free_shipping');
        });
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_methods');
    }
};
