<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 管理画面で商品と在庫を触る前に、規則を DB でも守る。アプリの検査をすり抜けても DB が拒む。
 *
 * - listings.version: 楽観ロックのバージョン番号。更新のたびに 1 増やし、画面が読んだバージョンと違えば保存を拒む
 * - 金額は 0 以上、通貨は 3 文字の大文字、状態は既知の値だけ
 * - 在庫は負にならず、確保数は在庫数を超えない
 * - 在庫・個体在庫の商品と拠点は同じ出品者のもの（別の出品者の倉庫に在庫を置けない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->integer('version')->default(1);
        });

        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_version_positive CHECK (version >= 1)');
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_unit_amount_nonnegative CHECK (unit_amount IS NULL OR unit_amount >= 0)');
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_currency_format CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_status_known CHECK (status IN ('draft', 'active', 'archived'))");

        DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT inventory_stocks_quantities CHECK (quantity >= 0 AND reserved >= 0 AND reserved <= quantity)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_inventory_same_seller() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.listing_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM listings l JOIN locations o ON o.seller_id = l.seller_id
                    WHERE l.id = NEW.listing_id AND o.id = NEW.location_id
                ) THEN
                    RAISE EXCEPTION '商品 % と拠点 % の出品者が違います', NEW.listing_id, NEW.location_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);

        foreach (['inventory_stocks', 'inventory_units'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_same_seller BEFORE INSERT OR UPDATE OF listing_id, location_id ON {$name} FOR EACH ROW EXECUTE FUNCTION sugar_inventory_same_seller()");
        }
    }

    public function down(): void
    {
        foreach (['inventory_stocks', 'inventory_units'] as $name) {
            DB::statement("DROP TRIGGER IF EXISTS {$name}_same_seller ON {$name}");
        }

        DB::statement('DROP FUNCTION IF EXISTS sugar_inventory_same_seller()');
        DB::statement('ALTER TABLE inventory_stocks DROP CONSTRAINT IF EXISTS inventory_stocks_quantities');

        foreach (['listings_status_known', 'listings_currency_format', 'listings_unit_amount_nonnegative', 'listings_version_positive'] as $constraint) {
            DB::statement("ALTER TABLE listings DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
