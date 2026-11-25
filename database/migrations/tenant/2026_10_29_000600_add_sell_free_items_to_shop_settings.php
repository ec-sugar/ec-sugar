<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0 円の品を売るか。既定は売らない。
 * 0 円の注文は支払いを経ずに確定し、注文のメールを入力されたメール宛てに出す。売る店だけにして、他人宛てのメールを出させる・品を空にする悪用を防ぐ（運営の判断）。
 * 足すだけなので、古いイメージでもそのまま動く
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE shop_settings ADD COLUMN sell_free_items boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_settings DROP COLUMN sell_free_items');
    }
};
