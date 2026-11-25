<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 検索と共有の見え方（ADR 0061）。
 *
 * - 商品・カテゴリ・自由なページに「検索の結果に出す題と説明」（空なら自動で作る）。どちらも 1 行（改行と制御文字を断る）
 * - shop_seo: 店に 1 行。トップの題と説明・共有の既定の写真（ショップの写真。使っている間は消させない）
 * - redirects: 店が足す 301 の転送（古い URL → 同じショップの中の新しい URL）。元は一意、行き先は 1 つの / で始まる英数字と記号だけ
 *   （スキームとホストを持てない。外へ飛ばさない）。元と行き先が同じ行は持たない。数の上限（1,000）は書くサービスが錠の中で数える
 */
return new class extends Migration
{
    private const array TABLES = ['listings', 'categories', 'shop_pages'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('seo_title', 70)->nullable();
                $blueprint->string('seo_description', 200)->nullable();
            });

            self::singleLine($table, 'seo_title');
            self::singleLine($table, 'seo_description');
        }

        Schema::create('shop_seo', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('home_title', 70)->nullable();
            $table->string('home_description', 200)->nullable();
            $table->foreignUlid('share_image_id')->nullable()->constrained('shop_media')->restrictOnDelete();
            $table->timestampsTz();
        });

        self::singleLine('shop_seo', 'home_title');
        self::singleLine('shop_seo', 'home_description');
        // 店に 1 行だけ
        DB::statement('CREATE UNIQUE INDEX shop_seo_one_row ON shop_seo ((true))');

        Schema::create('redirects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('from_path', 500)->unique();
            $table->string('to_path', 500);
            // 店の人が足したか（manual）、slug を変えた時に核が足したか（auto）
            $table->string('source', 8);
            $table->timestampsTz();

            $table->index('to_path');
        });

        DB::statement("ALTER TABLE redirects ADD CONSTRAINT redirects_source CHECK (source IN ('manual', 'auto'))");
        // 元は / で始まる 1 行（日本語は戻した形で持つ）。// で始まらない
        DB::statement("ALTER TABLE redirects ADD CONSTRAINT redirects_from_path CHECK (from_path ~ '^/' AND from_path !~ '^//' AND from_path !~ '[\\x01-\\x20\\x7f\\\\]')");
        // 行き先は同じショップの中の道筋だけ（/ で始まり、// と /\ で始まらない、見える ASCII だけ）
        DB::statement("ALTER TABLE redirects ADD CONSTRAINT redirects_to_path CHECK (to_path ~ '^/([^/\\\\]|$)' AND to_path ~ '^[\\x21-\\x7e]+$' AND to_path !~ '\\\\')");
        DB::statement('ALTER TABLE redirects ADD CONSTRAINT redirects_not_self CHECK (from_path <> to_path)');
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('shop_seo');

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['seo_title', 'seo_description']);
            });
        }
    }

    /**
     * 空でない 1 行（改行と制御文字を持たない）
     */
    private static function singleLine(string $table, string $column): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_single_line CHECK ({$column} IS NULL OR ({$column} <> '' AND {$column} !~ '[\\x01-\\x1f\\x7f-\\x9f]'))");
    }
};
