<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 運営の役割と、店ごとの権限（ADR 0024）。いた運営はオーナー（これまでどおり全店）、これから作る運営はスタッフ（権限を与えるまでどの店にも入れない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->string('role', 16)->default('staff');
        });
        DB::table('operators')->update(['role' => 'owner']);
        DB::statement("ALTER TABLE operators ADD CONSTRAINT operators_role CHECK (role IN ('owner', 'staff'))");

        Schema::create('operator_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('level', 16); // read | operate | money
            $table->timestampsTz();
            $table->unique(['operator_id', 'tenant_id']);
        });
        DB::statement("ALTER TABLE operator_grants ADD CONSTRAINT operator_grants_level CHECK (level IN ('read', 'operate', 'money'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_grants');
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
