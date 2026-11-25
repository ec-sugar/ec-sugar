<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 運営のメールアドレスを暗号化した後に、表を書き直す（ADR 0112 の段階 2）。
 * UPDATE の前の行（平文のアドレス）は、VACUUM まで表のファイルに残る。VACUUM FULL で表を作り直して消す（行は数件で、一瞬で終わる）。
 * VACUUM はトランザクションの中では動かないので、この移行だけトランザクションの外で流す。
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('VACUUM FULL operators');
    }

    public function down(): void
    {
        // 戻すものは無い
    }
};
