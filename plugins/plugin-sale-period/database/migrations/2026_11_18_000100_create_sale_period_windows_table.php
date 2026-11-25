<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App「公開の予約と販売期間」の表（ADR 0025）。表の名前は App の頭（sale_period）で始め、核の表は作らない・変えない
 * （商品への参照の外部キーはよい。PluginMigrationsTest が見張る）。店の DB に流れ、使っていない店では空のまま。
 *
 * - sale_period_windows: 商品ごとの公開の予約と販売期間（1 商品に 1 行）。出す日時（starts_at）と終わる日時（ends_at）はどちらも任意で、
 *   両方が空の行は置かない（やめる時は行を消す）。保存は UTC（timestampTz）で、管理画面は日本時間で見せる
 * - 核の商品の状態（listings.status）は変えない。期間は「出すか・売るか」の見せ方だけで、店の人が決めた下書き・公開・終了はそのまま
 * - 規則は DB でも守る: 始まりは終わりより前・どちらかは入っている
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_period_windows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();

            // 「今は出さない商品」「今は売らない商品」を引くときの順（今の時刻と比べる）
            $table->index('starts_at');
            $table->index('ends_at');
        });

        DB::statement('ALTER TABLE sale_period_windows ADD CONSTRAINT sale_period_windows_some_bound CHECK (starts_at IS NOT NULL OR ends_at IS NOT NULL)');
        DB::statement('ALTER TABLE sale_period_windows ADD CONSTRAINT sale_period_windows_order CHECK (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_period_windows');
    }
};
