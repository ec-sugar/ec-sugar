<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ショップの公開の状態とパスワード。
 *   storefront_status:              preparing（準備中）| open（公開）。列の既定は準備中（新しく作った店）
 *   storefront_password_hash:       準備中に中を見るためのパスワードのハッシュ（平文は持たない）。null は「パスワードなし」（誰も入れない）
 *   storefront_password_generation: パスワードを変えるたびに 1 つ進める。入れた人の Cookie はこの値を持ち、違えば無効（前の Cookie をすべて切る）
 *
 * 既にある店は公開にする（今の店が急に見えなくならないように）: 列を足すときは「公開」で埋めてから、既定を準備中に変える。
 * 設定の行がまだ無い店は、中身（商品・イベント）があれば「公開」の行を作る。中身の無い店（新しく作っている途中の店も同じ）は作らず、
 * 店を作るときのシーダー（TenantSeeder）が準備中の行を作る。行が無いときはショップは準備中として扱う（閉じる側に倒す）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE shop_settings ADD COLUMN storefront_status text NOT NULL DEFAULT 'open'");
        DB::statement("ALTER TABLE shop_settings ALTER COLUMN storefront_status SET DEFAULT 'preparing'");
        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_storefront_status_known CHECK (storefront_status IN ('preparing', 'open'))");
        DB::statement('ALTER TABLE shop_settings ADD COLUMN storefront_password_hash text NULL');
        DB::statement('ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_storefront_password_hash_length CHECK (storefront_password_hash IS NULL OR length(storefront_password_hash) BETWEEN 20 AND 255)');
        DB::statement('ALTER TABLE shop_settings ADD COLUMN storefront_password_generation integer NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_storefront_password_generation_nonnegative CHECK (storefront_password_generation >= 0)');

        $existingShop = DB::table('domain_events')->exists() || DB::table('listings')->exists();

        if ($existingShop && ! DB::table('shop_settings')->exists()) {
            DB::table('shop_settings')->insert(['id' => strtolower((string) Str::ulid()), 'storefront_status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_settings DROP COLUMN storefront_password_generation');
        DB::statement('ALTER TABLE shop_settings DROP COLUMN storefront_password_hash');
        DB::statement('ALTER TABLE shop_settings DROP COLUMN storefront_status');
    }
};
