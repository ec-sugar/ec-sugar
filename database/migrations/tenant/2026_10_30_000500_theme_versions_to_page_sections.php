<?php

declare(strict_types=1);

use App\Core\Theme\ThemeRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * テーマの区画の値を、ページごとの区画の並びの形にする（ADR 0039 区切り 1）。
 *
 * 前: { sections: { 区画: { hidden, values, items } } }
 * 後: { pages: { home: [ { id, type, pattern, hidden, values, items, blocks } ] } }
 *
 * - 今の値はトップ（home）の並びに写す。ID は区画の名前（下書きと公開で同じ ID になり、「公開していない変更」が出ない）、
 *   パターンは default（今の書き方のテーマは、どの区画もパターンが default の 1 つ）、ブロックは無し。中の値はそのまま（消さない）
 * - 並びの順は、入っているテーマの区画の宣言の順（今のアプリが書く順と同じ。テーマが入っていなければ jsonb のキーの順）。
 *   決まった並びのテーマは読むときにも宣言の順に並べ直すので、どちらでも見た目は変わらない
 * - 写す決まりはこの移行の中に書き写す（後でアプリが変わっても、この移行の意味を変えない）
 * - 形は DB でも守る（sugar_theme_document_ok）: pages は名前 => 並び、1 ページ 40 区画まで、区画は ID・種類・パターンの文字を持ち、
 *   ID はページの中で重ならない、繰り返しは 12 個・ブロックは 20 個まで、全体は 512KB まで。前の形（sections）は受け付けない
 *
 * down は前の形に戻す（トップの種類ごとの最初の区画だけ。同じ種類の 2 つ目からと、ブロック・パターンは失われる）。流すのは作った直後に取り消すときだけ
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE theme_versions DROP CONSTRAINT theme_versions_draft_shape');
        DB::statement('ALTER TABLE theme_versions DROP CONSTRAINT theme_versions_published_shape');

        foreach (DB::table('theme_versions')->orderBy('id')->get(['id', 'theme', 'draft', 'published']) as $row) {
            $order = self::declaredOrder((string) $row->theme);
            DB::table('theme_versions')->where('id', $row->id)->update([
                'draft' => self::encode(self::toPages(self::decode($row->draft), $order)),
                'published' => $row->published === null ? null : self::encode(self::toPages(self::decode($row->published), $order)),
            ]);
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_theme_document_ok(doc jsonb) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE
                page record;
                section jsonb;
            BEGIN
                IF jsonb_typeof(doc) <> 'object' OR octet_length(doc::text) > 524288 OR doc ? 'sections' THEN
                    RETURN false;
                END IF;

                IF NOT doc ? 'pages' THEN
                    RETURN true;
                END IF;

                IF jsonb_typeof(doc->'pages') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(doc->'pages')) > 100 THEN
                    RETURN false;
                END IF;

                FOR page IN SELECT key, value FROM jsonb_each(doc->'pages') LOOP
                    IF page.key !~ '^[a-z][a-z0-9_-]{0,31}$' OR jsonb_typeof(page.value) <> 'array' OR jsonb_array_length(page.value) > 40 THEN
                        RETURN false;
                    END IF;

                    FOR section IN SELECT value FROM jsonb_array_elements(page.value) LOOP
                        IF jsonb_typeof(section) <> 'object'
                            OR jsonb_typeof(section->'id') IS DISTINCT FROM 'string' OR (section->>'id') !~ '^[a-z0-9][a-z0-9_-]{0,39}$'
                            OR jsonb_typeof(section->'type') IS DISTINCT FROM 'string' OR (section->>'type') !~ '^[a-z][a-z0-9_]{0,31}$'
                            OR jsonb_typeof(section->'pattern') IS DISTINCT FROM 'string' OR (section->>'pattern') !~ '^[a-z][a-z0-9_]{0,31}$'
                            OR (section ? 'items' AND (jsonb_typeof(section->'items') <> 'array' OR jsonb_array_length(section->'items') > 12))
                            OR (section ? 'blocks' AND (jsonb_typeof(section->'blocks') <> 'array' OR jsonb_array_length(section->'blocks') > 20)) THEN
                            RETURN false;
                        END IF;
                    END LOOP;

                    -- 区画の ID はページの中で重ならない
                    IF (SELECT count(DISTINCT e->>'id') FROM jsonb_array_elements(page.value) e) <> jsonb_array_length(page.value) THEN
                        RETURN false;
                    END IF;
                END LOOP;

                RETURN true;
            END
            $$;
            SQL);

        DB::statement('ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_draft_shape CHECK (sugar_theme_document_ok(draft))');
        DB::statement('ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_published_shape CHECK (published IS NULL OR sugar_theme_document_ok(published))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE theme_versions DROP CONSTRAINT theme_versions_draft_shape');
        DB::statement('ALTER TABLE theme_versions DROP CONSTRAINT theme_versions_published_shape');
        DB::statement('DROP FUNCTION IF EXISTS sugar_theme_document_ok(jsonb)');

        foreach (DB::table('theme_versions')->orderBy('id')->get(['id', 'draft', 'published']) as $row) {
            DB::table('theme_versions')->where('id', $row->id)->update([
                'draft' => self::encode(self::toSections(self::decode($row->draft))),
                'published' => $row->published === null ? null : self::encode(self::toSections(self::decode($row->published))),
            ]);
        }

        DB::statement("ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_draft_shape CHECK (jsonb_typeof(draft) = 'object' AND octet_length(draft::text) <= 524288)");
        DB::statement("ALTER TABLE theme_versions ADD CONSTRAINT theme_versions_published_shape CHECK (published IS NULL OR (jsonb_typeof(published) = 'object' AND octet_length(published::text) <= 524288))");
    }

    /**
     * 入っているテーマの区画の宣言の順（無ければ空。jsonb のキーの順のまま写す）。
     * 並びはこの順で持つ（テーマを並びのある書き方に作り直したとき、今の見た目の順で始まるように）。読むだけで、テーマの宣言は変えない
     *
     * @return list<string>
     */
    private static function declaredOrder(string $theme): array
    {
        try {
            $themes = app(ThemeRegistry::class);

            return $themes->has($theme) ? array_keys($themes->get($theme)->schema()->sections) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $order  区画の宣言の順
     * @return array<string, mixed>
     */
    private static function toPages(array $document, array $order): array
    {
        if (! array_key_exists('sections', $document)) {
            return $document;
        }

        $sections = is_array($document['sections']) ? $document['sections'] : [];
        // 宣言の順に並べ、宣言に無い区画は後ろに（jsonb の順）
        $sections = array_merge(array_intersect_key(array_fill_keys($order, null), $sections), $sections);

        $home = [];
        foreach ($sections as $key => $section) {
            // 区画の名前の形（英小文字で始まる 32 文字まで）でないものは、今のアプリも読まない
            if (! is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $key) !== 1 || ! is_array($section)) {
                continue;
            }

            $home[] = ['id' => $key, 'type' => $key, 'pattern' => 'default']
                + array_intersect_key($section, ['hidden' => true, 'values' => true, 'items' => true])
                + ['blocks' => []];
        }

        unset($document['sections']);

        // 何も入れていなかった値は、トップを保存していない形（読むとテーマの最初の並び）にする。空の並び（区画を全部外したページ）にはしない
        return $document + ['pages' => $home === [] ? (object) [] : ['home' => $home]];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function toSections(array $document): array
    {
        $sections = [];

        foreach ((array) ($document['pages']['home'] ?? []) as $section) {
            if (is_array($section) && is_string($section['type'] ?? null)) {
                $sections[$section['type']] ??= array_intersect_key($section, ['hidden' => true, 'values' => true, 'items' => true]);
            }
        }

        unset($document['pages']);

        return $document + ['sections' => (object) $sections];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function encode(array $document): string
    {
        // 空の対応は {} のまま（[] にすると形の検査に掛かる）
        return (string) json_encode($document === [] ? (object) [] : $document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
