<?php

declare(strict_types=1);

use App\Admin\Models\Operator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 運営のメールアドレスの暗号化の段階 1（ADR 0112）: 照合用の値 email_index を足し、今ある行に入れる。
 *
 * メールアドレスはまだ平文のまま（暗号化するのは次のリリースの段階 2）。前のバージョンのコードは email を平文として
 * 読み書きするので、この段階では email に手を付けない。そうすれば、更新が失敗してイメージだけが前に戻っても
 * （ADR 0093 の 5 節。DB は移行した後のまま）、前のバージョンはこの DB の上でそのまま動く。
 * 前のバージョンが足した行は email_index が空なので、空を許す（段階 2 の移行が埋めてから NOT NULL にする）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->char('email_index', 64)->nullable()->unique();
        });

        foreach (DB::table('operators')->select(['id', 'email'])->lockForUpdate()->get() as $row) {
            DB::table('operators')->where('id', $row->id)->update(['email_index' => Operator::emailIndex((string) $row->email)]);
        }

        DB::statement("ALTER TABLE operators ADD CONSTRAINT operators_email_index_format CHECK (email_index IS NULL OR email_index ~ '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE operators DROP CONSTRAINT IF EXISTS operators_email_index_format');
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropUnique(['email_index']);
            $table->dropColumn('email_index');
        });
    }
};
