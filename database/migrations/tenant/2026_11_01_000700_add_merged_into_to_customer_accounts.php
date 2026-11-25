<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 同じお客さんを手でまとめる（ADR 0044 の 2）。注文（取引）は書き換えず、まとめられた台帳に「まとめた先」の印を付けるだけ。
 * 分け直すときは印を外す。入れ子にしない（まとめた先はまとめられていない台帳だけ。サービスで確かめ、自分自身は DB でも断る）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->foreignUlid('merged_into_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->timestampTz('merged_at')->nullable();
            $table->index('merged_into_id');
        });

        DB::statement('ALTER TABLE customer_accounts ADD CONSTRAINT customer_accounts_not_into_self CHECK (merged_into_id IS NULL OR merged_into_id <> id)');
        DB::statement('ALTER TABLE customer_accounts ADD CONSTRAINT customer_accounts_merged_shape CHECK ((merged_into_id IS NULL) = (merged_at IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customer_accounts DROP CONSTRAINT IF EXISTS customer_accounts_merged_shape');
        DB::statement('ALTER TABLE customer_accounts DROP CONSTRAINT IF EXISTS customer_accounts_not_into_self');

        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('merged_at');
        });
    }
};
