<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 処理を追う ID（trace_id。ADR 0087）を、店の記録に添える列。どれも空でよい（前からの行と、ID の無い処理で作った行）。
 *
 * - domain_events: 書いた処理。ハッシュの本文には入れない（EventStore）。列を足すだけで、追記だけのトリガーはそのまま効く
 * - sent_mails: 知らせのメールを送った処理（キューの仕事は積んだ要求の ID を引き継ぐ）
 * - refunds・checkout_plans: 決済代行へ頼む冪等キーの横に、頼んだ処理
 * - webhook_events: 決済代行の通知を受けた処理
 *
 * 値はこちらで作る ULID（小文字の 26 文字）だけ。形を DB でも確かめる。引くための索引は値のある行だけに張る
 */
return new class extends Migration
{
    private const array TABLES = ['domain_events', 'sent_mails', 'refunds', 'checkout_plans', 'webhook_events'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN trace_id char(26) NULL");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_trace_id_shape CHECK (trace_id IS NULL OR trace_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$')");
            DB::statement("CREATE INDEX {$table}_trace_id ON {$table} (trace_id) WHERE trace_id IS NOT NULL");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN trace_id");
        }
    }
};
