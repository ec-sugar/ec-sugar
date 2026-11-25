<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会員の届け先と本人の再確認（ADR 0044 の 3）。
 *
 * - 届け先: 会員として入ってから入れたものだけ（ゲストの時の住所は写さない）。中身（名前・電話・住所）は店の鍵で暗号化した 1 つの JSON。
 *   いつもの届け先は会員ごとに 1 つ（DB の部分の一意の索引）。1 人 20 件まで（サービスで）
 * - 再確認: 会員の印ごとに、最後に本人を確かめた時刻（メールの変更・パスキー・退会の前。10 分）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->text('address');                  // 暗号化（name・phone・postal・prefecture・city・line1・line2）
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index(['customer_account_id', 'created_at']);
        });

        DB::statement('CREATE UNIQUE INDEX customer_addresses_one_default ON customer_addresses (customer_account_id) WHERE is_default');

        Schema::table('customer_sessions', function (Blueprint $table): void {
            $table->timestampTz('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_sessions', function (Blueprint $table): void {
            $table->dropColumn('confirmed_at');
        });

        Schema::dropIfExists('customer_addresses');
    }
};
