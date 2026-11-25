<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * オプションに税区分を持たせる（リリース 1.0 の R6。PR 89 の見直しの残り）。
 * 空（null）は今までどおり商品の区分に従う。軽減の商品（食品）に別料金のギフト包装を付けるときは、包装は標準 10%（国税庁の案内）なので、店が標準を選べる
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_addons', function (Blueprint $table): void {
            $table->string('tax_class', 16)->nullable();
        });

        DB::statement("ALTER TABLE listing_addons ADD CONSTRAINT listing_addons_tax_class_known CHECK (tax_class IS NULL OR tax_class IN ('standard', 'reduced'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE listing_addons DROP CONSTRAINT IF EXISTS listing_addons_tax_class_known');

        Schema::table('listing_addons', function (Blueprint $table): void {
            $table->dropColumn('tax_class');
        });
    }
};
