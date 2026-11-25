<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ギフトカード（商品券。ADR 0076）。売るのは App「ギフトカード」、残高の帳簿と注文の支払いへの充て方は核。1 円 = 1 円。
 *
 * - gift_cards: 発行したカード。コードそのものは持たず、店の鍵の HMAC（sha256）と末尾 4 文字だけ。額面・期限・止めた時刻と理由（staff 店の人・refund 売った注文の返金）・
 *   コードを届けた時刻（買った人・贈る相手）・出し直した回数（3 回まで）・テストの支払いで売った印（本番の鍵の店では使えない）・売った注文（App の注文。1 つの注文に 1 枚）。
 *   止める・コードを出し直す（届く前だけ）・届けた時刻はこの行を直す（サービス経由で、イベントを残す）
 * - gift_card_entries: 残高の帳簿（追記だけ。UPDATE・DELETE・TRUNCATE を断る）。種類は issue（発行）・spend（注文に使った）・release（使った分を戻した）・
 *   expire（期限で消えた）・adjust（店の人の手で足し引き）。数は符号つきの円。残高は カードごとの最後の行の balance_after で、DB のトリガーが前の行から
 *   計算し直して入れ、負になる行は断る（アプリの計算をすり抜けても）。カードごとの連番 card_seq もトリガーが入れ、(カード, card_seq) の一意の索引で守る
 *   （ポイントの帳簿〔ADR 0068〕と同じ形。同じ残高から 2 回引けない）。戻す（release）には理由（cause: cancel 取消・払われずに消えた、shipping 贈り物の送料の差額、
 *   refund 返金で店の人が戻した）があり、理由ごとに 1 回。戻す合計は注文に使った額までで、トリガーも守る
 * - order_gift_cards: 注文ごとに使ったカードと額（注文を作る時に 1 回だけ書き、後から変えない）。お支払いの額 = 注文の合計 − この額
 *
 * 個人情報は入れない。店の人の手の足し引きの理由は自由な文なので、店の鍵で暗号化して持つ（ADR 0010。列は暗号文）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('code_hash', 64)->unique();            // コードの店の鍵の HMAC（16 文字の形にそろえてから。TenantCipher::index）
            $table->char('last4', 4);                           // コードの末尾 4 文字（店の人とお客さんが見分けるため）
            $table->bigInteger('amount');                       // 額面（円）
            $table->foreignUlid('purchase_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('stopped_at')->nullable();      // 止めた（盗難の申し出など）。止めたカードは使えない
            $table->string('stopped_reason', 16)->nullable();   // staff（店の人）・refund（売った注文が返金された。ホームのやることに出る）
            $table->timestampTz('mailed_at')->nullable();       // コードを買った人にメールで届けた
            $table->timestampTz('recipient_mailed_at')->nullable(); // 贈る相手にも届けた
            $table->smallInteger('reissues')->default(0);       // 届かなかったコードを出し直した回数（3 回まで）
            $table->boolean('test')->default(false);            // テストの支払い（Stripe のテストのモード）で売ったカード
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE gift_cards ADD CONSTRAINT gift_cards_amount CHECK (amount BETWEEN 1 AND 1000000)');
        DB::statement("ALTER TABLE gift_cards ADD CONSTRAINT gift_cards_stop CHECK ((stopped_at IS NULL) = (stopped_reason IS NULL) AND (stopped_reason IS NULL OR stopped_reason IN ('staff', 'refund')) AND reissues BETWEEN 0 AND 3)");
        DB::statement("ALTER TABLE gift_cards ADD CONSTRAINT gift_cards_hash_shape CHECK (code_hash ~ '^[0-9a-f]{64}$' AND last4 ~ '^[2-9A-HJKMNP-Z]{4}$')");

        Schema::create('gift_card_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->bigInteger('seq')->unique();                // 店全体の書いた順の連番（DB が振る）
            $table->integer('card_seq');                        // カードごとの連番（1 から。トリガーが前の行 + 1 を入れる）
            $table->foreignUlid('gift_card_id')->constrained('gift_cards')->restrictOnDelete();
            $table->string('kind', 16);
            $table->bigInteger('amount');                       // 符号つきの円（issue・release は正、spend・expire は負、adjust は 0 以外）
            $table->bigInteger('balance_after')->default(0);    // この行の後の残高（トリガーが入れる）
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->string('cause', 16)->nullable();            // 戻した理由（release だけ: cancel・shipping・refund）
            $table->text('note')->nullable();                   // 店の人の手の足し引きの理由（暗号文。adjust だけ）
            $table->string('actor', 64)->nullable();
            $table->timestampTz('occurred_at');

            $table->unique(['gift_card_id', 'card_seq']);
            $table->index('transaction_id');
        });

        DB::statement('ALTER TABLE gift_card_entries ALTER COLUMN seq ADD GENERATED ALWAYS AS IDENTITY');
        DB::statement("ALTER TABLE gift_card_entries ADD CONSTRAINT gift_card_entries_kind_known CHECK (kind IN ('issue', 'spend', 'release', 'expire', 'adjust'))");
        DB::statement(<<<'SQL'
            ALTER TABLE gift_card_entries ADD CONSTRAINT gift_card_entries_sign CHECK (
                (kind IN ('issue', 'release') AND amount > 0)
                OR (kind IN ('spend', 'expire') AND amount < 0)
                OR (kind = 'adjust' AND amount <> 0)
            )
            SQL);
        DB::statement('ALTER TABLE gift_card_entries ADD CONSTRAINT gift_card_entries_range CHECK (amount BETWEEN -1000000 AND 1000000)');
        DB::statement('ALTER TABLE gift_card_entries ADD CONSTRAINT gift_card_entries_balance_nonnegative CHECK (balance_after >= 0)');
        DB::statement("ALTER TABLE gift_card_entries ADD CONSTRAINT gift_card_entries_order_kinds CHECK ((kind IN ('spend', 'release')) = (transaction_id IS NOT NULL))");
        DB::statement("ALTER TABLE gift_card_entries ADD CONSTRAINT gift_card_entries_note_only_adjust CHECK (note IS NULL OR kind = 'adjust')");
        DB::statement("ALTER TABLE gift_card_entries ADD CONSTRAINT gift_card_entries_cause_known CHECK ((kind = 'release') = (cause IS NOT NULL) AND (cause IS NULL OR cause IN ('cancel', 'shipping', 'refund')))");
        // 1 つの注文に使うは 1 回、戻すは理由ごとに 1 回（同じ操作・同じ知らせが 2 度来ても 2 行目を DB が断る）
        DB::statement("CREATE UNIQUE INDEX gift_card_entries_once_per_order ON gift_card_entries (transaction_id, kind, (COALESCE(cause, ''))) WHERE kind IN ('spend', 'release')");
        // 発行はカードの最初の行で 1 回だけ
        DB::statement("CREATE UNIQUE INDEX gift_card_entries_one_issue ON gift_card_entries (gift_card_id) WHERE kind = 'issue'");

        // 残高とカードごとの連番は前の行から DB が計算し直す（アプリの値を信じない）。同じカードの書き込みはカードの行の錠で順番にし、
        // それもすり抜けて 2 つが同じ前の行を読んだら、同じ card_seq になるので一意の索引が後の方を断る
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sugar_gift_card_balance() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                previous bigint;
                previous_seq integer;
                releasable bigint;
            BEGIN
                PERFORM 1 FROM gift_cards WHERE id = NEW.gift_card_id FOR UPDATE;
                SELECT balance_after, card_seq INTO previous, previous_seq FROM gift_card_entries WHERE gift_card_id = NEW.gift_card_id ORDER BY card_seq DESC LIMIT 1;
                IF NEW.kind = 'issue' AND previous_seq IS NOT NULL THEN
                    RAISE EXCEPTION 'gift_card_entries_issue_first: 発行はカードの最初の行だけです';
                END IF;
                IF NEW.kind <> 'issue' AND previous_seq IS NULL THEN
                    RAISE EXCEPTION 'gift_card_entries_issue_first: 発行の前のカードは動かせません';
                END IF;
                IF NEW.kind = 'release' THEN
                    SELECT -COALESCE(SUM(amount), 0) INTO releasable FROM gift_card_entries
                     WHERE gift_card_id = NEW.gift_card_id AND transaction_id = NEW.transaction_id AND kind IN ('spend', 'release');
                    IF NEW.amount > releasable THEN
                        RAISE EXCEPTION 'gift_card_entries_release_over_spent: 戻せるのは注文に使った額までです（残り %、今回 %）', releasable, NEW.amount;
                    END IF;
                END IF;
                NEW.card_seq := COALESCE(previous_seq, 0) + 1;
                NEW.balance_after := COALESCE(previous, 0) + NEW.amount;
                IF NEW.balance_after < 0 THEN
                    RAISE EXCEPTION 'gift_card_entries_negative_balance: ギフトカードの残高は負にできません（残高 %、今回 %）', COALESCE(previous, 0), NEW.amount;
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
        DB::statement('CREATE TRIGGER gift_card_entries_balance BEFORE INSERT ON gift_card_entries FOR EACH ROW EXECUTE FUNCTION sugar_gift_card_balance()');
        DB::statement('CREATE TRIGGER gift_card_entries_append_only BEFORE UPDATE OR DELETE ON gift_card_entries FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER gift_card_entries_no_truncate BEFORE TRUNCATE ON gift_card_entries FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');

        Schema::create('order_gift_cards', function (Blueprint $table): void {
            $table->foreignUlid('transaction_id')->primary()->constrained('transactions')->restrictOnDelete();
            $table->foreignUlid('gift_card_id')->constrained('gift_cards')->restrictOnDelete();
            $table->bigInteger('amount');                       // この注文に使った額（円）
            $table->timestampTz('created_at');

            $table->index('gift_card_id');
        });

        DB::statement('ALTER TABLE order_gift_cards ADD CONSTRAINT order_gift_cards_amount CHECK (amount BETWEEN 1 AND 1000000)');
        DB::statement('CREATE TRIGGER order_gift_cards_append_only BEFORE UPDATE OR DELETE ON order_gift_cards FOR EACH ROW EXECUTE FUNCTION sugar_forbid_mutation()');
        DB::statement('CREATE TRIGGER order_gift_cards_no_truncate BEFORE TRUNCATE ON order_gift_cards FOR EACH STATEMENT EXECUTE FUNCTION sugar_forbid_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_gift_cards');
        Schema::dropIfExists('gift_card_entries');
        Schema::dropIfExists('gift_cards');
        DB::statement('DROP FUNCTION IF EXISTS sugar_gift_card_balance()');
    }
};
