<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理画面のホームの「ようこそ」を閉じた印。運営ごと・店ごとに 1 行で、閉じた時刻だけを持つ。
 * 店の DB でなく中央に置く（運営は中央の人で、店の記録＝イベントの鎖に運営の画面の好みを混ぜない）。運営か店を消すと一緒に消える
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_welcomes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->timestampTz('seen_at');
            $table->unique(['operator_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_welcomes');
    }
};
