<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * プライバシーポリシーの本文（ADR 0094）。ショップの /privacy に出す店の人が書いた素の文（見出しと箇条書きだけの短い書き方）。
 * 問い合わせ先に店主の名前・住所・電話が入りうるので、特商法の表記と同じくテナント鍵で暗号化して置く（ADR 0010）。
 * 平文を置かないことは DB でも守る（暗号文の頭 te1: だけを通す。TenantCipher::PREFIX）。空は null（ショップは「見つかりません」）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->text('privacy_policy')->nullable(); // 暗号化した文
        });

        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_privacy_policy_encrypted CHECK (privacy_policy IS NULL OR privacy_policy LIKE 'te1:%')");
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn('privacy_policy');
        });
    }
};
