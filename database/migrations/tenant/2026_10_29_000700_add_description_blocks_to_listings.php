<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商品の説明をブロックの並びで持つ（ADR 0032）。説明のブロック・仕様の表・一覧に出す短い説明の列を足す。
 *
 * - 今の説明（description、素の文字）は消さない（二段の移行。足してから、後で消す）。今の説明は 1 つの段落に写す
 * - 前のバージョンのアプリへ戻しても動くように、DB が両方向をつなぐ:
 *   今のアプリはブロックとブロックの文（description）を一緒に書く。前のアプリは description だけを書くので、
 *   ブロックが変わらずに description だけが変わったら、トリガーがブロックを「1 つの段落」に写し直す
 * - 形は DB でも守る（並びであること・個数の上限・短い説明は 1 行で 200 文字まで）。中の形はアプリ（ProductDescription）が確かめる
 * - 写す文の整え方（改行をそろえる・制御文字と文字の向きを変える印を外す・前後の空白を除く）はこの移行の中に書き写す（後でアプリが変わっても、この移行の意味を変えない）
 * - 説明に写真を使っているかは、写真を消すときに 1 枚分だけを索引（jsonb_path_ops の GIN）で調べる
 *
 * 戻すときは**イメージだけを前のバージョンに差し替え、down は流さない**。前のアプリは description だけを読むので、この形のまま動く。
 * down はブロックの構造・仕様の表・短い説明を消す（description に残るのは、ブロックの文をつないだ写しだけ）。down を流すのは、作った直後に取り消すときだけ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->jsonb('description_blocks')->default('[]');  // 説明のブロックの並び（段落・見出し・箇条書き・写真・表・注意書き）
            $table->jsonb('specs')->default('[]');               // 仕様の表（{label, value} の並び）
            $table->string('summary', 200)->nullable();          // 一覧に出す短い説明（1 行）
        });

        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_description_blocks_shape CHECK (jsonb_typeof(description_blocks) = 'array' AND jsonb_array_length(description_blocks) <= 100)");
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_specs_shape CHECK (jsonb_typeof(specs) = 'array' AND jsonb_array_length(specs) <= 30)");
        // 大きさの上限（個数の上限の中でも、巨大な値で DB と読み出しを重くさせない。アプリの上限の合計よりゆとりを持たせた 512KB）
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_description_blocks_size CHECK (octet_length(description_blocks::text) <= 524288)');
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_specs_size CHECK (octet_length(specs::text) <= 524288)');
        // 写真を消すとき、その写真を使う説明を 1 枚分だけ調べる（description_blocks @> '[{"type":"image","media_id":"…"}]'）
        DB::statement('CREATE INDEX listings_description_blocks_images ON listings USING gin (description_blocks jsonb_path_ops)');
        // 制御文字は DB のロケールに頼らず範囲で書く（C0・DEL・C1。アプリの \p{Cc} と同じ範囲）
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_summary_single_line CHECK (summary IS NULL OR (summary <> '' AND char_length(summary) <= 200 AND summary !~ '[\\x01-\\x1f\\x7f-\\x9f]'))");

        // 素の文を 1 つの段落のブロックにする（空なら空の並び）。改行を \n にそろえ、改行とタブ以外の制御文字と、
        // 文字の向きを変える印（U+202A〜202E・U+2066〜2069。Windows や Office から貼った文に付きうる。アプリは保存のときに断る）を外し、前後の空白を除く
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_listing_paragraph_blocks(body text) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE
                cleaned text;
            BEGIN
                IF body IS NULL THEN
                    RETURN '[]'::jsonb;
                END IF;

                cleaned := regexp_replace(body, E'\r\n?', E'\n', 'g');
                cleaned := regexp_replace(cleaned, E'[\\x01-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f-\\x9f]', '', 'g');
                cleaned := regexp_replace(cleaned, E'[\\u202a-\\u202e\\u2066-\\u2069]', '', 'g');
                cleaned := btrim(cleaned, E' \t\n　');

                IF cleaned = '' THEN
                    RETURN '[]'::jsonb;
                END IF;

                RETURN jsonb_build_array(jsonb_build_object('type', 'paragraph', 'text', left(cleaned, 5000)));
            END;
            $$;

            CREATE OR REPLACE FUNCTION sugar_listing_description_sync() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- 前のバージョンのアプリが説明の文だけを書いたとき（ブロックは変わっていない）、ブロックを写し直す
                IF TG_OP = 'INSERT' THEN
                    IF NEW.description_blocks = '[]'::jsonb THEN
                        NEW.description_blocks := sugar_listing_paragraph_blocks(NEW.description);
                    END IF;
                ELSIF NEW.description IS DISTINCT FROM OLD.description AND NEW.description_blocks = OLD.description_blocks THEN
                    NEW.description_blocks := sugar_listing_paragraph_blocks(NEW.description);
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);

        DB::statement('CREATE TRIGGER listings_description_sync BEFORE INSERT OR UPDATE OF description ON listings FOR EACH ROW EXECUTE FUNCTION sugar_listing_description_sync()');

        // 今の説明を段落に写す（まだブロックの無い行だけ。流し直しても同じ）
        DB::statement("UPDATE listings SET description_blocks = sugar_listing_paragraph_blocks(description) WHERE description_blocks = '[]'::jsonb AND description IS NOT NULL");
    }

    public function down(): void
    {
        // 説明の文（description）は残っている（今のアプリはブロックの文をここにも書く）ので、前の形のまま動く。
        // ただし構造・仕様の表・短い説明は消える。戻すときはイメージだけを差し替え、これは流さない（上の注）
        DB::statement('DROP INDEX IF EXISTS listings_description_blocks_images');
        DB::statement('DROP TRIGGER IF EXISTS listings_description_sync ON listings');
        DB::statement('DROP FUNCTION IF EXISTS sugar_listing_description_sync()');
        DB::statement('DROP FUNCTION IF EXISTS sugar_listing_paragraph_blocks(text)');

        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn(['description_blocks', 'specs', 'summary']); // 形の検査も一緒に消える
        });
    }
};
