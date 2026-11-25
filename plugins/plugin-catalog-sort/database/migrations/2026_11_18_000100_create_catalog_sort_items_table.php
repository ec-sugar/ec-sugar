<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App「並び順と絞り込み」の表（ADR 0025）。プラグインの表なので名前は catalog_sort で始め、核の表は作らない・変えない
 * （核の listings への参照の外部キーだけ）。店の DB に流れ、使っていない店でも表はでき、空のまま。
 *
 * - catalog_sort_items: 店が一覧の並びを手で決めた商品（商品ごとに 1 行）と、その商品に付けたタグ
 *   - listing_id は商品ごとに 1 行（一意）。商品を消したら、この行も消える（cascade）
 *   - position は店で 1 つの並び（小さいほど先。入れ替えは 1 つのトランザクションで 2 行を書くので、一意の確かめは終わりに〔DEFERRABLE〕）
 *   - tags は店の人が書いた短い言葉の配列（お客さんの個人情報は入れない）。数と形は DB でも守り、絞り込みで引くので GIN の索引を付ける
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_sort_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->jsonb('tags')->default('[]');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE catalog_sort_items ADD CONSTRAINT catalog_sort_items_position_unique UNIQUE (position) DEFERRABLE INITIALLY DEFERRED');
        DB::statement("ALTER TABLE catalog_sort_items ADD CONSTRAINT catalog_sort_items_tags_check CHECK (jsonb_typeof(tags) = 'array' AND jsonb_array_length(tags) <= 5)");
        // タグで絞り込むとき（tags @> '["札"]'）に、商品の数が多い店でも索引で引けるように
        DB::statement('CREATE INDEX catalog_sort_items_tags_index ON catalog_sort_items USING GIN (tags jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_sort_items');
    }
};
