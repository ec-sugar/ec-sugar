<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会員のログイン（ADR 0044 の 3）。メールの 6 桁のコードと、入った後の会員の印。
 *
 * - コード: メールの索引ごと。保存は店の鍵の HMAC だけ（平文のコードは DB に置かない）。10 分・1 回だけ・5 回まで
 * - 会員の印: ブラウザの Cookie の合い言葉の SHA-256 だけを置く（DB が漏れても印は作れない）。30 日、使うたびに延ばす。取り消せる
 * どちらも状態でもお金でもないので、連鎖のロックとイベントの外（conventions.md の例外の一覧）。守りは行のロックと一意の制約
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_login_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('email_index', 64);
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');

            $table->index(['email_index', 'created_at']);
        });

        Schema::create('customer_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at');

            $table->index(['customer_account_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sessions');
        Schema::dropIfExists('customer_login_codes');
    }
};
