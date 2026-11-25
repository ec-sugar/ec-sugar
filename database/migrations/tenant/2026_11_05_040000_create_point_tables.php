<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ポイント（ADR 0068）。会員だけが貯めて使える。1 ポイント = 1 円。
 *
 * - point_entries: ポイントの帳簿（追記だけ。UPDATE・DELETE・TRUNCATE を断る）。種類は earn（付けた）・spend（使った）・release（使った分を戻した）・
 *   revoke（付けた分を取り消した）・expire（期限・退会で消えた）・adjust（店の人の手で足し引き）。数は符号つき。
 *   残高は会員ごとの最後の行の balance_after で、DB のトリガーが前の行から計算し直して入れ、負になる行は断る（アプリの計算をすり抜けても）。
 *   会員ごとの連番 account_seq もトリガーが「前の行 + 1」で入れ、(会員, account_seq) の一意の索引で守る。連鎖のロックも台帳の行の錠もすり抜けた 2 つの書き込みが
 *   同じ前の行を読んでも、後の方は一意の索引で断られる（同じ残高から 2 回引けない）
 *   expires_at は最後に動いた時（付く・使う・戻す・手で足し引き）から 1 年で、アプリが入れる（取り消し・消えた行は前の期限のまま）
 * - order_points: 注文ごとのポイントの決まり（使った数・付く予定の数・注文の時の率・割り振り）。注文を作る時に 1 回だけ書き、後から変えない
 * - shop_settings: 使う／使わない（既定は使う）・率（万分率。既定 100 = 1%、0〜2000 = 0〜20%）
 *
 * 個人情報は入れない（お客さんは台帳の ID だけ）。店の人の手の足し引きの理由は自由な文なので、店の鍵で暗号化して持つ（ADR 0010。列は暗号文）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->bigInteger('seq')->unique();                // 店全体の書いた順の連番（DB が振る）
            $table->integer('account_seq');                     // 会員ごとの連番（1 から。トリガーが前の行 + 1 を入れる。残高の計算の順）
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->string('kind', 16);
            $table->bigInteger('points');                       // 符号つき（earn・release は正、spend・revoke・expire は負か 0、adjust は 0 以外）
            $table->bigInteger('balance_after')->default(0);    // この行の後の残高（トリガーが入れる）
            $table->bigInteger('shortfall')->default(0);        // 取り消しで残高が足りずに引けなかった数（revoke だけ）
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->string('cause', 16)->nullable();            // 決まった理由（expire: inactive・left、revoke: refund、release: shipping〔贈り物の送料の差額をポイントで戻した〕）
            $table->text('note')->nullable();                   // 店の人の手の足し引きの理由（暗号文。adjust だけ）
            $table->string('actor', 64)->nullable();
            $table->timestampTz('expires_at')->nullable();      // この行の後の、残り全部の期限
            $table->timestampTz('occurred_at');

            $table->unique(['customer_account_id', 'account_seq']);
            $table->index('transaction_id');
        });

        DB::statement('ALTER TABLE point_entries ALTER COLUMN seq ADD GENERATED ALWAYS AS IDENTITY');
        DB::statement("ALTER TABLE point_entries ADD CONSTRAINT point_entries_kind_known CHECK (kind IN ('earn', 'spend', 'release', 'revoke', 'expire', 'adjust'))");
        DB::statement(<<<'SQL'
            ALTER TABLE point_entries ADD CONSTRAINT point_entries_sign CHECK (
                (kind IN ('earn', 'release') AND points > 0)
                OR (kind = 'spend' AND points < 0)
                OR (kind IN ('revoke', 'expire') AND points <= 0)
                OR (kind = 'adjust' AND points <> 0)
            )
            SQL);
        DB::statement('ALTER TABLE point_entries ADD CONSTRAINT point_entries_range CHECK (points BETWEEN -100000000 AND 100000000 AND shortfall BETWEEN 0 AND 100000000)');
        DB::statement('ALTER TABLE point_entries ADD CONSTRAINT point_entries_balance_nonnegative CHECK (balance_after >= 0)');
        DB::statement("ALTER TABLE point_entries ADD CONSTRAINT point_entries_order_kinds CHECK ((kind IN ('earn', 'spend', 'release', 'revoke')) = (transaction_id IS NOT NULL))");
        DB::statement("ALTER TABLE point_entries ADD CONSTRAINT point_entries_note_only_adjust CHECK (note IS NULL OR kind = 'adjust')");
        DB::statement("ALTER TABLE point_entries ADD CONSTRAINT point_entries_shortfall_only_revoke CHECK (shortfall = 0 OR kind = 'revoke')");
        // 1 つの注文に付ける・使う・戻す・取り消すは 1 回ずつ（同じ知らせ・同じ操作が 2 度来ても 2 行目を DB が断る）。
        // 戻すは理由ごと（取消・期限切れ・全額の返金の戻しと、贈り物の送料の差額の戻し〔cause = shipping〕を 1 回ずつ）
        DB::statement("CREATE UNIQUE INDEX point_entries_once_per_order ON point_entries (transaction_id, kind, (COALESCE(cause, ''))) WHERE kind IN ('earn', 'spend', 'release', 'revoke')");
        DB::statement("ALTER TABLE point_entries ADD CONSTRAINT point_entries_cause_known CHECK (cause IS NULL OR (kind = 'expire' AND cause IN ('inactive', 'left')) OR (kind = 'revoke' AND cause = 'refund') OR (kind = 'release' AND cause = 'shipping'))");

        // 残高と会員ごとの連番は前の行から DB が計算し直す（アプリの値を信じない）。同じ会員の書き込みは台帳の行の錠で順番にし、
        // それもすり抜けて 2 つが同じ前の行を読んだら、同じ account_seq になるので一意の索引が後の方を断る
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_point_balance() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                previous bigint;
                previous_seq integer;
            BEGIN
                PERFORM 1 FROM customer_accounts WHERE id = NEW.customer_account_id FOR UPDATE;
                SELECT balance_after, account_seq INTO previous, previous_seq FROM point_entries WHERE customer_account_id = NEW.customer_account_id ORDER BY account_seq DESC LIMIT 1;
                NEW.account_seq := COALESCE(previous_seq, 0) + 1;
                NEW.balance_after := COALESCE(previous, 0) + NEW.points;
                IF NEW.balance_after < 0 THEN
                    RAISE EXCEPTION 'point_entries_negative_balance: ポイントの残高は負にできません（残高 %、今回 %）', COALESCE(previous, 0), NEW.points;
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::statement('CREATE TRIGGER point_entries_balance BEFORE INSERT ON point_entries FOR EACH ROW EXECUTE FUNCTION sugar_point_balance()');
        DB::statement('CREATE TRIGGER point_entries_append_only BEFORE UPDATE OR DELETE ON point_entries FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER point_entries_no_truncate BEFORE TRUNCATE ON point_entries FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');

        Schema::create('order_points', function (Blueprint $table): void {
            $table->foreignUlid('transaction_id')->primary()->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->integer('rate_bp');                         // 注文の時の率（万分率）
            $table->bigInteger('spent')->default(0);            // 使ったポイント（= 値引きの円）
            $table->bigInteger('earn')->default(0);             // 発送で付く予定のポイント
            $table->bigInteger('earn_base')->default(0);        // 付ける額の元（商品の代金。値引きと使ったポイントの後、税込み、送料を除く）
            $table->jsonb('allocations')->default('{}');        // 使ったポイントの明細の位置ごとの割り振り（商品・オプション・送料の行）
            $table->timestampTz('created_at');

            $table->index('customer_account_id');
        });

        DB::statement('ALTER TABLE order_points ADD CONSTRAINT order_points_values CHECK (rate_bp BETWEEN 0 AND 2000 AND spent >= 0 AND earn >= 0 AND earn_base >= 0)');
        DB::statement('CREATE TRIGGER order_points_append_only BEFORE UPDATE OR DELETE ON order_points FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER order_points_no_truncate BEFORE TRUNCATE ON order_points FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');

        DB::statement('ALTER TABLE shop_settings ADD COLUMN points_enabled boolean NOT NULL DEFAULT true');
        DB::statement('ALTER TABLE shop_settings ADD COLUMN points_rate_bp integer NOT NULL DEFAULT 100');
        DB::statement('ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_points_rate CHECK (points_rate_bp BETWEEN 0 AND 2000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_settings DROP CONSTRAINT shop_settings_points_rate');
        DB::statement('ALTER TABLE shop_settings DROP COLUMN points_rate_bp');
        DB::statement('ALTER TABLE shop_settings DROP COLUMN points_enabled');
        Schema::dropIfExists('order_points');
        Schema::dropIfExists('point_entries');
        DB::statement('DROP FUNCTION IF EXISTS sugar_point_balance()');
    }
};
