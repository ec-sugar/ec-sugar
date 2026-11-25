<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * テーマの区画の値（ADR 0028 区切り 2）。店 × テーマ × バージョンで持ち、各バージョンに下書き（draft）と公開（published）がある。
 * バージョンはテーマの値の名前つきの組（例「標準」「春のセール」）で、テーマごとに使うバージョン（current）が 1 つ。
 * 中身の形はテーマの宣言（theme.json）しだいなので、ここでは JSON の形と大きさだけを守る。中身は ThemeContentService が宣言どおりに確かめてから書く。
 * 書くのは ThemeContentService だけ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('theme', 32);                    // テーマの名前（ThemeRegistry と同じ形）
            $table->string('name', 40);                     // バージョンの名前（店の人が付ける）
            $table->boolean('current')->default(false);     // このテーマで使うバージョン
            $table->jsonb('draft');                         // 編集中の値（お客さんには出ない）
            $table->jsonb('published')->nullable();         // ショップに出す値（まだ公開していなければ null）
            $table->integer('revision')->default(0);        // 変えるたびに 1 つ進む（同時の編集で上書きしない）
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['theme', 'name']);
        });

        DB::statement("ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_theme_name CHECK (theme ~ '^[a-z][a-z0-9-]{1,31}$')");
        // 制御文字は DB のロケールに頼らず範囲で書く（C0・DEL・C1）
        DB::statement("ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_name_single_line CHECK (btrim(name) <> '' AND name !~ '[\\x01-\\x1f\\x7f-\\x9f]')");
        DB::statement("ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_draft_shape CHECK (jsonb_typeof(draft) = 'object' AND octet_length(draft::text) <= 524288)");
        DB::statement("ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_published_shape CHECK (published IS NULL OR (jsonb_typeof(published) = 'object' AND octet_length(published::text) <= 524288))");
        DB::statement('ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_published_at CHECK ((published IS NULL) = (published_at IS NULL))');
        DB::statement('ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_revision CHECK (revision >= 0)');
        // テーマごとに使うバージョンは 1 つだけ
        DB::statement('CREATE UNIQUE INDEX theme_versions_one_current ON theme_versions (theme) WHERE current');
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_versions');
    }
};
