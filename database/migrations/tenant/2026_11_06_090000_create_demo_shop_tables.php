<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * デモの店（ADR 0086）。どの店にも表はできるが、行が入るのはデモの印のある店だけ。
 *
 * - demo_mails: デモの店で送らずに止めたお客さんへのメール。来訪者ごとの受け箱（mailbox。来訪者だけが持つ合い言葉から作った 24 文字）で分け、
 *   その来訪者のセッションからだけ見せる。件名と本文は店の鍵で暗号化する（名前や届け先が入る）。状態でもお金でもなく、イベントは残さない
 * - demo_steps: デモの店を作るスクリプトが済ませた手順の印（流し直しで二重に作らない）。`built:<店の種類>` があることが、毎晩の戻しの条件の 1 つ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_mails', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('mailbox', 24);
            $table->text('subject');
            $table->text('body');
            $table->timestampTz('created_at');

            $table->index(['mailbox', 'created_at']);
        });

        DB::statement("ALTER TABLE demo_mails ADD CONSTRAINT demo_mails_mailbox_format CHECK (mailbox ~ '^[0-9a-f]{24}$')");

        Schema::create('demo_steps', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 64)->unique();
            $table->timestampTz('created_at');
        });

        DB::statement("ALTER TABLE demo_steps ADD CONSTRAINT demo_steps_key_format CHECK (key ~ '^[a-z][a-z0-9_]*:[a-z0-9-]{1,48}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_steps');
        Schema::dropIfExists('demo_mails');
    }
};
