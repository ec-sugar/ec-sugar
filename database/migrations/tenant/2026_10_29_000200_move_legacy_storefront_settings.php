<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 店の設定の見せ方の項目（お知らせの帯・ヒーロー・安心の一行・トップの区画）を、店の設定から外す（ADR 0028 区切り 2c 後半）。
 * 見せ方はテーマの区画の値（theme_versions）が持つ。核は、どのテーマ用の設定の項目も持たない。
 *
 * 値は消さずに控えの表（legacy_storefront_settings）へ移す。区画を宣言したテーマへ初めて切り替えたときに、
 * LegacyContentImport がここから写す（区画の無いテーマを使う店の中身も、テーマを作り直した後に写せる）。
 * 控えの写真の ID は外部キーで守らない（写真の置き場から消してよい。写すときに無い写真は外す）。
 *
 * 控えへ移すのは DB の中だけで完結させる（アプリのコードが後で変わっても、この移行の意味を変えない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_storefront_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();                  // 店の設定の行の ID のまま
            $table->jsonb('storefront');                    // お知らせの帯・ヒーローの見出しと文・安心の一行
            $table->jsonb('sections')->nullable();          // トップの区画（カテゴリ・コーディネート・人気・季節の特集）
            $table->char('hero_media_id', 26)->nullable();  // ヒーローの写真（ショップの写真の ID。外部キーなし）
            $table->timestampTz('archived_at');
        });

        DB::statement('CREATE UNIQUE INDEX legacy_storefront_settings_single_row ON legacy_storefront_settings ((true))'); // 1 行だけ
        DB::statement("ALTER TABLE legacy_storefront_settings ADD CONSTRAINT legacy_storefront_settings_storefront_object CHECK (jsonb_typeof(storefront) = 'object')");
        DB::statement("ALTER TABLE legacy_storefront_settings ADD CONSTRAINT legacy_storefront_settings_sections_object CHECK (sections IS NULL OR jsonb_typeof(sections) = 'object')");

        DB::statement('INSERT INTO legacy_storefront_settings (id, storefront, sections, hero_media_id, archived_at) SELECT id, storefront, sections, hero_media_id, now() FROM shop_settings');

        // 公開の前の例外（ADR 0006）: 店の設定の古い項目を控えの表（legacy_storefront_settings）へ移した。前のバージョンへ戻すと列が無いので、公開の版からは足す → 次の版で消す
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hero_media_id');
            $table->dropColumn(['storefront', 'version', 'sections']); // 形の検査（shop_settings_*_object・version_nonnegative）も一緒に消える
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->jsonb('storefront')->default('{}');
            $table->integer('version')->default(0);
            $table->jsonb('sections')->nullable();
            $table->foreignUlid('hero_media_id')->nullable()->constrained('shop_media')->restrictOnDelete();
        });

        // 控えの写真が置き場から消えていたら、外部キーを満たすため外す
        DB::statement('UPDATE shop_settings s SET storefront = l.storefront, sections = l.sections, hero_media_id = (SELECT m.id FROM shop_media m WHERE m.id = l.hero_media_id)
            FROM legacy_storefront_settings l WHERE l.id = s.id');

        DB::statement('ALTER TABLE shop_settings ALTER COLUMN storefront DROP DEFAULT');
        DB::statement('ALTER TABLE shop_settings ALTER COLUMN version DROP DEFAULT');
        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_storefront_object CHECK (jsonb_typeof(storefront) = 'object')");
        DB::statement('ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_version_nonnegative CHECK (version >= 0)');
        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_sections_object CHECK (sections IS NULL OR jsonb_typeof(sections) = 'object')");

        Schema::dropIfExists('legacy_storefront_settings');
    }
};
