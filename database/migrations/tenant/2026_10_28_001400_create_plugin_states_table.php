<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 店ごとのプラグインの使う・使わない（ADR 0025）。行が無いプラグインは使わない。
 * 止めても行は残す（もう一度使うときに同じ行を変える）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('plugin', 32)->unique();
            $table->boolean('enabled');
            $table->timestampTz('changed_at');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE plugin_states ADD CONSTRAINT plugin_states_name CHECK (plugin ~ '^[a-z][a-z0-9-]{1,31}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_states');
    }
};
