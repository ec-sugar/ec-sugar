<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI エージェントの書き込み（ADR 0101）。
 *
 * - 店の設定 2 つ: 書き込みを「すぐ反映（immediate）」か「承認してから（approval。既定。空も承認）」、お客さんの情報を渡すか（既定は渡さない）
 * - 案（agent_proposals）: 承認してからの店で、エージェントが作った書き込みの案。店の人が管理画面で承認すると反映する。
 *   状態は pending（待ち）→ approved（承認して反映した）・rejected（却下）・expired（7 日で期限切れ）・failed（承認の時に確かめ直して断った・反映できなかった）。
 *   引数は店の鍵で暗号化する（送り状の番号は個人情報の扱い。ADR 0010）。要約（人が読む文）と照らす値（basis。改訂の番号・金額・状態）は平文で、個人情報を入れない。
 *   作った鍵と運営は中央の ID（外部キーは付けない。中央の表）。状態は下の CHECK で守り、変えるのは AgentProposals だけ（連鎖のロック・同じトランザクションのイベント）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->string('agent_write_mode', 16)->nullable();
            $table->boolean('agent_customer_data')->default(false);
        });
        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_agent_write_mode CHECK (agent_write_mode IS NULL OR agent_write_mode IN ('immediate', 'approval'))");

        Schema::create('agent_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tool', 32);
            // 承認に要る段階（operate・money）
            $table->string('level', 8);
            $table->text('arguments');                   // 引数（暗号化した JSON）
            $table->jsonb('basis');                      // 作った時の照らす値（承認の時に作り直して比べる）
            $table->text('summary');                     // 人が読む要約（「クッキー缶の値段を ¥2,000 → ¥2,200」）
            $table->char('token_id', 26);                // 作った AI エージェントの鍵（中央）
            $table->char('operator_id', 26);             // 鍵の持ち主（中央）
            $table->string('status', 16)->default('pending');
            $table->char('decided_by', 26)->nullable();  // 承認・却下した運営（中央）
            $table->timestampTz('decided_at')->nullable();
            $table->jsonb('result')->nullable();         // 反映の結果（作った ID など）か、断った理由の文
            $table->timestampTz('expires_at');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE agent_proposals ADD CONSTRAINT agent_proposals_tool CHECK (tool IN ('create_product', 'update_product', 'adjust_stock', 'mark_shipped', 'cancel_order', 'refund_order', 'create_coupon'))");
        DB::statement("ALTER TABLE agent_proposals ADD CONSTRAINT agent_proposals_level CHECK (level IN ('operate', 'money'))");
        DB::statement("ALTER TABLE agent_proposals ADD CONSTRAINT agent_proposals_status CHECK (status IN ('pending', 'approved', 'rejected', 'expired', 'failed'))");
        // 待ちの間は決めた人も時刻も結果も無い。決まったら時刻がある。承認と却下は人が決める
        DB::statement("ALTER TABLE agent_proposals ADD CONSTRAINT agent_proposals_decision CHECK ((status = 'pending') = (decided_at IS NULL) AND (status <> 'pending' OR (decided_by IS NULL AND result IS NULL)) AND (status NOT IN ('approved', 'rejected') OR decided_by IS NOT NULL))");
        DB::statement("ALTER TABLE agent_proposals ADD CONSTRAINT agent_proposals_ids CHECK (token_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$' AND operator_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$' AND (decided_by IS NULL OR decided_by ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$'))");
        DB::statement("ALTER TABLE agent_proposals ADD CONSTRAINT agent_proposals_json CHECK (jsonb_typeof(basis) = 'object' AND (result IS NULL OR jsonb_typeof(result) = 'object'))");
        DB::statement("ALTER TABLE agent_proposals ADD CONSTRAINT agent_proposals_summary CHECK (btrim(summary) <> '' AND length(summary) <= 2000)");
        DB::statement('ALTER TABLE agent_proposals ADD CONSTRAINT agent_proposals_expiry CHECK (expires_at > created_at)');
        DB::statement("CREATE INDEX agent_proposals_pending ON agent_proposals (expires_at) WHERE status = 'pending'");
        DB::statement('CREATE INDEX agent_proposals_recent ON agent_proposals (created_at DESC, id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_proposals');
        DB::statement('ALTER TABLE shop_settings DROP CONSTRAINT IF EXISTS shop_settings_agent_write_mode');
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn(['agent_write_mode', 'agent_customer_data']);
        });
    }
};
