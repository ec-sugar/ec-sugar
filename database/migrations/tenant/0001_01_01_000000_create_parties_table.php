<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 当事者（テナント DB）。個人情報の列は暗号文（text）、検索用はブラインドインデックス（hex 64 桁）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 16)->index();            // customer | staff

            $table->text('name');                            // 暗号化
            $table->char('name_index', 64)->index();
            $table->text('email')->nullable();               // 暗号化
            $table->char('email_index', 64)->nullable()->index();
            $table->text('phone')->nullable();               // 暗号化
            $table->char('phone_index', 64)->nullable()->index();
            $table->text('address')->nullable();             // 暗号化 JSON
            $table->text('notes')->nullable();               // 暗号化

            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
