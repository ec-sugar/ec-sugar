<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');

            // 運用モード（docs/CONCEPT.md 4 章）
            $table->string('storefront_scope', 16)->default('own');   // own | shared
            $table->string('seller_model', 16)->default('single');    // single | multi
            $table->string('settlement', 16)->default('direct');      // direct | platform

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
