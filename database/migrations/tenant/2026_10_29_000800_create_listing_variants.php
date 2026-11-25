<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 商品の種類（バリエーション。ADR 0036）。
 *
 * - どの商品も種類を 1 つ以上持つ。今の商品には軸の無い種類を 1 つずつ作り、品番を移す
 * - 選ぶ軸は 3 つまで（listing_options）、軸ごとの値（listing_option_values）。値の組み合わせが 1 つの種類
 * - 在庫・カートの行・取引の行は (variant_id, listing_id) で種類を指す。別の商品の種類は DB が断る
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_options', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');   // 1〜3。種類の value_1〜value_3 に対応する
            $table->string('name', 40);
            $table->timestampsTz();
            $table->unique(['listing_id', 'position']);
            $table->unique(['listing_id', 'name']);
            $table->unique(['id', 'listing_id']);
        });
        DB::statement('ALTER TABLE listing_options ADD CONSTRAINT listing_options_position_range CHECK (position BETWEEN 1 AND 3)');
        DB::statement("ALTER TABLE listing_options ADD CONSTRAINT listing_options_name_present CHECK (btrim(name) <> '')");

        Schema::create('listing_option_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('option_id')->constrained('listing_options')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label', 40);
            $table->timestampsTz();
            $table->unique(['option_id', 'label']);
            $table->unique(['option_id', 'position']);
        });
        DB::statement("ALTER TABLE listing_option_values ADD CONSTRAINT listing_option_values_label_present CHECK (btrim(label) <> '')");

        Schema::create('listing_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->ulid('seller_id');                          // 品番の重なりを出品者ごとに見るため。商品から DB が写す
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('sku', 64)->nullable();
            $table->bigInteger('unit_amount')->nullable();      // 空なら商品の価格
            $table->foreignUlid('value_1')->nullable()->constrained('listing_option_values')->restrictOnDelete();
            $table->foreignUlid('value_2')->nullable()->constrained('listing_option_values')->restrictOnDelete();
            $table->foreignUlid('value_3')->nullable()->constrained('listing_option_values')->restrictOnDelete();
            $table->foreignUlid('image_id')->nullable()->constrained('listing_images')->nullOnDelete();
            $table->string('status', 16)->default('active');    // active | stopped
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['id', 'listing_id']);
            $table->index(['listing_id', 'position']);
        });
        DB::statement('ALTER TABLE listing_variants ADD CONSTRAINT listing_variants_unit_amount_nonnegative CHECK (unit_amount IS NULL OR unit_amount >= 0)');
        DB::statement("ALTER TABLE listing_variants ADD CONSTRAINT listing_variants_status_known CHECK (status IN ('active', 'stopped'))");
        DB::statement('ALTER TABLE listing_variants ADD CONSTRAINT listing_variants_version_positive CHECK (version >= 1)');
        DB::statement('ALTER TABLE listing_variants ADD CONSTRAINT listing_variants_values_in_order CHECK ((value_2 IS NULL OR value_1 IS NOT NULL) AND (value_3 IS NULL OR value_2 IS NOT NULL))');
        // 同じ組み合わせの種類は 1 つだけ（消したものは除く）。軸の無い種類も商品に 1 つだけ
        DB::statement("CREATE UNIQUE INDEX listing_variants_combination_unique ON listing_variants (listing_id, COALESCE(value_1, ''), COALESCE(value_2, ''), COALESCE(value_3, '')) WHERE deleted_at IS NULL");
        // 品番は出品者の中で重ならない（マーケットでは別の出品者が同じ品番を使える。前の listings の (seller_id, sku) と同じ）
        DB::statement('CREATE UNIQUE INDEX listing_variants_seller_sku_unique ON listing_variants (seller_id, sku) WHERE sku IS NOT NULL AND deleted_at IS NULL');

        // 種類の値は、同じ商品の、その番の軸の値だけ（赤を「サイズ」の欄に入れられない・別の商品の値を使えない）
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_variant_values_match() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                n integer;
                v text;
            BEGIN
                -- 出品者は商品から写す（呼び出し側の値は使わない）
                SELECT l.seller_id INTO NEW.seller_id FROM listings l WHERE l.id = NEW.listing_id;
                FOR n IN 1..3 LOOP
                    v := CASE n WHEN 1 THEN NEW.value_1 WHEN 2 THEN NEW.value_2 ELSE NEW.value_3 END;
                    IF v IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM listing_option_values ov JOIN listing_options o ON o.id = ov.option_id
                        WHERE ov.id = v AND o.listing_id = NEW.listing_id AND o.position = n
                    ) THEN
                        RAISE EXCEPTION '種類 % の値 % は商品 % の軸 % のものではありません', NEW.id, v, NEW.listing_id, n
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::statement('CREATE TRIGGER listing_variants_values_match BEFORE INSERT OR UPDATE OF listing_id, seller_id, value_1, value_2, value_3 ON listing_variants FOR EACH ROW EXECUTE FUNCTION sugar_variant_values_match()');

        // 今の商品に、軸の無い種類を 1 つずつ（品番は種類へ移す）
        foreach (DB::table('listings')->select(['id', 'sku', 'created_at'])->orderBy('id')->cursor() as $listing) {
            DB::table('listing_variants')->insert([
                'id' => strtolower((string) Str::ulid()),
                'listing_id' => $listing->id,
                'seller_id' => '',
                'position' => 1,
                'sku' => $listing->sku,
                'created_at' => $listing->created_at,
                'updated_at' => now(),
            ]);
        }

        $default = '(SELECT v.id FROM listing_variants v WHERE v.listing_id = %s.listing_id ORDER BY v.position, v.id LIMIT 1)';

        foreach (['inventory_stocks', 'inventory_units', 'cart_lines', 'transaction_lines'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->ulid('variant_id')->nullable();
            });
            DB::statement(sprintf("UPDATE {$name} SET variant_id = {$default} WHERE listing_id IS NOT NULL", $name));
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_variant_of_listing FOREIGN KEY (variant_id, listing_id) REFERENCES listing_variants (id, listing_id)"
                .(in_array($name, ['inventory_stocks', 'cart_lines'], true) ? ' ON DELETE CASCADE' : ' ON DELETE RESTRICT'));
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_variant_with_listing CHECK ((variant_id IS NULL) = (listing_id IS NULL))");
            DB::statement("CREATE INDEX {$name}_variant_id_index ON {$name} (variant_id)");
        }

        // 在庫とカートは種類ごとに 1 行
        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->dropUnique(['listing_id', 'location_id']);
            $table->unique(['variant_id', 'location_id']);
        });
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'listing_id']);
            $table->unique(['cart_id', 'variant_id']);
        });
        DB::statement('ALTER TABLE inventory_stocks ALTER COLUMN variant_id SET NOT NULL');
        DB::statement('ALTER TABLE cart_lines ALTER COLUMN variant_id SET NOT NULL');

        // 取引の行には、合意のときの品番を写して残す（種類の名前は description に入る）
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->string('sku', 64)->nullable();
        });
        DB::statement('UPDATE transaction_lines t SET sku = v.sku FROM listing_variants v WHERE v.id = t.variant_id');

        // 公開の前の例外（ADR 0006）: 品番を商品の種類（listing_variants）へ移した。前のバージョンへ戻すと列が無いので、公開の版からは足す → 次の版で消す
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn('sku');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('sku', 64)->nullable();
        });
        DB::statement('UPDATE listings l SET sku = v.sku FROM listing_variants v WHERE v.listing_id = l.id AND v.value_1 IS NULL AND v.deleted_at IS NULL');
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->dropColumn('sku');
        });
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'variant_id']);
            $table->unique(['cart_id', 'listing_id']);
        });
        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->dropUnique(['variant_id', 'location_id']);
            $table->unique(['listing_id', 'location_id']);
        });
        foreach (['inventory_stocks', 'inventory_units', 'cart_lines', 'transaction_lines'] as $name) {
            DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_variant_with_listing");
            DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_variant_of_listing");
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('variant_id');
            });
        }
        DB::statement('DROP TRIGGER IF EXISTS listing_variants_values_match ON listing_variants');
        DB::statement('DROP FUNCTION IF EXISTS sugar_variant_values_match()');
        Schema::dropIfExists('listing_variants');
        Schema::dropIfExists('listing_option_values');
        Schema::dropIfExists('listing_options');
    }
};
