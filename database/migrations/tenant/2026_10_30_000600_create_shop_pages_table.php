<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 自由なページ（ADR 0039 区切り 3）: 店の人が作る「私たちについて」・キャンペーン・お問い合わせの案内などのページ。
 * ここに持つのはページの住所（slug。ショップの /pages/{slug}）・題・メニューに載せるか・並び順・公開の状態だけ。
 * 中身（区画の並び）はテーマの値（theme_versions）の中に page-{ID} の名前で持つ（テーマごと・下書きと公開）。
 * 書くのは ShopPageService だけ。
 *
 * - slug は英小文字・数字・ハイフン（間だけ）の 60 文字まで。今ある経路の名前（予約語）は使えない（DB でも断る。
 *   ここの一覧は ShopPage::RESERVED_SLUGS の写し。予約語を足すときは新しいマイグレーションで制約を作り直す）
 * - slug は店の中で重ならない（ゴミ箱に入れたページは数えない。戻すときに重なっていれば断る）
 * - 消すのはゴミ箱へ（deleted_at）。戻せる。数の上限はゴミ箱のページも含めて数える（中身の置き場の数を抑える）
 */
return new class extends Migration
{
    /** ShopPage::RESERVED_SLUGS の写し（後でアプリが変わっても、このマイグレーションの意味を変えない） */
    private const array RESERVED = [
        'account', 'admin', 'api', 'apps', 'assets', 'build', 'cart', 'categories', 'category', 'checkout', 'consent',
        'favicon', 'home', 'index', 'legal', 'login', 'logout', 'media', 'new', 'orders', 'p', 'page', 'pages', 'password',
        'postal', 'preview', 'product', 'products', 'register', 'robots', 'search', 'sitemap', 'storage', 'up', 'webhooks',
    ];

    public function up(): void
    {
        Schema::create('shop_pages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug', 60);                         // ショップの /pages/{slug}
            $table->string('title', 60);                        // 題（ページの見出し・メニューの文字）
            $table->boolean('in_nav')->default(false);          // ショップのメニューに載せるか
            $table->integer('position')->default(0);            // メニューと一覧の並び順
            $table->string('status', 16)->default('draft');     // draft（お客さんには出ない）・published
            $table->timestampsTz();
            $table->softDeletesTz();                            // ゴミ箱
        });

        DB::statement("ALTER TABLE shop_pages ADD CONSTRAINT shop_pages_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND char_length(slug) <= 60)");
        DB::statement("ALTER TABLE shop_pages ADD CONSTRAINT shop_pages_slug_not_reserved CHECK (slug NOT IN ('".implode("', '", self::RESERVED)."'))");
        // 制御文字は DB のロケールに頼らず範囲で書く（C0・DEL・C1）
        DB::statement("ALTER TABLE shop_pages ADD CONSTRAINT shop_pages_title_single_line CHECK (btrim(title) <> '' AND title !~ '[\\x01-\\x1f\\x7f-\\x9f]')");
        DB::statement("ALTER TABLE shop_pages ADD CONSTRAINT shop_pages_status_known CHECK (status IN ('draft', 'published'))");
        DB::statement('ALTER TABLE shop_pages ADD CONSTRAINT shop_pages_position CHECK (position >= 0)');
        // 店の中で slug は重ならない（ゴミ箱のページは数えない）
        DB::statement('CREATE UNIQUE INDEX shop_pages_slug_unique ON shop_pages (slug) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_pages');
    }
};
