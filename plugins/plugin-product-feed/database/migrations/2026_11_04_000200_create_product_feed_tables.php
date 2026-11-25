<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 商品フィードの表（App「商品フィード」、ADR 0025・0050 の 2）。プラグインの表なので名前は product_feed で始め、核の表は変えない（商品を参照するだけ）。
 *
 * - product_feed_feeds: フィードの行き先ごと（Google・Meta の 2 行。移行で作る）に、URL の秘密のトークン。
 *   トークンは店の鍵で暗号化して置き（管理画面に URL を出すため）、照らすのは SHA-256 の値で（要求のたびに復号しない）。両方とも空か、両方とも入っている
 * - product_feed_listings: 商品ごとの出し方（フィードから外す・ブランド・Google の商品カテゴリの番号・状態・品番を MPN として出す）。
 *   無い商品は既定（出す・App の設定の既定・新品・MPN を出さない）
 *
 * どちらも店の人が書く値と商品の ID だけで、お客さんの個人情報は入れない。形は DB でも守る
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_feed_feeds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('format', 16)->unique();
            $table->text('token')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE product_feed_feeds ADD CONSTRAINT product_feed_feeds_format_check CHECK (format IN ('google', 'meta'))");
        DB::statement("ALTER TABLE product_feed_feeds ADD CONSTRAINT product_feed_feeds_token_check CHECK ((token IS NULL AND token_hash IS NULL AND issued_at IS NULL) OR (token IS NOT NULL AND token_hash ~ '^[0-9a-f]{64}$' AND issued_at IS NOT NULL))");

        $now = now();

        foreach (['google', 'meta'] as $format) {
            DB::table('product_feed_feeds')->insert(['id' => strtolower((string) Str::ulid()), 'format' => $format, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('product_feed_listings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // 商品を消す核の操作を止めないよう、商品と一緒に消える
            $table->foreignUlid('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->boolean('excluded')->default(false);
            $table->string('brand', 70)->nullable();
            $table->string('google_product_category', 6)->nullable();
            $table->string('condition', 16)->default('new');
            $table->boolean('mpn_from_sku')->default(false);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE product_feed_listings ADD CONSTRAINT product_feed_listings_values_check CHECK (condition IN ('new', 'used', 'refurbished') AND (brand IS NULL OR char_length(btrim(brand)) > 0) AND (google_product_category IS NULL OR google_product_category ~ '^[0-9]{1,6}$'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feed_listings');
        Schema::dropIfExists('product_feed_feeds');
    }
};
