<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 注文の項目（ADR 0066）。店が注文の入力に足す欄（のしの有無・用途・ご要望など）の決まりと、注文ごとの値。
 *
 * - order_fields: 欄の決まり。種類（1 行の文・複数行の文・選ぶ・チェック）・名前・説明・必須・並び・使う／使わない。同時に使う欄は 10 個まで（DB でも守る）
 * - order_field_values: 注文ごとの値。その時の欄の名前・説明・種類を写して持つ（後で名前を変えても、過去の注文は当時の名前で見せる）。
 *   値は自由な文で個人情報を含みうるので、店の鍵で暗号化して持つ（ADR 0010。列は暗号文）。追記専用（UPDATE・DELETE・TRUNCATE を断る）。
 *   欄への外部キーは消すのを拒む（注文に使われた欄は消せず、「使わない」にだけできる）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fields', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 40);
            $table->string('description', 100)->nullable();
            $table->string('type', 16);                       // text・textarea・select・checkbox
            $table->jsonb('options')->default('[]');          // select のときだけ: 選択肢の文（20 個まで）
            $table->boolean('required')->default(false);
            $table->smallInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->index(['enabled', 'position']);
        });

        DB::statement("ALTER TABLE order_fields ADD CONSTRAINT order_fields_type_known CHECK (type IN ('text', 'textarea', 'select', 'checkbox'))");
        DB::statement("ALTER TABLE order_fields ADD CONSTRAINT order_fields_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE order_fields ADD CONSTRAINT order_fields_options_shape CHECK (jsonb_typeof(options) = 'array' AND ((type = 'select') = (jsonb_array_length(options) BETWEEN 1 AND 20)) AND (type = 'select' OR jsonb_array_length(options) = 0))");
        DB::statement('ALTER TABLE order_fields ADD CONSTRAINT order_fields_position_range CHECK (position BETWEEN 0 AND 1000)');

        // 同時に使う欄は 10 個まで（アプリは連鎖のロックの中で数える。すり抜けても DB が拒む）
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_order_fields_limit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.enabled AND (SELECT count(*) FROM order_fields WHERE enabled AND id <> NEW.id) >= 10 THEN
                    RAISE EXCEPTION 'order_fields_enabled_limit: 同時に使う注文の項目は 10 個まで';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::statement('CREATE TRIGGER order_fields_enabled_limit BEFORE INSERT OR UPDATE OF enabled ON order_fields FOR EACH ROW EXECUTE FUNCTION sugar_order_fields_limit()');

        Schema::create('order_field_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('order_field_id')->constrained('order_fields')->restrictOnDelete();
            $table->string('name', 40);                       // その時の欄の名前（写し）
            $table->string('description', 100)->nullable();   // その時の欄の説明（写し。同意のチェックで、どの文に同意したかを示す）
            $table->string('type', 16);                       // その時の欄の種類（写し）
            $table->smallInteger('position');                 // その時の並び（見せる順）
            $table->text('value');                            // 暗号文（ADR 0010）
            $table->timestampTz('created_at')->nullable();

            $table->unique(['transaction_id', 'order_field_id']);
            $table->index('order_field_id');
        });

        DB::statement("ALTER TABLE order_field_values ADD CONSTRAINT order_field_values_type_known CHECK (type IN ('text', 'textarea', 'select', 'checkbox'))");
        // 注文の時の値は書き換えない・消さない（記録の表と同じく追記専用。店ごと消す時は DB ごと消すので、この守りとぶつからない）
        DB::statement('CREATE TRIGGER order_field_values_append_only BEFORE UPDATE OR DELETE ON order_field_values FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER order_field_values_no_truncate BEFORE TRUNCATE ON order_field_values FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_field_values');
        Schema::dropIfExists('order_fields');
        DB::statement('DROP FUNCTION IF EXISTS sugar_order_fields_limit()');
    }
};
