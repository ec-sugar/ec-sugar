<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 支払いの記録の状態に failed（預けたカードの請求が後で断られた。payment_intent.payment_failed。ADR 0045）を足す
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_status');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status CHECK (status IN ('open', 'paid', 'expired', 'failed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_status');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status CHECK (status IN ('open', 'paid', 'expired'))");
    }
};
