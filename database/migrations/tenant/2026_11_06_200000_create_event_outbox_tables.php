<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 記録（domain_events）を「Transactional Outbox」として使うための表（ADR 0089）。
 *
 * - event_receiver_positions: 受け手ごとに「どこまで流したか」（記録の番号）。届けた・届けなくてよかった・失敗して下の表へ移した記録の番号まで進む
 * - event_retries: 届け直す記録（失敗した記録と、同じ集約の前の記録が失敗している間に来た記録）と、何度も失敗して止めた記録。
 *   中身は受け手の名前・記録の番号・集約・回数・例外のクラスの名前だけ（例外の文は個人情報を含むことがあるので残さない）
 *
 * どちらも状態ではなく、お金と在庫に触れない「流したか」の印（書き方の決まり 3 の例外。連鎖のロックとイベントを使わない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_receiver_positions', function (Blueprint $table): void {
            $table->string('receiver', 64)->primary();
            $table->bigInteger('position');
            $table->timestampTz('updated_at');
        });

        DB::statement('ALTER TABLE event_receiver_positions ADD CONSTRAINT event_receiver_positions_position CHECK (position >= 0)');

        Schema::create('event_retries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('receiver', 64);
            $table->bigInteger('sequence');
            $table->string('aggregate_type', 32);
            $table->ulid('aggregate_id');
            $table->string('status', 16);
            $table->smallInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestampTz('next_at');
            $table->timestampsTz();

            $table->unique(['receiver', 'sequence']);
            $table->index(['status', 'sequence']);
            $table->index(['receiver', 'aggregate_type', 'aggregate_id']);
            $table->foreign('sequence')->references('sequence')->on('domain_events');
        });

        DB::statement("ALTER TABLE event_retries ADD CONSTRAINT event_retries_status CHECK (status IN ('waiting', 'stopped'))");
        DB::statement('ALTER TABLE event_retries ADD CONSTRAINT event_retries_attempts CHECK (attempts BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_retries');
        Schema::dropIfExists('event_receiver_positions');
    }
};
