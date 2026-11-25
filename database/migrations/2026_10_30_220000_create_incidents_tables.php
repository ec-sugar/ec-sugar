<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 壊れたら知らせる仕組み（ADR 0043）。中央に置くのは「どこで・何の種類が・何回」だけ。
 * 例外のメッセージ・スタックの全体・リクエストの中身・URL・利用者は置かない（エラーの文には個人情報が混ざりうる。ADR 0010）。
 * 書くのは Incidents だけ（指紋の行に回数を足す 1 文）、知らせた印を付けるのは operations:alert だけ
 */
return new class extends Migration
{
    public function up(): void
    {
        // 起きたエラー（指紋ごとに 1 行）。指紋は 種類・例外のクラス・場所・店 の SHA-256
        Schema::create('incidents', function (Blueprint $table): void {
            $table->char('fingerprint', 64)->primary();
            $table->string('kind', 16);                          // http_error | job_failed | command_failed
            $table->string('exception', 255);                    // 例外のクラス（名前空間つき）
            $table->string('location', 255);                     // アプリの中のいちばん深い場所（相対パス:行）・裏の仕事の名前・定期の処理のコマンド
            // 店の文脈の中で起きた時の店（無ければ空）。外部キーは付けない（記録の 1 文が、店の行の鍵を待って止まらないように。消えた店は名前の無い ID で出る）
            $table->string('tenant_id')->nullable();
            $table->bigInteger('count');
            $table->timestampTz('first_at');
            $table->timestampTz('last_at');
            $table->timestampTz('notified_at')->nullable();      // 最後にオーナーへ知らせた時刻
            $table->bigInteger('notified_count')->nullable();    // その時の回数（これより増えていれば、1 時間を過ぎて知らせ直す）
            $table->index('last_at');
        });

        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_kind CHECK (kind IN ('http_error', 'job_failed', 'command_failed'))");
        DB::statement('ALTER TABLE incidents ADD CONSTRAINT incidents_count CHECK (count >= 1 AND (notified_count IS NULL OR (notified_count >= 1 AND notified_count <= count)))');
        DB::statement('ALTER TABLE incidents ADD CONSTRAINT incidents_notified CHECK ((notified_at IS NULL) = (notified_count IS NULL))');
        DB::statement('ALTER TABLE incidents ADD CONSTRAINT incidents_times CHECK (first_at <= last_at)');

        // 知らせた困りごと（運営の様子の要対応。ADR 0042 の 3）。出ている間は 1 回だけ知らせ、消えたら行を消す（出直したらまた知らせる）
        Schema::create('alerted_issues', function (Blueprint $table): void {
            $table->string('signature', 128)->primary();         // 困りごとの種類と店（例 stray_payments:shop1、店に依らないものは failed_jobs:）
            $table->timestampTz('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerted_issues');
        Schema::dropIfExists('incidents');
    }
};
