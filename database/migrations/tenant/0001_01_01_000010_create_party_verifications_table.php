<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('method', 16);                    // ekyc | document | in_person
            $table->string('status', 16)->index();           // pending | verified | rejected | expired
            $table->text('document_ref')->nullable();        // 暗号化。S3 互換ストレージのオブジェクトキー
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('data')->nullable();               // 個人情報は入れない（事業者名、確認種別など）
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_verifications');
    }
};
