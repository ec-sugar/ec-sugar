<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 取引（CONCEPT 5.4、ADR 0011）。種類（kind）とフェーズの並びはレジストリが決めるので文字列。
 * 取引・明細・提示は消さない（帳票と監査の根拠）。外部キーは restrict。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('parent_id')->nullable()->index();    // 定期の子、仕事のマイルストーン（外部キーは主キーの後で付ける）
            $table->string('kind', 32);                        // sale | (プラグインが追加)
            $table->string('phase', 16);                       // Phase
            $table->string('suspended_phase', 16)->nullable(); // 紛争に入る前のフェーズ（再開先）
            $table->string('status', 16)->default('open');     // open | completed | cancelled | refunded
            $table->foreignUlid('seller_id')->nullable()->constrained('sellers')->restrictOnDelete(); // 複数者の提示を受ける間は null
            $table->foreignUlid('customer_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('payer', 8);                        // customer | seller
            $table->string('payee', 8);
            $table->char('currency', 3)->default('JPY');
            $table->boolean('prices_include_tax')->default(true); // 総額表示（内税）が既定
            $table->string('tax_rounding', 8)->default('floor');
            $table->bigInteger('subtotal_amount')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->timestampTz('agreed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('closed_at')->nullable();      // 取消・返金で終わった時刻
            $table->timestampsTz();

            $table->index(['seller_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['kind', 'phase']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('transactions')->restrictOnDelete();
        });

        Schema::create('transaction_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('line_type', 16);                   // item | adjustment
            $table->string('kind', 32)->nullable();            // 調整行の種類（discount, shipping, ...）
            $table->foreignUlid('listing_id')->nullable()->constrained('listings')->restrictOnDelete();
            $table->foreignUlid('inventory_unit_id')->nullable()->constrained('inventory_units')->restrictOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained('locations')->restrictOnDelete(); // 在庫を動かす拠点
            $table->string('description', 200);               // 合意時点の名称（出品物が変わっても残る）
            $table->integer('quantity');
            $table->bigInteger('unit_amount');                 // 調整行は負になりうる
            $table->bigInteger('amount');                      // quantity × unit_amount
            $table->unsignedSmallInteger('tax_rate_bp');       // 税率（basis point。10% = 1000）
            $table->timestampsTz();

            $table->unique(['transaction_id', 'position']);
            $table->index('listing_id');
            $table->index('inventory_unit_id');
        });

        Schema::create('transaction_quotes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('seller_id')->constrained('sellers')->restrictOnDelete(); // 提示した出品者
            $table->string('status', 16)->default('proposed'); // proposed | accepted | rejected | withdrawn
            $table->jsonb('lines');                            // 提示した明細（LineDraft の配列）
            $table->bigInteger('subtotal_amount');
            $table->bigInteger('tax_amount');
            $table->bigInteger('total_amount');
            $table->timestampTz('valid_until')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['transaction_id', 'status']);
        });

        // 第 2 片で保留していた、個体在庫の入手元（買取）の外部キー
        Schema::table('inventory_units', function (Blueprint $table): void {
            $table->foreign('acquired_transaction_id')->references('id')->on('transactions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_units', function (Blueprint $table): void {
            $table->dropForeign(['acquired_transaction_id']);
        });
        Schema::dropIfExists('transaction_quotes');
        Schema::dropIfExists('transaction_lines');
        Schema::dropIfExists('transactions');
    }
};
