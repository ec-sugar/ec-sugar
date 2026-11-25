<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->string('status', 16)->default('active')->index();
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
