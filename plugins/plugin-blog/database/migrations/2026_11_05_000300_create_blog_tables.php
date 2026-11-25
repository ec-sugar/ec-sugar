<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * お知らせ・ブログの表（ADR 0072）。プラグインの表なので名前は blog で始め、核の表は変えない（ショップの写真を参照するだけ）。
 *
 * - blog_categories: 記事の種類（名前・URL の絞り込みの名前・並び）。最初は「お知らせ」「ブログ」。店が足せる（10 個まで。数は App が数える）
 * - blog_posts: 記事（題・URL の名前・本文のブロック・抜き書き・一覧の写真・種類・状態・予約の日時・公開の日時）
 *
 * 記事は店の人が書く文で、お客さんの個人情報は入れない。長さ・形・状態の組み合わせは DB でも守る:
 * 公開（published）の記事だけが公開の日時を持つ（日時が今より先なら予約）。下書き（draft）は持たない。
 * 予約の日時（publish_at）は欄に入れた値で、公開の日時（published_at）は「この時から出る」。ショップは published_at が今までの公開の記事だけを出す
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 20)->unique();
            $table->string('slug', 40)->unique();
            $table->unsignedInteger('position');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE blog_categories ADD CONSTRAINT blog_categories_shape_check CHECK (char_length(btrim(name)) > 0 AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");

        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->constrained('blog_categories')->restrictOnDelete();
            $table->string('title', 100);
            $table->string('slug', 60)->unique();
            $table->string('excerpt', 200)->nullable();
            // 一覧と共有の写真。ショップの写真を消したら外す（記事は残す）
            $table->foreignUlid('image_id')->nullable()->constrained('shop_media')->nullOnDelete();
            $table->jsonb('body')->default('[]');
            $table->string('status', 16)->default('draft');
            $table->timestampTz('publish_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'published_at']);
            $table->index(['category_id', 'status', 'published_at']);
        });

        DB::statement("ALTER TABLE blog_posts ADD CONSTRAINT blog_posts_shape_check CHECK (
            char_length(btrim(title)) > 0
            AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'
            AND slug NOT IN ('feed', 'preview', 'page', 'category', 'index')
            AND (excerpt IS NULL OR char_length(btrim(excerpt)) > 0)
            AND jsonb_typeof(body) = 'array'
            AND jsonb_array_length(body) <= 100
            AND status IN ('draft', 'published')
            AND ((status = 'published') = (published_at IS NOT NULL))
        )");

        // 最初の種類（店が名前の後ろに足せる。並びは小さいほど先）
        $now = now();
        DB::table('blog_categories')->insert([
            ['id' => strtolower((string) Str::ulid()), 'name' => 'お知らせ', 'slug' => 'news', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => strtolower((string) Str::ulid()), 'name' => 'ブログ', 'slug' => 'blog', 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_categories');
    }
};
