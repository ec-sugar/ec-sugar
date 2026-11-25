<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ほかの記録にまとめられた台帳は会員にならない（ADR 0044 の 2、2026-11-03 の運営の判断で「ログインを断る」）。
 * サービス（CustomerMerges・CustomerLogin）で断り、DB でも断る。ログインの印・パスキーは会員の台帳だけを引くので、これで入口がそろって閉じる
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customer_accounts ADD CONSTRAINT customer_accounts_merged_not_member CHECK (merged_into_id IS NULL OR member_since IS NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customer_accounts DROP CONSTRAINT IF EXISTS customer_accounts_merged_not_member');
    }
};
