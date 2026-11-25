<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * テーマのバージョンの見た目の値（下書きと公開の style。ADR 0097）の形を DB でも守る。
 *
 * style は { palette, accent, font, radius } だけ。名前は英小文字で始まる英小文字・数字・- の 32 文字まで、主な色は #rrggbb（小文字）か null。
 * アプリの確かめ（ThemeStyle::validate）をすり抜けても、CSS に出す値の形をここで止める（ショップの CSS に店の人の文字を入れない）。
 * style の無い値（今までの値）はそのまま通る
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_theme_style_ok(style jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                SELECT style IS NULL OR (
                    jsonb_typeof(style) = 'object'
                    AND NOT EXISTS (SELECT 1 FROM jsonb_object_keys(style) k WHERE k NOT IN ('palette', 'accent', 'font', 'radius'))
                    AND jsonb_typeof(style->'palette') = 'string' AND (style->>'palette') ~ '^[a-z][a-z0-9-]{0,31}$'
                    AND (style->'accent' IS NULL OR jsonb_typeof(style->'accent') = 'null'
                        OR (jsonb_typeof(style->'accent') = 'string' AND (style->>'accent') ~ '^#[0-9a-f]{6}$'))
                    AND (style->'font' IS NULL OR jsonb_typeof(style->'font') = 'null'
                        OR (jsonb_typeof(style->'font') = 'string' AND (style->>'font') ~ '^[a-z][a-z0-9-]{0,31}$'))
                    AND (style->'radius' IS NULL OR jsonb_typeof(style->'radius') = 'null'
                        OR (jsonb_typeof(style->'radius') = 'string' AND (style->>'radius') ~ '^[a-z][a-z0-9-]{0,31}$'))
                )
            $$;
            SQL);

        DB::statement("ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_draft_style CHECK (sugar_theme_style_ok(draft->'style'))");
        DB::statement("ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_published_style CHECK (published IS NULL OR sugar_theme_style_ok(published->'style'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE theme_versions DROP CONSTRAINT theme_versions_draft_style');
        DB::statement('ALTER TABLE theme_versions DROP CONSTRAINT theme_versions_published_style');
        DB::statement('DROP FUNCTION IF EXISTS sugar_theme_style_ok(jsonb)');
    }
};
