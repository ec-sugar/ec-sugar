<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 有料のコンテンツの「読める条件」と「ここから有料」（ADR 0072 に足した節）。
 *
 * - access_keys: 読める条件（鍵の一覧。`<種類>:<名前>`。核の ContentAccess が App に聞く形）。空なら誰でも読める記事
 * - paid_body: 「ここから有料」より後ろの本文のブロック。**無料の分（body）とは別の列**に持つ
 *   （権利の無い人には、組み立てる前に読まないので 1 バイトも出ない）
 *
 * DB でも守ること: 鍵の形と数（10 個まで）・有料の分の形と数・本文と有料の分を合わせて 100 ブロックまで・
 * **有料の分があるなら読める条件が必ずある**（条件の無い有料の分は誰にも出せず、ただ読めなくなるだけなので置かせない）。
 * 鍵の形は核の `ContentAccess::KEY` と同じ（CHECK では部分一致にしないよう前後を留める）
 */
return new class extends Migration
{
    /** 鍵の形（核の ContentAccess::KEY と同じ）。jsonpath の like_regex で 1 つずつ見る */
    private const string KEY = '^[a-z][a-z0-9-]{0,31}:[A-Za-z0-9][A-Za-z0-9._-]{0,63}$';

    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->jsonb('access_keys')->default('[]');
            $table->jsonb('paid_body')->default('[]');
        });

        DB::statement("ALTER TABLE blog_posts ADD CONSTRAINT blog_posts_paywall_check CHECK (
            jsonb_typeof(access_keys) = 'array'
            AND jsonb_array_length(access_keys) <= 10
            AND NOT jsonb_path_exists(access_keys, '$[*] ? (@.type() != \"string\" || !(@ like_regex \"".self::KEY."\"))')
            AND jsonb_typeof(paid_body) = 'array'
            AND jsonb_array_length(body) + jsonb_array_length(paid_body) <= 100
            AND (jsonb_array_length(paid_body) = 0 OR jsonb_array_length(access_keys) > 0)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE blog_posts DROP CONSTRAINT IF EXISTS blog_posts_paywall_check');
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropColumn(['access_keys', 'paid_body']);
        });
    }
};
