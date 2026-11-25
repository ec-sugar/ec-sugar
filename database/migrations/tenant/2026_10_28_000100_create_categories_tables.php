<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商品カテゴリ。木（親を指す）で、深さは 3 段まで。商品は複数のカテゴリに入り、道案内用の主カテゴリは 1 つ。
 * 書くのは CategoryService だけ（木の形・深さ・循環は EventStore::lock() の中で守る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('parent_id')->nullable();
            $table->string('name', 40);
            $table->string('slug', 60)->unique();                // URL に使う（店の中で重ならない）
            $table->smallInteger('position');                    // 兄弟の中の並び
            $table->foreignUlid('image_id')->nullable()->constrained('listing_images')->nullOnDelete(); // 代表の写真（商品の写真を指す）
            $table->timestampsTz();

            $table->index(['parent_id', 'position']);
        });

        // 自分の表を指す外部キーは、主キーができてから付ける。子があるうちは消せない
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_position_nonnegative CHECK (position >= 0)');
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_name_not_blank CHECK (btrim(name) <> '')");

        Schema::create('listing_categories', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignUlid('category_id')->constrained('categories')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);       // 道案内（パンくず）に使う主カテゴリ
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['listing_id', 'category_id']);
            $table->index('category_id');
        });

        // 主カテゴリは商品ごとに 1 つまで
        DB::statement('CREATE UNIQUE INDEX listing_categories_one_primary ON listing_categories (listing_id) WHERE is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_categories');
        Schema::dropIfExists('categories');
    }
};
