<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 預けたカードの請求の冪等キー（stored:<取引>:<何回目>）を、支払いの記録に残す（provider_ref は答えの支払いの ID に置き換わるので、
 * 同じ回数の請求を二度記録しない見分けに別の列を使う）。一意
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('charge_key', 128)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('charge_key');
        });
    }
};
