<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 売れ筋ランキングの数えた結果（App「おすすめの区画」、ADR 0071）。プラグインの表なので名前は recommendations で始め、核の表は変えない。
 * 1 時間ごとの recommendations:rank が、直近 30 日の払われた注文の明細から商品ごとの売れた数を数えて丸ごと入れ替える。
 * ショップの画面はこの表を読むだけ（開くたびに注文を数えない）。お客さんのことは何も置かない（商品と数だけ）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations_ranks', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->primary()->constrained('listings')->cascadeOnDelete();
            $table->integer('position');
            $table->bigInteger('units');
            $table->timestampTz('counted_at');

            $table->unique('position');
        });

        DB::statement('ALTER TABLE recommendations_ranks ADD CONSTRAINT recommendations_ranks_check CHECK (position >= 1 AND units >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations_ranks');
    }
};
