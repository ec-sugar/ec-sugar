<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決済代行から届いた通知（Webhook）。同じ通知の二度目は処理しない（代行の通知の ID で一意）。
 * 中身（お客さんのメールなどを含みうる）は残さず、種類と処理の結果だけ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider', 16);
            $table->string('event_id', 255);
            $table->string('type', 100);
            $table->string('outcome', 32); // paid | expired | ignored | unknown_session | mismatch | duplicate | late
            $table->timestampTz('created_at');
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
