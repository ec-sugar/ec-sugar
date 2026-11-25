<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 領収書を出した回数の表（App「納品書・領収書」、ADR 0025）。プラグインの表なので名前は order_print で始め、核の表は変えない（注文を参照するだけ）。
 *
 * - order_print_receipts: 注文ごとに 1 行。出した回数（2 回目からの領収書に「再発行」の印を付ける）と、最初と最後に出した日時
 * 宛名・但し書き（人や会社の名前になりうる）は残さない。回数は 1 以上で、注文 1 つに 1 行（DB でも守る）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_print_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->unsignedInteger('issued_count');
            $table->timestampTz('first_issued_at');
            $table->timestampTz('last_issued_at');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE order_print_receipts ADD CONSTRAINT order_print_receipts_count_check CHECK (issued_count >= 1 AND last_issued_at >= first_issued_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_print_receipts');
    }
};
