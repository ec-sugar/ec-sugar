<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 支払いの記録の種類（ADR 0045・0049）: checkout（お客さんが支払いの画面で払う）と stored（預けたカードでお客さんのいない請求）。
 * stored の記録は、請求の前に仮の参照（stored:<取引>:<何回目>）で作り、請求の答えの支払いの ID に置き換える。確定は Webhook
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('kind', 16)->default('checkout');
        });

        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_kind CHECK (kind IN ('checkout', 'stored'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT IF EXISTS payment_attempts_kind');

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
