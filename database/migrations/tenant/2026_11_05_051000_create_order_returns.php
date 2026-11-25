<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 返品・一部返金・済んだ注文の返金（ADR 0048）。取引を開き直さず、同じ取引に「返金の行」を足して記録する。
 *
 * - order_returns: 返金の 1 回（どの理由で・いくら・税率ごとの額と消費税）。追記だけ（直さない・消さない）。お金の動きは refunds の行（return_id）で追う。
 *   決済代行に断られたら、同じ 1 回に新しい返金の行（新しい冪等キー）を足して頼み直す
 * - refund_lines: 返金の行（明細の位置・数・額・税率・在庫に戻したか）。追記だけ。商品の行は返した数の合計が明細の数を、額の合計が明細の額を越えない（DB でも断る）
 * - refunds: 目的に return（返品・一部返金）、返し方（channel: provider＝決済代行に頼む・outside＝店が外で返した記録だけ）、返金の 1 回（return_id）を足す。
 *   外で返した記録は決済代行の支払いを持たないことがある（銀行振込・代金引換）ので、支払いの列は決済代行に頼む時だけ必須にする。
 *   **返金の合計は受け取った額を越えない**: 台帳の支払いの仕訳の合計を、途中・止めた・済んだ返金（返金が要る支払いと、まだ確かめていない外でした返金を除く）の合計が越えたら断る
 * - point_entries: 一部の返金の割合の戻し（release、cause = partial）と取り消し（revoke、cause = partial）は注文に何度でも。注文ごとの戻しの合計は使った数を、
 *   取り消しの合計（引けなかった数を含む）は付けた数を越えない（DB でも断る）
 * - sent_mails: 返金の知らせ（order_refunded）を返金ごとに 1 回
 *
 * 個人情報は入れない（理由は決まった値だけ。自由な文は持たない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->string('reason', 24);                 // ReturnReason
            $table->bigInteger('amount');                 // 返す額の合計（税込みの円）
            $table->jsonb('taxes')->default('[]');        // 税率ごとの返す額と消費税 [{rate_bp, gross, tax}]
            $table->string('actor', 64)->nullable();
            $table->timestampTz('created_at');

            $table->index('transaction_id');
        });

        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_reason CHECK (reason IN ('returned', 'defective', 'wrong_item', 'goodwill', 'price_adjustment', 'external', 'other'))");
        // 0 円は、0 円の品だけを返して在庫に戻す記録（返金の記録は作らない）
        DB::statement('ALTER TABLE order_returns ADD CONSTRAINT order_returns_amount CHECK (amount >= 0)');
        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_taxes_array CHECK (jsonb_typeof(taxes) = 'array')");
        DB::statement('CREATE TRIGGER order_returns_append_only BEFORE UPDATE OR DELETE ON order_returns FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER order_returns_no_truncate BEFORE TRUNCATE ON order_returns FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');

        Schema::create('refund_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('return_id')->constrained('order_returns')->restrictOnDelete();
            $table->foreignUlid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->string('kind', 16);                   // item・addon・shipping・amount（金額だけ）
            $table->integer('line_position')->nullable(); // 明細の位置（金額だけの行は無い）
            $table->integer('quantity')->default(0);      // 商品は返した数、オプションは従う商品の数、送料と金額だけは 0
            $table->bigInteger('amount');                 // 返す額（税込み。値引き・使ったポイントの割り振りを引いた実の額）。金額だけの行は、止めた返金を確かめた額にそろえる直しで負もある
            $table->integer('tax_rate_bp');
            $table->boolean('restock')->default(false);   // 在庫に戻した（商品の行だけ）
            $table->timestampTz('created_at');

            $table->index(['transaction_id', 'line_position']);
            $table->index('return_id');
        });

        DB::statement("ALTER TABLE refund_lines ADD CONSTRAINT refund_lines_kind CHECK (kind IN ('item', 'addon', 'shipping', 'amount'))");
        DB::statement("ALTER TABLE refund_lines ADD CONSTRAINT refund_lines_position CHECK ((kind = 'amount') = (line_position IS NULL))");
        DB::statement("ALTER TABLE refund_lines ADD CONSTRAINT refund_lines_quantity CHECK ((kind IN ('item', 'addon') AND quantity > 0) OR (kind IN ('shipping', 'amount') AND quantity = 0))");
        DB::statement("ALTER TABLE refund_lines ADD CONSTRAINT refund_lines_amount CHECK ((kind <> 'amount' AND amount >= 0) OR (kind = 'amount' AND amount <> 0))");
        DB::statement("ALTER TABLE refund_lines ADD CONSTRAINT refund_lines_restock CHECK (NOT restock OR kind = 'item')");
        DB::statement('ALTER TABLE refund_lines ADD CONSTRAINT refund_lines_rate CHECK (tax_rate_bp BETWEEN 0 AND 10000)');
        // 明細の行に返す数と額は、明細の数と額を越えない（同じ取引の書き込みは取引の行の錠で順番にしてから数える）
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_refund_lines_within_line() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                line_quantity integer;
                line_amount bigint;
                done_quantity bigint;
                done_amount bigint;
            BEGIN
                IF NEW.kind = 'amount' THEN
                    RETURN NEW;
                END IF;
                PERFORM 1 FROM transactions WHERE id = NEW.transaction_id FOR UPDATE;
                SELECT quantity, amount INTO line_quantity, line_amount FROM transaction_lines WHERE transaction_id = NEW.transaction_id AND position = NEW.line_position;
                IF line_quantity IS NULL THEN
                    RAISE EXCEPTION 'refund_lines_unknown_line: この取引に無い明細の位置です（%）', NEW.line_position;
                END IF;
                SELECT COALESCE(SUM(quantity), 0), COALESCE(SUM(amount), 0) INTO done_quantity, done_amount FROM refund_lines
                 WHERE transaction_id = NEW.transaction_id AND line_position = NEW.line_position AND kind = NEW.kind;
                IF NEW.kind = 'item' AND done_quantity + NEW.quantity > line_quantity THEN
                    RAISE EXCEPTION 'refund_lines_over_quantity: 明細の数を越えて返せません（明細 %、返した %、今回 %）', line_quantity, done_quantity, NEW.quantity;
                END IF;
                IF done_amount + NEW.amount > GREATEST(line_amount, 0) THEN
                    RAISE EXCEPTION 'refund_lines_over_amount: 明細の額を越えて返せません（明細 %、返した %、今回 %）', line_amount, done_amount, NEW.amount;
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::statement('CREATE TRIGGER refund_lines_within_line BEFORE INSERT ON refund_lines FOR EACH ROW EXECUTE FUNCTION sugar_refund_lines_within_line()');
        DB::statement('CREATE TRIGGER refund_lines_append_only BEFORE UPDATE OR DELETE ON refund_lines FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER refund_lines_no_truncate BEFORE TRUNCATE ON refund_lines FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');

        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreignUlid('return_id')->nullable()->constrained('order_returns')->restrictOnDelete();
            $table->string('channel', 16)->default('provider');
        });

        DB::statement('ALTER TABLE refunds ALTER COLUMN attempt_id DROP NOT NULL');
        DB::statement('ALTER TABLE refunds ALTER COLUMN payment_ref DROP NOT NULL');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_channel CHECK (channel IN ('provider', 'outside') AND (channel = 'outside' OR (attempt_id IS NOT NULL AND payment_ref IS NOT NULL)))");
        // 外で返した記録は、返品・一部返金で、記録した時に済んでいる（決済代行の返金の ID は無い）
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_outside CHECK (channel = 'provider' OR (purpose = 'return' AND status = 'succeeded' AND provider_ref IS NULL))");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_provider_ref');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_provider_ref CHECK (status = 'requested' OR status = 'failed' OR provider_ref IS NOT NULL OR channel = 'outside')");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_purpose');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_purpose CHECK (purpose IN ('cancel', 'stray', 'external', 'shipping', 'return') AND (purpose = 'stray') = (stray_payment_id IS NOT NULL)"
            ." AND (purpose <> 'return' OR return_id IS NOT NULL) AND (return_id IS NULL OR purpose IN ('return', 'external')))");
        // 頼み直した印（retried）は、断られた送料の差額・取消・返品の返金に
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_resolution');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_resolution CHECK ((resolution IS NULL) = (resolved_at IS NULL) AND (resolution IS NULL OR (resolution = 'recorded' AND status = 'succeeded') OR (resolution = 'dismissed' AND status = 'failed' AND failure_reason = 'dismissed') OR (resolution = 'retried' AND status = 'failed' AND purpose IN ('shipping', 'cancel', 'return'))))");
        // 返金の 1 回に、生きた（断られていない）返金は 1 つ。外でした返金を記録した 1 回は、その返金だけ
        DB::statement("CREATE UNIQUE INDEX refunds_one_per_return ON refunds (return_id) WHERE return_id IS NOT NULL AND status <> 'failed'");
        // 1 注文に途中（答え待ち・手続き中・止めた）の返品の返金は 1 つ（同じ額の返金の取り違えと、同時の 2 人の二重を防ぐ）
        DB::statement("CREATE UNIQUE INDEX refunds_one_active_return ON refunds (transaction_id) WHERE purpose = 'return' AND status IN ('requested', 'pending', 'held')");

        // 返金の合計は受け取った額（台帳の支払いの仕訳）を越えない。数えるのは途中・止めた・済んだ返金（断られた・戻された返金は数えない）。
        // 返金が要る支払いの返金（台帳に入れていないお金。外でした返金がその支払いに当たるものも）と、まだ店の人が確かめていない外でした返金（決済代行の知らせ。決済代行が実在を守る）は数えない
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_refunds_within_received() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                payer_account text;
                received bigint;
                committed bigint;
            BEGIN
                IF NEW.purpose = 'stray' OR NEW.status NOT IN ('requested', 'pending', 'held', 'succeeded') OR (NEW.purpose = 'external' AND NEW.status = 'held')
                   OR EXISTS (SELECT 1 FROM stray_payments s WHERE s.payment_ref = NEW.payment_ref) THEN
                    RETURN NEW;
                END IF;
                SELECT payer INTO payer_account FROM transactions WHERE id = NEW.transaction_id FOR UPDATE;
                SELECT COALESCE(SUM(p.amount), 0) INTO received FROM ledger_postings p JOIN ledger_journals j ON j.id = p.journal_id
                 WHERE p.transaction_id = NEW.transaction_id AND j.type = 'payment' AND p.account = payer_account;
                SELECT COALESCE(SUM(amount), 0) INTO committed FROM refunds
                 WHERE transaction_id = NEW.transaction_id AND id <> NEW.id AND purpose <> 'stray'
                   AND status IN ('requested', 'pending', 'held', 'succeeded') AND NOT (purpose = 'external' AND status = 'held')
                   AND NOT EXISTS (SELECT 1 FROM stray_payments s WHERE s.payment_ref = refunds.payment_ref);
                IF committed + NEW.amount > received THEN
                    RAISE EXCEPTION 'refunds_over_received: 受け取った額を越える返金はできません（受領 %、ほかの返金 %、今回 %）', received, committed, NEW.amount;
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::statement('CREATE TRIGGER refunds_within_received BEFORE INSERT OR UPDATE OF amount, status, purpose, transaction_id ON refunds FOR EACH ROW EXECUTE FUNCTION sugar_refunds_within_received()');

        // ポイント: 一部の返金の割合の戻し・取り消しは cause = partial で何度でも（全額の返金の戻し〔cause なし〕と取り消し〔refund〕は今までどおり 1 回）
        DB::statement('DROP INDEX point_entries_once_per_order');
        DB::statement("CREATE UNIQUE INDEX point_entries_once_per_order ON point_entries (transaction_id, kind, (COALESCE(cause, ''))) WHERE kind IN ('earn', 'spend', 'release', 'revoke') AND COALESCE(cause, '') <> 'partial'");
        DB::statement('ALTER TABLE point_entries DROP CONSTRAINT point_entries_cause_known');
        DB::statement("ALTER TABLE point_entries ADD CONSTRAINT point_entries_cause_known CHECK (cause IS NULL OR (kind = 'expire' AND cause IN ('inactive', 'left')) OR (kind = 'revoke' AND cause IN ('refund', 'partial')) OR (kind = 'release' AND cause IN ('shipping', 'partial')))");
        // 注文ごとの戻しの合計は使った数まで、取り消しの合計（引けなかった数を含む）は付けた数まで（同じ会員の書き込みは台帳の行の錠で順番）
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_point_entries_within_order() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                limit_points bigint;
                done bigint;
            BEGIN
                IF NEW.transaction_id IS NULL OR NEW.kind NOT IN ('release', 'revoke') THEN
                    RETURN NEW;
                END IF;
                PERFORM 1 FROM customer_accounts WHERE id = NEW.customer_account_id FOR UPDATE;
                IF NEW.kind = 'release' THEN
                    SELECT COALESCE(-SUM(points), 0) INTO limit_points FROM point_entries WHERE transaction_id = NEW.transaction_id AND kind = 'spend';
                    SELECT COALESCE(SUM(points), 0) INTO done FROM point_entries WHERE transaction_id = NEW.transaction_id AND kind = 'release';
                    IF done + NEW.points > limit_points THEN
                        RAISE EXCEPTION 'point_entries_over_spent: 使った数を越えて戻せません（使った %、戻した %、今回 %）', limit_points, done, NEW.points;
                    END IF;
                ELSE
                    SELECT COALESCE(SUM(points), 0) INTO limit_points FROM point_entries WHERE transaction_id = NEW.transaction_id AND kind = 'earn';
                    SELECT COALESCE(SUM(-points + shortfall), 0) INTO done FROM point_entries WHERE transaction_id = NEW.transaction_id AND kind = 'revoke';
                    IF done + (-NEW.points + NEW.shortfall) > limit_points THEN
                        RAISE EXCEPTION 'point_entries_over_earned: 付けた数を越えて取り消せません（付けた %、取り消した %、今回 %）', limit_points, done, -NEW.points + NEW.shortfall;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::statement('CREATE TRIGGER point_entries_within_order BEFORE INSERT ON point_entries FOR EACH ROW EXECUTE FUNCTION sugar_point_entries_within_order()');

        // 返金の知らせのメール（返金ごとに 1 回）
        Schema::table('sent_mails', function (Blueprint $table): void {
            $table->ulid('refund_id')->nullable();
        });
        DB::statement('ALTER TABLE sent_mails DROP CONSTRAINT sent_mails_kind');
        DB::statement("ALTER TABLE sent_mails ADD CONSTRAINT sent_mails_kind CHECK (kind IN ('order_paid', 'order_shipped', 'order_awaiting_payment', 'order_payment_lapsed', 'order_refunded'))");
        DB::statement('DROP INDEX sent_mails_once');
        DB::statement("CREATE UNIQUE INDEX sent_mails_once ON sent_mails (transaction_id, kind, COALESCE(fulfillment_id, ''), COALESCE(refund_id, ''))");
    }

    public function down(): void
    {
        // 返品の記録・取消の返金のやり直しの記録があれば戻さない（返金の行と台帳の調整が消え、受け取った額の守りも外れる。やり直しの印は前の制約で許されない）
        if (DB::table('order_returns')->exists() || DB::table('refunds')->where('resolution', 'retried')->whereIn('purpose', ['cancel', 'return'])->exists()) {
            throw new RuntimeException('返品・一部返金か、取消の返金のやり直しの記録があるので戻せません。');
        }

        DB::statement('DROP INDEX sent_mails_once');
        DB::table('sent_mails')->where('kind', 'order_refunded')->delete();
        DB::statement("CREATE UNIQUE INDEX sent_mails_once ON sent_mails (transaction_id, kind, COALESCE(fulfillment_id, ''))");
        DB::statement('ALTER TABLE sent_mails DROP CONSTRAINT sent_mails_kind');
        DB::statement("ALTER TABLE sent_mails ADD CONSTRAINT sent_mails_kind CHECK (kind IN ('order_paid', 'order_shipped', 'order_awaiting_payment', 'order_payment_lapsed'))");
        Schema::table('sent_mails', function (Blueprint $table): void {
            $table->dropColumn('refund_id');
        });

        DB::statement('DROP TRIGGER IF EXISTS point_entries_within_order ON point_entries');
        DB::statement('DROP FUNCTION IF EXISTS sugar_point_entries_within_order()');
        DB::statement('ALTER TABLE point_entries DROP CONSTRAINT point_entries_cause_known');
        DB::statement("ALTER TABLE point_entries ADD CONSTRAINT point_entries_cause_known CHECK (cause IS NULL OR (kind = 'expire' AND cause IN ('inactive', 'left')) OR (kind = 'revoke' AND cause = 'refund') OR (kind = 'release' AND cause = 'shipping'))");
        DB::statement('DROP INDEX point_entries_once_per_order');
        DB::statement("CREATE UNIQUE INDEX point_entries_once_per_order ON point_entries (transaction_id, kind, (COALESCE(cause, ''))) WHERE kind IN ('earn', 'spend', 'release', 'revoke')");

        DB::statement('DROP TRIGGER IF EXISTS refunds_within_received ON refunds');
        DB::statement('DROP FUNCTION IF EXISTS sugar_refunds_within_received()');
        DB::statement('DROP INDEX IF EXISTS refunds_one_per_return');
        DB::statement('DROP INDEX IF EXISTS refunds_one_active_return');
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_resolution');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_resolution CHECK ((resolution IS NULL) = (resolved_at IS NULL) AND (resolution IS NULL OR (resolution = 'recorded' AND status = 'succeeded') OR (resolution = 'dismissed' AND status = 'failed' AND failure_reason = 'dismissed') OR (resolution = 'retried' AND status = 'failed' AND purpose = 'shipping')))");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_purpose');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_purpose CHECK (purpose IN ('cancel', 'stray', 'external', 'shipping') AND (purpose = 'stray') = (stray_payment_id IS NOT NULL))");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_provider_ref');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_provider_ref CHECK (status = 'requested' OR status = 'failed' OR provider_ref IS NOT NULL)");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_outside');
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_channel');
        DB::statement('ALTER TABLE refunds ALTER COLUMN payment_ref SET NOT NULL');
        DB::statement('ALTER TABLE refunds ALTER COLUMN attempt_id SET NOT NULL');
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_id');
            $table->dropColumn('channel');
        });

        Schema::dropIfExists('refund_lines');
        DB::statement('DROP FUNCTION IF EXISTS sugar_refund_lines_within_line()');
        Schema::dropIfExists('order_returns');
    }
};
