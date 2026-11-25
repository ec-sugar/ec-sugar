<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * メールでの戻しの申し込み（ADR 0038 の区切り 2）。メールのリンクを開くと 24 時間の待ちに入り、待った後に同じブラウザで新しいパスキーを足す。
 * リンクとブラウザの印は保存するのはハッシュだけ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_recovery_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->char('link_hash', 64)->unique();
            $table->timestampTz('link_expires_at');
            $table->char('browser_hash', 64)->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancelled_by', 32)->nullable();
            $table->timestampTz('created_at');
            $table->index(['operator_id', 'used_at', 'cancelled_at']);
        });
        DB::statement("ALTER TABLE operator_recovery_requests ADD CONSTRAINT operator_recovery_requests_hashes CHECK (link_hash ~ '^[0-9a-f]{64}$' AND (browser_hash IS NULL OR browser_hash ~ '^[0-9a-f]{64}$'))");
        // 待ちに入ったら印と開ける時がそろう。使ったものと取り消したものは両方にはならない
        DB::statement('ALTER TABLE operator_recovery_requests ADD CONSTRAINT operator_recovery_requests_waiting CHECK ((browser_hash IS NULL) = (ready_at IS NULL) AND NOT (used_at IS NOT NULL AND cancelled_at IS NOT NULL) AND (used_at IS NULL OR ready_at IS NOT NULL))');
        // 24 時間の待ちを DB でも守る（開けるのは申し込みから 24 時間より後、使うのは開ける時より後）
        DB::statement("ALTER TABLE operator_recovery_requests ADD CONSTRAINT operator_recovery_requests_wait_a_day CHECK ((ready_at IS NULL OR ready_at >= created_at + interval '24 hours') AND (used_at IS NULL OR used_at >= ready_at))");
        DB::statement("ALTER TABLE operator_recovery_requests ADD CONSTRAINT operator_recovery_requests_cancelled CHECK ((cancelled_at IS NULL) = (cancelled_by IS NULL) AND (cancelled_by IS NULL OR cancelled_by IN ('superseded', 'login', 'confirmed', 'self', 'disabled', 'revoked', 'expired')))");
        DB::statement('CREATE UNIQUE INDEX operator_recovery_requests_browser ON operator_recovery_requests (browser_hash) WHERE browser_hash IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_recovery_requests');
    }
};
