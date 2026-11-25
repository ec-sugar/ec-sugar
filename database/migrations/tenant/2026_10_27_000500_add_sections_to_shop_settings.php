<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * トップの区画: カテゴリ・コーディネート・人気・季節の特集。機能ができるまでの仮の内容で、写真は商品の写真を ID で指す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->jsonb('sections')->nullable();
        });

        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_sections_object CHECK (sections IS NULL OR jsonb_typeof(sections) = 'object')");
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn('sections');
        });
    }
};
