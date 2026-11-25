<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商品の CSV の取り込みの記録（docs/design/product-csv.md）。上げた 1 つのファイルに 1 行。
 * 確かめも反映もキューの仕事で、ここに状態と進み具合を書く。反映は商品 1 つずつ短いトランザクションで入れ、途中で止まったら止まった行と理由を残す。
 *
 * - csv は UTF-8 に直した CSV の文（商品のデータで、個人情報ではない）。反映が済んだら・断られたら・古くなったら消す
 * - 同じ店で反映（待ち・進行中）は 1 つだけ（部分の一意の索引で DB でも守る）
 * - キューの仕事は 1 回を短く区切り（走らせ役の時間の上限より短く）、next_line から続きの仕事が進める
 * - created は、この取り込み（と、もう一度取り込む前の取り込み）が作った新しい商品の「商品名 => ID」。入れ直しで同じ商品を二重に作らない
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('previous_id')->nullable();
            $table->string('actor', 64);
            $table->char('digest', 64);
            $table->string('format', 32);
            $table->unsignedInteger('rows');
            $table->string('status', 16);
            $table->text('csv')->nullable();
            $table->jsonb('report')->nullable();
            $table->jsonb('applied')->nullable();
            $table->unsignedInteger('applied_rows')->default(0);
            // 確かめ・反映を続ける行（キューの仕事は 1 回 45 秒ほどで区切り、続きの仕事を出す。その商品の最初の行）
            $table->unsignedInteger('next_line')->nullable();
            $table->unsignedInteger('stopped_line')->nullable();
            $table->string('stopped_reason', 500)->nullable();
            $table->jsonb('created')->default('{}');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'updated_at']);
        });

        // 自分の表を指す外部キーは、主キーができた後に足す
        Schema::table('product_imports', function (Blueprint $table): void {
            $table->foreign('previous_id')->references('id')->on('product_imports')->nullOnDelete();
        });

        DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_status CHECK (status IN ('checking', 'checked', 'rejected', 'queued', 'running', 'done', 'stopped', 'expired'))");
        DB::statement('ALTER TABLE product_imports ADD CONSTRAINT product_imports_applied_rows CHECK (applied_rows <= rows)');
        // 反映（待ち・進行中）は店に 1 つだけ
        DB::statement("CREATE UNIQUE INDEX product_imports_one_applying ON product_imports ((true)) WHERE status IN ('queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
    }
};
