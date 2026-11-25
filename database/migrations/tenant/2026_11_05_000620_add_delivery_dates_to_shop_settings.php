<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * お届け日時の指定の決まり（ADR 0059）: 日付の指定を受けるか・受ける時間帯・発送までの日数・配達の日数・選べる日数・
 * 締め切りの時刻・休みの曜日と日。形は DeliveryPolicy が決める（読むときに崩れた値は「受けない」に倒す）。
 * 空（null）は「受けない」。足すだけなので、古いイメージでもそのまま動く
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE shop_settings ADD COLUMN delivery_dates jsonb NULL');
        DB::statement("ALTER TABLE shop_settings ADD CONSTRAINT shop_settings_delivery_dates_object CHECK (delivery_dates IS NULL OR jsonb_typeof(delivery_dates) = 'object')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_settings DROP CONSTRAINT shop_settings_delivery_dates_object');
        DB::statement('ALTER TABLE shop_settings DROP COLUMN delivery_dates');
    }
};
