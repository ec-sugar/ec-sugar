<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ホスト（サーバーの外）から見た状態（ADR 0043）。ホストの ec-sugar がコンテナの中の
 * `php artisan operations:host-report` を呼んで、決まった項目だけを置き換えで書く（HTTP の入口は無い）。
 * 置くのはホスト名・日時・割合・バイト数・決まった語・コミットのハッシュだけ。証明書の中身・鍵の場所・自由な文は置かない
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_checks', function (Blueprint $table): void {
            $table->string('kind', 16);                          // certificate | disk | update | backup
            $table->string('name', 253)->default('');            // 証明書はホスト名、空き容量は置き場（data | backup）、更新とバックアップは空
            $table->string('result', 16)->nullable();            // 更新: ok | rolled_back | failed | stopped | manual_rollback／バックアップ: ok | failed／証明書: unreadable（読めなかった）
            $table->timestampTz('at')->nullable();               // 証明書は期限、更新とバックアップは最後の結果の時刻
            $table->timestampTz('checked_at')->nullable();       // 更新だけ: 最後に取得元（リモート）へ届いた時刻
            $table->char('version', 40)->nullable();             // 更新だけ: 入れようとしたバージョン（コミットのハッシュ）
            $table->smallInteger('free_percent')->nullable();    // 空き容量だけ: 空きの割合（0〜100）
            $table->bigInteger('free_bytes')->nullable();        // 空き容量だけ: 空きのバイト数
            $table->timestampTz('reported_at');                  // ホストが報告した時刻（1 回の報告の行はみな同じ）
            $table->primary(['kind', 'name']);
        });

        // 形は DB でも守る（コマンドの確かめをすり抜けても入らない）
        DB::statement("ALTER TABLE host_checks ADD CONSTRAINT host_checks_kind CHECK (kind IN ('certificate', 'disk', 'update', 'backup'))");
        DB::statement(<<<'SQL'
            ALTER TABLE host_checks ADD CONSTRAINT host_checks_shape CHECK (
                (kind = 'certificate' AND name ~ '^[a-z0-9.-]{1,253}$' AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL
                    AND ((result IS NULL AND at IS NOT NULL) OR (result = 'unreadable' AND at IS NULL)))
                OR (kind = 'disk' AND name IN ('data', 'backup') AND result IS NULL AND at IS NULL AND checked_at IS NULL AND version IS NULL
                    AND free_percent BETWEEN 0 AND 100 AND free_bytes >= 0)
                OR (kind = 'update' AND name = '' AND free_percent IS NULL AND free_bytes IS NULL
                    AND (result IS NULL OR result IN ('ok', 'rolled_back', 'failed', 'stopped', 'manual_rollback'))
                    AND (result IS NULL) = (at IS NULL) AND (result IS NOT NULL OR checked_at IS NOT NULL)
                    AND (version IS NULL OR (result IS NOT NULL AND version ~ '^[0-9a-f]{40}$')))
                OR (kind = 'backup' AND name = '' AND result IN ('ok', 'failed') AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('host_checks');
    }
};
