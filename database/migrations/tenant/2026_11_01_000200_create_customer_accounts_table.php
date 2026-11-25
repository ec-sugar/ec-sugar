<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * お客さんの台帳（ADR 0044 の 1）。店ごとに 1 表、メールの完全一致（email_index）で 1 人。
 * 注文（transactions）に customer_account_id を足し、今までの注文もここでつなぐ（店ごとに 1 回）。
 *
 * - メールは Party と同じ店の鍵で暗号化。索引も同じ正規化（前後の空白と大文字小文字）なので、今までの注文は Party の暗号文と索引をそのまま写す（復号しない）
 * - email_index は一意（同時の 2 つの注文で 2 行にしない。注文の確定は連鎖のロックの中で引くので、ここは DB の 2 枚目の守り）
 * - 名前・住所・電話は写さない（注文ごとの Party に残す）
 * - 写した台帳にはイベントを残さない（移行は人の操作でも注文でもない。作った時刻は最初の注文の時刻）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('email');                                // 暗号化
            $table->char('email_index', 64)->unique();
            $table->timestampTz('member_since')->nullable();     // メールのコードで初めて入った時。空ならゲストだけ
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignUlid('customer_account_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->index(['customer_account_id', 'created_at']);
        });

        // 今までの注文: 買い手のメールが同じものを 1 人にまとめる。メールの暗号文は最後の注文のもの
        $rows = DB::table('transactions')->join('parties', 'parties.id', '=', 'transactions.customer_id')
            ->whereNotNull('parties.email_index')->whereNotNull('parties.email')
            ->orderBy('transactions.created_at')->orderBy('transactions.id')
            ->get(['transactions.id', 'transactions.created_at', 'parties.email', 'parties.email_index']);

        $accounts = [];
        foreach ($rows as $row) {
            $index = (string) $row->email_index;
            $accounts[$index] ??= ['first' => $row->created_at, 'email' => $row->email, 'transactions' => []];
            $accounts[$index]['email'] = $row->email;
            $accounts[$index]['transactions'][] = $row->id;
        }

        foreach ($accounts as $index => $account) {
            $id = strtolower((string) Str::ulid());
            DB::table('customer_accounts')->insert([
                'id' => $id,
                'email' => $account['email'],
                'email_index' => $index,
                'created_at' => $account['first'],
                'updated_at' => $account['first'],
            ]);

            foreach (array_chunk($account['transactions'], 500) as $chunk) {
                DB::table('transactions')->whereIn('id', $chunk)->update(['customer_account_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_account_id');
        });

        Schema::dropIfExists('customer_accounts');
    }
};
