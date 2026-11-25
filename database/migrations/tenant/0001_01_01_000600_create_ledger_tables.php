<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger（仕訳。CONCEPT 5.5、ADR 0011）。
 *
 * 1 件の仕訳（journal）に 2 行以上の明細（posting）。明細の金額は符号付きで、仕訳ごとの合計は必ず 0。
 * 0 になることは遅延制約トリガーでコミット時に DB が検査する（アプリのバグで片側だけ書けない）。
 * 訂正は逆仕訳（reverses_journal_id）で行い、行の更新・削除はトリガーで禁止する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_journals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->string('type', 16);                        // JournalType
            $table->char('currency', 3);
            $table->ulid('reverses_journal_id')->nullable()->unique(); // 逆仕訳の元。1 件は 1 回だけ打ち消せる
            $table->string('reference', 128)->nullable();      // 決済代行側の ID など
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->index(['transaction_id', 'type']);
        });

        Schema::table('ledger_journals', function (Blueprint $table): void {
            $table->foreign('reverses_journal_id')->references('id')->on('ledger_journals')->restrictOnDelete();
        });

        Schema::create('ledger_postings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('journal_id')->constrained('ledger_journals')->restrictOnDelete();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete(); // 残高集計用（journal と同じ値）
            $table->string('account', 16);                     // LedgerAccount
            $table->bigInteger('amount');                      // 正 = その口座が受け取る権利、負 = 払う義務

            $table->index(['transaction_id', 'account']);
        });

        DB::statement('ALTER TABLE ledger_postings ADD CONSTRAINT ledger_postings_amount_nonzero CHECK (amount <> 0)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_ledger_journal_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                total numeric;
                lines integer;
            BEGIN
                SELECT COALESCE(SUM(amount), 0), COUNT(*) INTO total, lines
                FROM ledger_postings WHERE journal_id = NEW.journal_id;
                IF total <> 0 OR lines < 2 THEN
                    RAISE EXCEPTION '仕訳 % の貸借が一致しません（合計 %、明細 % 行）', NEW.journal_id, total, lines;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM ledger_journals j
                    WHERE j.id = NEW.journal_id AND j.transaction_id <> NEW.transaction_id
                ) THEN
                    RAISE EXCEPTION '仕訳 % の明細が別の取引を指しています', NEW.journal_id;
                END IF;
                RETURN NULL;
            END;
            $$
            SQL);

        DB::statement('CREATE CONSTRAINT TRIGGER ledger_postings_balanced AFTER INSERT ON ledger_postings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sugar_ledger_journal_balanced()');

        foreach (['ledger_journals', 'ledger_postings'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_append_only BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()");
            DB::statement("CREATE TRIGGER {$name}_no_truncate BEFORE TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_postings');
        Schema::dropIfExists('ledger_journals');
        DB::statement('DROP FUNCTION IF EXISTS sugar_ledger_journal_balanced()');
    }
};
