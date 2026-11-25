<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 店ごとの App の設定の値（ADR 0034 の 3）。App 1 つに 1 行。変えるのは PluginSettings だけ（イベントと同じトランザクション）。
 * 宣言で秘密とした項目は secrets に店の鍵で暗号化して置く（ADR 0010 の考え方）。values には秘密でない項目だけ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('plugin', 32)->unique();
            $table->jsonb('values');
            $table->text('secrets')->nullable();
            $table->timestampTz('changed_at');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE plugin_settings ADD CONSTRAINT plugin_settings_name CHECK (plugin ~ '^[a-z][a-z0-9-]{1,31}$')");
        // 値は項目の名前をキーにした形だけ（並びや文字を入れない）。秘密は暗号文だけ（平文を入れない）
        DB::statement("ALTER TABLE plugin_settings ADD CONSTRAINT plugin_settings_values_object CHECK (jsonb_typeof(\"values\") = 'object')");
        DB::statement("ALTER TABLE plugin_settings ADD CONSTRAINT plugin_settings_secrets_sealed CHECK (secrets IS NULL OR secrets LIKE 'te1:%')");
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_settings');
    }
};
