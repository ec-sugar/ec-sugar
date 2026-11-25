<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 配送の CSV の表（ADR 0025・0051）。プラグインの表なので名前は shipping_csv で始め、核の表は変えない（注文と受渡を参照するだけ）。
 *
 * - shipping_csv_exported: 受渡ごとの「書き出した」の印（最後に書き出した日時と形と回数）。「まだ書き出していない注文」の絞り込みに使う
 * - shipping_csv_imports: 取り込んだ CSV 1 つ（確かめ待ち → 発送にした・取りやめた）。中身は行の表に。ファイルそのものは置かない（同じファイルかはハッシュで見る）
 * - shipping_csv_import_rows: 取り込んだ CSV の 1 行（どの受渡か・送り状番号〔暗号化〕・発送にする・飛ばす〔理由〕・発送にした）
 *
 * 平文の個人情報は置かない（ADR 0010）。届け先は核の受渡の暗号化した列にだけあり、ここには写さない。
 * 送り状番号は、核では一覧に出さない値として扱っているので、ここでは店の鍵で暗号化する。形の合わない値（名前などがずれて入った列かもしれない）は置かない
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_csv_exported', function (Blueprint $table): void {
            $table->foreignUlid('fulfillment_id')->primary()->constrained('fulfillments')->restrictOnDelete();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->string('format', 32);
            $table->unsignedInteger('times')->default(1);
            $table->timestampTz('exported_at');
        });

        Schema::create('shipping_csv_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('carrier', 32);
            $table->string('status', 16)->default('pending');
            $table->char('file_hash', 64);
            $table->unsignedInteger('rows_count');
            $table->unsignedInteger('ready_count');
            $table->unsignedInteger('skipped_count');
            $table->unsignedInteger('shipped_count')->default(0);
            $table->string('created_by', 64);
            $table->string('decided_by', 64)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index('file_hash');
        });

        Schema::create('shipping_csv_import_rows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('import_id')->constrained('shipping_csv_imports')->cascadeOnDelete();
            $table->unsignedInteger('line');
            $table->string('reference', 40)->nullable();
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('fulfillment_id')->nullable()->constrained('fulfillments')->restrictOnDelete();
            $table->text('tracking_number')->nullable();
            $table->string('status', 16);
            $table->string('reason', 32)->nullable();
            $table->timestampsTz();

            $table->unique(['import_id', 'line']);
        });

        DB::statement("ALTER TABLE shipping_csv_imports ADD CONSTRAINT shipping_csv_imports_status_check CHECK (status IN ('pending', 'applied', 'discarded'))");
        DB::statement("ALTER TABLE shipping_csv_imports ADD CONSTRAINT shipping_csv_imports_carrier_check CHECK (carrier IN ('yamato', 'sagawa', 'japanpost', 'other'))");
        DB::statement('ALTER TABLE shipping_csv_imports ADD CONSTRAINT shipping_csv_imports_counts_check CHECK (ready_count + skipped_count = rows_count AND shipped_count <= ready_count)');
        DB::statement("ALTER TABLE shipping_csv_imports ADD CONSTRAINT shipping_csv_imports_decided_check CHECK ((status = 'pending') = (decided_at IS NULL))");
        DB::statement("ALTER TABLE shipping_csv_import_rows ADD CONSTRAINT shipping_csv_import_rows_status_check CHECK (status IN ('ready', 'skipped', 'shipped'))");
        // 発送にする・発送にした行は、どの受渡かと送り状番号が必ずある。飛ばす行は理由が必ずある
        DB::statement("ALTER TABLE shipping_csv_import_rows ADD CONSTRAINT shipping_csv_import_rows_shape_check CHECK ((status = 'skipped') = (reason IS NOT NULL) AND (status = 'skipped' OR (fulfillment_id IS NOT NULL AND tracking_number IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_csv_import_rows');
        Schema::dropIfExists('shipping_csv_imports');
        Schema::dropIfExists('shipping_csv_exported');
    }
};
