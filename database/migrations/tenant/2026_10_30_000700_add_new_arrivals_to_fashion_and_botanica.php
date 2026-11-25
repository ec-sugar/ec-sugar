<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 公式テーマ fashion・botanica を区画の並びの形に作り直した（ADR 0039 区切り 4）ので、保存してあるトップの並びに「新着の商品」の区画を足す。
 *
 * 前は、新着の商品はテーマのトップの画面が決まって描いていた（区画ではなかった）。並びの形では区画（new_arrivals）になり、
 * 並びに無い区画は描かれないので、足さないと今の店のトップから新着の商品が消える（今の見た目を変えない）。
 *
 * - 足すのは、fashion・botanica のバージョンの下書きと公開の、保存してあるトップ（pages.home が並び）だけ。
 *   まだ保存していないトップは、読むときにテーマの最初の並び（新着の商品を含む）になるので触らない。空の並び（区画を全部外したトップ）も触らない
 * - 足す場所はカテゴリの区画のすぐ後（今までのトップと同じ。fashion はヒーロー・カテゴリの後、botanica はヒーロー・右の特集・カテゴリの後）。
 *   カテゴリが無ければ最後。ID は種類の名前（最初の並びと今の値からの写しと同じ決まり）、パターンは grid（今までの見た目）、値は空（見出しは既定）
 * - もう新着の商品がある・ID が重なる・40 区画ある並びは触らない（何度流しても同じ）
 * - 下書きを変えたバージョンは改訂を 1 つ進める（開いている編集の画面が、古い並びで上書きしないように。下書きと公開に同じ区画を足すので「公開していない変更」の印は変わらない）
 * - 値の中身（jsonb）は DB の中で書き換える（PHP の配列を通すと、空の対応 {} が [] に変わるため）
 *
 * down は、この移行が足した区画（ID と種類が new_arrivals）を外す
 */
return new class extends Migration
{
    private const string SECTION = '{"id": "new_arrivals", "type": "new_arrivals", "pattern": "grid", "hidden": false, "values": {"heading": null}, "items": [], "blocks": []}';

    public function up(): void
    {
        DB::unprepared(<<<SQL
            CREATE FUNCTION pg_temp.sugar_add_new_arrivals(doc jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS \$\$
                SELECT CASE
                    WHEN doc IS NULL OR jsonb_typeof(doc #> '{pages,home}') IS DISTINCT FROM 'array' THEN doc
                    WHEN jsonb_array_length(doc #> '{pages,home}') NOT BETWEEN 1 AND 39 THEN doc
                    WHEN EXISTS (SELECT 1 FROM jsonb_array_elements(doc #> '{pages,home}') e WHERE e->>'type' = 'new_arrivals' OR e->>'id' = 'new_arrivals') THEN doc
                    ELSE jsonb_set(doc, '{pages,home}', (
                        SELECT jsonb_agg(x.value ORDER BY x.ord)
                        FROM (
                            SELECT e.value, e.ord::numeric AS ord FROM jsonb_array_elements(doc #> '{pages,home}') WITH ORDINALITY AS e(value, ord)
                            UNION ALL
                            SELECT '{$this->section()}'::jsonb, COALESCE(
                                (SELECT min(c.ord) FROM jsonb_array_elements(doc #> '{pages,home}') WITH ORDINALITY AS c(value, ord) WHERE c.value->>'type' = 'categories'),
                                jsonb_array_length(doc #> '{pages,home}')
                            ) + 0.5
                        ) x
                    ))
                END
            \$\$;
            SQL);

        $this->rewrite('pg_temp.sugar_add_new_arrivals');
        DB::unprepared('DROP FUNCTION pg_temp.sugar_add_new_arrivals(jsonb)');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION pg_temp.sugar_remove_new_arrivals(doc jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $$
                SELECT CASE
                    WHEN doc IS NULL OR jsonb_typeof(doc #> '{pages,home}') IS DISTINCT FROM 'array' THEN doc
                    WHEN NOT EXISTS (SELECT 1 FROM jsonb_array_elements(doc #> '{pages,home}') e WHERE e->>'type' = 'new_arrivals' AND e->>'id' = 'new_arrivals') THEN doc
                    ELSE jsonb_set(doc, '{pages,home}', (
                        SELECT COALESCE(jsonb_agg(e.value ORDER BY e.ord), '[]'::jsonb)
                        FROM jsonb_array_elements(doc #> '{pages,home}') WITH ORDINALITY AS e(value, ord)
                        WHERE NOT (e.value->>'type' = 'new_arrivals' AND e.value->>'id' = 'new_arrivals')
                    ))
                END
            $$;
            SQL);

        $this->rewrite('pg_temp.sugar_remove_new_arrivals');
        DB::unprepared('DROP FUNCTION pg_temp.sugar_remove_new_arrivals(jsonb)');
    }

    private function rewrite(string $function): void
    {
        DB::update(<<<SQL
            UPDATE theme_versions SET
                draft = {$function}(draft),
                published = {$function}(published),
                revision = revision + CASE WHEN {$function}(draft) IS DISTINCT FROM draft THEN 1 ELSE 0 END
            WHERE theme IN ('fashion', 'botanica')
                AND ({$function}(draft) IS DISTINCT FROM draft OR {$function}(published) IS DISTINCT FROM published)
            SQL);
    }

    private function section(): string
    {
        return self::SECTION;
    }
};
