<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App「ラッピング・のし」の表（ADR 0078）。プラグインの表なので名前は wrapping で始め、核の表は変えない（取引・商品を参照するだけ）。
 *
 * - wrapping_types: 店が決めるラッピングの種類（名前・税込みの料金・使うか・並び）。名前は店の人の文で個人情報ではない
 * - wrapping_listings: 商品ごとの「ラッピングできる」「のしを付けられる」（1 商品に 1 行。無ければどちらもできない）
 * - wrapping_orders: 注文ごとに選んだもの（取引で一意）。その時の種類の名前と額・のしの表書き・掛け方・額の写しと、名入れ（店の鍵で暗号化。ADR 0010）。
 *   追記だけ（UPDATE・DELETE・TRUNCATE を断る。注文の時の事実なので直さない。店を消す時は DB ごと消す）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wrapping_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 30);
            $table->unsignedInteger('price');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE wrapping_types ADD CONSTRAINT wrapping_types_shape CHECK (char_length(btrim(name)) > 0 AND price <= 100000)');

        Schema::create('wrapping_listings', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->primary()->constrained('listings')->cascadeOnDelete();
            $table->boolean('wrappable')->default(false);
            $table->boolean('noshi')->default(false);
            $table->timestampsTz();
        });

        Schema::create('wrapping_orders', function (Blueprint $table): void {
            $table->foreignUlid('transaction_id')->primary()->constrained('transactions')->restrictOnDelete();
            $table->char('wrapping_type_id', 26)->nullable();
            $table->string('wrapping_name', 30)->nullable();
            $table->unsignedInteger('wrapping_amount')->default(0);
            $table->string('noshi_title', 20)->nullable();
            $table->string('noshi_style', 8)->nullable();
            $table->text('noshi_name')->nullable();
            $table->unsignedInteger('noshi_amount')->default(0);
            $table->timestampTz('created_at');
        });

        // ラッピングは名前があれば種類の写しがある・のしは表書きと掛け方がそろう・名入れはのしがある時だけで暗号文・何も選ばない行は作らない
        DB::statement('ALTER TABLE wrapping_orders ADD CONSTRAINT wrapping_orders_shape CHECK ('
            .'(wrapping_name IS NULL) = (wrapping_type_id IS NULL)'
            .' AND (wrapping_name IS NOT NULL OR wrapping_amount = 0)'
            .' AND (noshi_title IS NULL) = (noshi_style IS NULL)'
            ." AND (noshi_style IS NULL OR noshi_style IN ('outer', 'inner'))"
            .' AND (noshi_title IS NOT NULL OR (noshi_amount = 0 AND noshi_name IS NULL))'
            ." AND (noshi_name IS NULL OR noshi_name LIKE 'te1:%')"
            .' AND (wrapping_name IS NOT NULL OR noshi_title IS NOT NULL)'
            .' AND wrapping_amount <= 100000 AND noshi_amount <= 100000)');
        DB::statement('CREATE TRIGGER wrapping_orders_append_only BEFORE UPDATE OR DELETE ON wrapping_orders FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER wrapping_orders_no_truncate BEFORE TRUNCATE ON wrapping_orders FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('wrapping_orders');
        Schema::dropIfExists('wrapping_listings');
        Schema::dropIfExists('wrapping_types');
    }
};
