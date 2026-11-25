<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 店のヒーローの写真。ファイルは作り直した WebP を保存場所 'media' に置き、ここには ID・寸法・中身の指紋だけ。
 * 写真だけを置いた店は、文言をまだ保存していない（バージョン 0）ので、バージョンの下限を 0 にする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->jsonb('hero_image')->nullable(); // {id, width, height, checksum}
        });

        DB::statement('ALTER TABLE shop_settings DROP CONSTRAINT shop_settings_version_positive');
        DB::statement('ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_version_nonnegative CHECK (version >= 0)');
        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_hero_image_shape CHECK (hero_image IS NULL OR (jsonb_typeof(hero_image) = 'object' AND (hero_image->>'id') IS NOT NULL AND (hero_image->>'checksum') ~ '^[0-9a-f]{64}$'))");
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn('hero_image');
        });
        DB::statement('ALTER TABLE shop_settings DROP CONSTRAINT shop_settings_version_nonnegative');
        DB::statement('ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_version_positive CHECK (version >= 1)');
    }
};
