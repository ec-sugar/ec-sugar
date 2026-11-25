<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * お届けの日と時間帯の指定（ADR 0059）。受渡に持たせる（個人情報ではないので平文）。
 * 日は日本の暦の日付、時間帯は運送会社の共通の区分（DeliverySlot）だけ。指定できるのは配送の受渡だけ（DB でも守る）。
 * 足すだけなので、古いイメージでもそのまま動く（古いイメージは列を読まない）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE fulfillments ADD COLUMN delivery_date date NULL');
        DB::statement('ALTER TABLE fulfillments ADD COLUMN delivery_slot varchar(8) NULL');
        DB::statement("ALTER TABLE fulfillments ADD CONSTRAINT fulfillments_delivery_slot_known CHECK (delivery_slot IS NULL OR delivery_slot IN ('am', '14-16', '16-18', '18-20', '19-21'))");
        DB::statement("ALTER TABLE fulfillments ADD CONSTRAINT fulfillments_delivery_only_shipping CHECK (method = 'shipping' OR (delivery_date IS NULL AND delivery_slot IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fulfillments DROP CONSTRAINT fulfillments_delivery_only_shipping');
        DB::statement('ALTER TABLE fulfillments DROP CONSTRAINT fulfillments_delivery_slot_known');
        DB::statement('ALTER TABLE fulfillments DROP COLUMN delivery_slot');
        DB::statement('ALTER TABLE fulfillments DROP COLUMN delivery_date');
    }
};
