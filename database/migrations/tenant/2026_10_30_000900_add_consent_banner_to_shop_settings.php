<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 解析・広告のタグの同意の帯を出すか（ADR 0034 の 4 の置き換え）。既定は出さない。
 * 出さない店では、タグは最初から読み込み、外部送信の公表のページだけを置く（日本の外部送信規律が求めるのは公表）。
 * 出す店（海外のお客さんを相手にする店）だけ、今までどおり受け入れた人にだけタグを読み込む。
 * 今ある店も「出さない」になる（今までは帯が出ていた。動きが変わることは ADR 0034 に書いた）。
 * 足すだけなので、古いイメージでもそのまま動く（古いイメージは列を読まず、今までどおり帯を出す）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE shop_settings ADD COLUMN consent_banner boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_settings DROP COLUMN consent_banner');
    }
};
