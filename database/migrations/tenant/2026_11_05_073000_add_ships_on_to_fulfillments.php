<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 発送を始める日（ADR 0073 予約販売）。予約の品のある注文の受渡は、発売日（いちばん遅い発売日）まで発送できない。
 * 日は日本の暦の日付。空なら今までどおり（いつでも発送できる）。決まりは FulfillmentService が守る（発送・完了にする時に今日と比べる）。
 * 足すだけなので、古いイメージでもそのまま動く（古いイメージは列を読まない）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE fulfillments ADD COLUMN ships_on date NULL');
        // 発送を始める日を持てるのは出す受渡（配送・手渡し）だけ。受ける受渡（買取の送付・持込）には持たせない
        DB::statement("ALTER TABLE fulfillments ADD CONSTRAINT fulfillments_ships_on_outbound CHECK (ships_on IS NULL OR method <> 'inbound')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fulfillments DROP CONSTRAINT fulfillments_ships_on_outbound');
        DB::statement('ALTER TABLE fulfillments DROP COLUMN ships_on');
    }
};
