<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App「購入の制限」の表（ADR 0025）。表の名前は App の頭（purchase_limits）で始め、核の表は作らない・変えない
 * （商品への参照の外部キーはよい）。店の DB に流れ、使っていない店では空のまま。
 *
 * - purchase_limits_listings: 商品ごとの「1 回のご注文の上限」（1 商品に 1 行。主キーは商品の ID）。上限をやめる時は行を消す
 * - 店の「最低購入金額」は App の設定に持ち、ここには置かない
 * - 規則は DB でも守る: 上限は 1〜999（0 以下の行があると、核は決まりを読めない扱いにしてご注文を受けなくなる）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_limits_listings', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->primary()->constrained('listings')->cascadeOnDelete();
            $table->unsignedSmallInteger('per_order');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE purchase_limits_listings ADD CONSTRAINT purchase_limits_listings_per_order_range CHECK (per_order BETWEEN 1 AND 999)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_limits_listings');
    }
};
