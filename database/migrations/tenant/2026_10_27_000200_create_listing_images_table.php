<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商品の写真。ファイルは作り直した WebP（ImageProcessor）を保存場所 'media' に置き、ここには並び順と寸法だけを持つ。
 * 書くのは ListingImageService だけ（並び順と枚数の上限は、商品の行のロックの中で守る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->smallInteger('position');
            $table->string('alt', 200)->nullable();          // 代わりの文字（写真の説明）。個人情報は入れない
            $table->integer('width');                         // 大きい方（large）の寸法
            $table->integer('height');
            $table->char('checksum', 64);                     // large の中身の SHA-256（配るときの ETag）
            $table->timestampsTz();

            $table->index(['listing_id', 'position']);
        });

        DB::statement('ALTER TABLE listing_images ADD CONSTRAINT listing_images_position_nonnegative CHECK (position >= 0)');
        DB::statement('ALTER TABLE listing_images ADD CONSTRAINT listing_images_size_positive CHECK (width > 0 AND height > 0)');
        DB::statement("ALTER TABLE listing_images ADD CONSTRAINT listing_images_checksum_hex CHECK (checksum ~ '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_images');
    }
};
