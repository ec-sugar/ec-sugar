<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App「セット商品」の表（ADR 0025）。表の名前は App の頭（bundle）で始め、核の表は作らない・変えない
 * （商品と種類への参照の外部キーはよい。PluginMigrationsTest が見張る）。店の DB に流れ、使っていない店では空のまま。
 *
 * - bundle_components: セットの商品（listing_id）1 つに、構成する品（part_listing_id・variant_id）と
 *   1 セットあたりの数（quantity）を 1 行ずつ。同じセット × 同じ品は 1 行だけ
 * - セットそのものは在庫を持たず、核（LineStock）が構成する品の在庫を押さえる・解く・出庫する。
 *   核は 1 段だけ辿るので、**セットの中にセットを入れさせない**（App が保存のときに断り、DB の引き金でも断る）
 *
 * 規則は DB でも守る: 1 セットあたりの数は 1〜999、自分自身は構成の品にできない、
 * 構成の品は「その商品の種類」でなければならない（種類と商品の組の外部キー）、入れ子は引き金が断る
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // セットの商品。商品を消すと構成も消える
            $table->foreignUlid('listing_id')->constrained('listings')->cascadeOnDelete();
            // 構成する品（商品と、その商品の種類）。種類を消すと構成も消える（下の組の外部キー）
            $table->ulid('part_listing_id');
            $table->ulid('variant_id');
            // 1 セットあたりの数
            $table->unsignedSmallInteger('quantity');
            $table->timestampsTz();

            $table->unique(['listing_id', 'variant_id']);
            // 「この品はどのセットに入っているか」を引くとき（入れ子の確かめ・構成の品を消した時）
            $table->index('part_listing_id');
            $table->index('variant_id');
        });

        // 構成の品は、その商品の種類であること（別の商品の種類を入れられない。核の cart_lines と同じ組の外部キー）
        DB::statement('ALTER TABLE bundle_components ADD CONSTRAINT bundle_components_variant_of_listing '
            .'FOREIGN KEY (variant_id, part_listing_id) REFERENCES listing_variants (id, listing_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE bundle_components ADD CONSTRAINT bundle_components_quantity_range CHECK (quantity BETWEEN 1 AND 999)');
        // 自分自身を構成の品にしない（在庫を自分から引く輪を作らない）
        DB::statement('ALTER TABLE bundle_components ADD CONSTRAINT bundle_components_not_self CHECK (listing_id <> part_listing_id)');

        // セットの中にセットを入れさせない（核は 1 段しか辿らないので、入れ子を許すと在庫を引かずに売れてしまう）。
        // App の保存でも断るが、在庫とお金に関わる決まりなので DB でも守る（核の listing_variants の引き金と同じ作り）
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bundle_no_nesting() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM bundle_components b WHERE b.listing_id = NEW.part_listing_id) THEN
                    RAISE EXCEPTION 'セットの中にセットは入れられません（% は別のセットの商品です）', NEW.part_listing_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (SELECT 1 FROM bundle_components b WHERE b.part_listing_id = NEW.listing_id) THEN
                    RAISE EXCEPTION 'ほかのセットの構成に入っている商品は、セットにできません（%）', NEW.listing_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
        DB::statement('CREATE TRIGGER bundle_components_no_nesting BEFORE INSERT OR UPDATE OF listing_id, part_listing_id, variant_id '
            .'ON bundle_components FOR EACH ROW EXECUTE FUNCTION bundle_no_nesting()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS bundle_components_no_nesting ON bundle_components');
        Schema::dropIfExists('bundle_components');
        DB::statement('DROP FUNCTION IF EXISTS bundle_no_nesting()');
    }
};
