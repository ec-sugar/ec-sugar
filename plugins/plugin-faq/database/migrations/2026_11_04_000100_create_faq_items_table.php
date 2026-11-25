<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * よくある質問の表（ADR 0025）。プラグインの表なので名前は faq で始め、核の表は変えない（商品を参照するだけ）。
 *
 * - faq_items: 質問と答え・どの商品に出すか（空は「全部の商品」）・並び（店で 1 つの並び。小さいほど上）・公開か
 * 質問と答えは店の人が書く文（お客さんの個人情報は入れない）。長さと空でないことは DB でも守る。
 * 並びは一意（入れ替えは 1 つのトランザクションで 2 行を書くので、確かめはトランザクションの終わりに）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->nullable()->constrained('listings')->restrictOnDelete();
            $table->string('question', 200);
            $table->text('answer');
            $table->unsignedInteger('position');
            $table->boolean('published')->default(true);
            $table->timestampsTz();

            $table->index(['listing_id', 'position']);
        });

        DB::statement('ALTER TABLE faq_items ADD CONSTRAINT faq_items_text_check CHECK (char_length(btrim(question)) > 0 AND char_length(btrim(answer)) > 0 AND char_length(answer) <= 2000)');
        DB::statement('ALTER TABLE faq_items ADD CONSTRAINT faq_items_position_unique UNIQUE (position) DEFERRABLE INITIALLY DEFERRED');
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_items');
    }
};
