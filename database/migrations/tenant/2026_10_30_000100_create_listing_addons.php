<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商品のオプション（名入れ・ギフト包装など、在庫の無い追加。ADR 0036 の 8〜11）。
 *
 * - 形は 3 つ: choice（選ぶ。選択肢ごとに足す額）・text（入れる文字。最大の文字数と足す額）・toggle（付ける／付けない。足す額）
 * - カートの行は種類 × 選んだオプションで 1 行。選んだものは店の鍵で暗号化し（名入れは人の名前になりうる）、同じ選び方かは鍵つきの印（addons_key）で見る
 * - 取引の行では、オプションは商品の行に従う調整の行（parent_position で親の行を指す）。入れた文字は暗号化して addon_text に
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_addons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 16);
            $table->string('name', 40);
            $table->boolean('required')->default(false);
            $table->bigInteger('amount')->default(0);           // text・toggle の足す額
            $table->jsonb('choices')->nullable();               // choice: [{label, amount}]
            $table->unsignedSmallInteger('max_length')->nullable(); // text の最大の文字数
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['listing_id', 'position']);
        });
        DB::statement("ALTER TABLE listing_addons ADD CONSTRAINT listing_addons_kind_known CHECK (kind IN ('choice', 'text', 'toggle'))");
        DB::statement("ALTER TABLE listing_addons ADD CONSTRAINT listing_addons_name_present CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE listing_addons ADD CONSTRAINT listing_addons_amount_nonnegative CHECK (amount >= 0)');
        // NULL の比べは NULL になり CHECK を素通りするので、各形の判定を COALESCE で「合わない」に倒す
        DB::statement("ALTER TABLE listing_addons ADD CONSTRAINT listing_addons_shape CHECK (
            COALESCE(kind = 'choice' AND jsonb_typeof(choices) = 'array' AND jsonb_array_length(choices) BETWEEN 1 AND 20 AND max_length IS NULL AND amount = 0, false)
            OR COALESCE(kind = 'text' AND choices IS NULL AND max_length BETWEEN 1 AND 100, false)
            OR COALESCE(kind = 'toggle' AND choices IS NULL AND max_length IS NULL, false))");

        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->text('addons')->nullable();                 // 選んだオプション（暗号文）
            $table->char('addons_key', 64)->default('');        // 同じ選び方かを見る鍵つきの印。選んでいなければ空
        });
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'variant_id']);
            $table->unique(['cart_id', 'variant_id', 'addons_key']);
        });
        DB::statement("ALTER TABLE cart_lines ADD CONSTRAINT cart_lines_addons_key_format CHECK (addons_key = '' OR addons_key ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE cart_lines ADD CONSTRAINT cart_lines_addons_encrypted CHECK (addons IS NULL OR addons LIKE 'te1:%')");

        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->unsignedSmallInteger('parent_position')->nullable(); // オプションの行が従う商品の行
            $table->text('addon_text')->nullable();                       // 入れた文字（暗号文）
        });
        DB::statement("ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_addon_text_encrypted CHECK (addon_text IS NULL OR addon_text LIKE 'te1:%')");
        DB::statement("ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_addon_shape CHECK (
            (COALESCE(kind, '') = 'addon') = (parent_position IS NOT NULL) AND (addon_text IS NULL OR COALESCE(kind, '') = 'addon'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transaction_lines DROP CONSTRAINT IF EXISTS transaction_lines_addon_shape');
        DB::statement('ALTER TABLE transaction_lines DROP CONSTRAINT IF EXISTS transaction_lines_addon_text_encrypted');
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->dropColumn(['parent_position', 'addon_text']);
        });
        DB::statement('ALTER TABLE cart_lines DROP CONSTRAINT IF EXISTS cart_lines_addons_encrypted');
        DB::statement('ALTER TABLE cart_lines DROP CONSTRAINT IF EXISTS cart_lines_addons_key_format');
        DB::table('cart_lines')->where('addons_key', '<>', '')->delete();
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'variant_id', 'addons_key']);
            $table->unique(['cart_id', 'variant_id']);
            $table->dropColumn(['addons', 'addons_key']);
        });
        Schema::dropIfExists('listing_addons');
    }
};
