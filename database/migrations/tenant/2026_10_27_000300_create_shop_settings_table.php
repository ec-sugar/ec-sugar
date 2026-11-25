<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 店の設定。店頭に出す文言（お知らせの帯・ヒーロー・安心の一行）。テナントごとに 1 行だけ。
 * 書くのは ShopSettingsService だけ（バージョンで同時の編集の衝突を見る）。個人情報は入れない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();                   // イベントの記録の対象の ID にも使う
            $table->jsonb('storefront');
            $table->integer('version');
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX shop_settings_single_row ON shop_settings ((true))'); // 1 行だけ
        DB::statement('ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_version_positive CHECK (version >= 1)');
        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_storefront_object CHECK (jsonb_typeof(storefront) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
