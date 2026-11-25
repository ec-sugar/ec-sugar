<?php

declare(strict_types=1);

use App\Admin\Models\Operator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 運営のメールアドレスの暗号化の段階 2（ADR 0112）: 今ある行を暗号化し、平文を DB が断る。
 *
 * 段階 1（2026_11_21_000100）が本番に入った後のリリースで流す。段階 1 のコードは暗号文と平文の両方を読み、照合用の値で探すので、
 * 更新が失敗してイメージだけが段階 1 に戻っても（ADR 0093 の 5 節）、ログインと探すのは動く。
 * 1 つのトランザクションの中で行う（途中で止まれば何も変わらない）。UPDATE の前の平文の行は、次の移行の VACUUM FULL で消す。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE operators DROP CONSTRAINT IF EXISTS operators_email_lowercase');
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropUnique(['email']);
        });
        DB::statement('ALTER TABLE operators ALTER COLUMN email TYPE text');

        foreach (DB::table('operators')->select(['id', 'email', 'email_index'])->lockForUpdate()->get() as $row) {
            $email = (string) $row->email;

            // 段階 1 の間に暗号文になった行は無いはずだが、もしあっても二重に包まない（暗号文には @ が無い）
            if (! str_contains($email, '@')) {
                $email = Crypt::decryptString($email);
            }

            $email = Operator::normalizeEmail($email);
            DB::table('operators')->where('id', $row->id)->update([
                'email' => Crypt::encryptString($email),
                'email_index' => Operator::emailIndex($email),
            ]);
        }

        DB::statement('ALTER TABLE operators ALTER COLUMN email_index SET NOT NULL');
        DB::statement('ALTER TABLE operators DROP CONSTRAINT IF EXISTS operators_email_index_format');
        DB::statement("ALTER TABLE operators ADD CONSTRAINT operators_email_index_format CHECK (email_index ~ '^[0-9a-f]{64}$')");
        // 暗号文（base64 の JSON）に @ は出ない。平文のアドレスを書いたら DB が断る
        DB::statement("ALTER TABLE operators ADD CONSTRAINT operators_email_sealed CHECK (position('@' in email) = 0)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE operators DROP CONSTRAINT IF EXISTS operators_email_sealed');
        DB::statement('ALTER TABLE operators DROP CONSTRAINT IF EXISTS operators_email_index_format');
        DB::statement("ALTER TABLE operators ADD CONSTRAINT operators_email_index_format CHECK (email_index IS NULL OR email_index ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE operators ALTER COLUMN email_index DROP NOT NULL');

        foreach (DB::table('operators')->select(['id', 'email'])->lockForUpdate()->get() as $row) {
            $email = (string) $row->email;
            DB::table('operators')->where('id', $row->id)->update(['email' => str_contains($email, '@') ? $email : Crypt::decryptString($email)]);
        }

        DB::statement('ALTER TABLE operators ALTER COLUMN email TYPE varchar(254)');
        Schema::table('operators', function (Blueprint $table): void {
            $table->unique('email');
        });
        DB::statement('ALTER TABLE operators ADD CONSTRAINT operators_email_lowercase CHECK (email = lower(email))');
    }
};
