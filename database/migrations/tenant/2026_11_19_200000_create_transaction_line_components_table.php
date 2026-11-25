<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 取引の行が在庫を押さえた時の構成の控え（ADR 0108）。
 *
 * セット商品（自分の在庫を持たず、構成する品から引く商品）は、押さえる時に「どの種類を 1 つあたり何個」を App に聞く。
 * 解く・出庫する・返品で戻す時にもう一度聞くと、その間に中身を変えた・セットをやめた・App を止めた時に、
 * 押さえた数と動かす数が食い違う（確保が残り続ける・取消や発送が例外で通らない・ほかの注文の確保を食って売りすぎる）。
 * そこで、押さえた時の構成を行にひもづけて控え、後の動きは必ずこの控えから計算する。
 *
 * 行が無い取引の行は、商品そのものの在庫を押さえた行（ふつうの商品。App を止めていた間に売れたセットも）。
 * 1 度書いたら変えない・消さない（押さえた事実の記録）。置くのは ID と数だけ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_line_components', function (Blueprint $table): void {
            $table->char('line_id', 26);
            $table->char('variant_id', 26);
            $table->unsignedSmallInteger('each');        // 行の 1 つあたり、この種類を何個引くか
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['line_id', 'variant_id']);
            $table->foreign('line_id')->references('id')->on('transaction_lines')->restrictOnDelete();
            $table->foreign('variant_id')->references('id')->on('listing_variants')->restrictOnDelete();
        });

        // 核の上限と同じ（App の口の答えも 1〜999 に絞っている）
        DB::statement('ALTER TABLE transaction_line_components ADD CONSTRAINT transaction_line_components_each CHECK ("each" BETWEEN 1 AND 999)');
        DB::statement('CREATE TRIGGER transaction_line_components_append_only BEFORE UPDATE OR DELETE ON transaction_line_components FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER transaction_line_components_no_truncate BEFORE TRUNCATE ON transaction_line_components FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_line_components');
    }
};
