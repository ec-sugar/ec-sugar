<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 更新の行に「自動の更新を入れているか」を足す。update の行の name に yes・no を入れる（空 = 分からない。
 * この仕組みの前の台本からの報告）。入れていないサーバーでは、運営の様子は「取得元に 48 時間届いていない」を出さない。
 * 値・URL・鍵は置かない。前のバージョンへ戻しても、前のプログラムは name を読まないだけで壊れない
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_shape');
        DB::statement(self::shape(true));
    }

    public function down(): void
    {
        // 戻す前に、新しい形の行（name が yes・no の更新の行）を消す（次の報告で入れ直る）
        DB::table('host_checks')->where('kind', 'update')->whereIn('name', ['yes', 'no'])->delete();
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_shape');
        DB::statement(self::shape(false));
    }

    /**
     * 形の決まり（前のマイグレーションと同じもの。$auto なら更新の行の name に yes・no を許す）
     */
    private static function shape(bool $auto): string
    {
        $updateName = $auto ? "name IN ('', 'yes', 'no')" : "name = ''";
        // name だけの行（まだ更新も取得元の確認もしていないサーバー）も通す
        $updateHasSomething = $auto
            ? "(result IS NOT NULL OR checked_at IS NOT NULL OR name <> '')"
            : '(result IS NOT NULL OR checked_at IS NOT NULL)';

        return <<<SQL
            ALTER TABLE host_checks ADD CONSTRAINT host_checks_shape CHECK (
                (kind = 'certificate' AND name ~ '^[a-z0-9.-]{1,253}$' AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL
                    AND ((result IS NULL AND at IS NOT NULL) OR (result = 'unreadable' AND at IS NULL)))
                OR (kind = 'disk' AND name IN ('data', 'backup') AND result IS NULL AND at IS NULL AND checked_at IS NULL AND version IS NULL
                    AND free_percent BETWEEN 0 AND 100 AND free_bytes >= 0)
                OR (kind = 'update' AND {$updateName} AND free_percent IS NULL AND free_bytes IS NULL
                    AND (result IS NULL OR result IN ('ok', 'rolled_back', 'failed', 'stopped', 'manual_rollback'))
                    AND (result IS NULL) = (at IS NULL) AND {$updateHasSomething}
                    AND (version IS NULL OR (result IS NOT NULL AND version ~ '^[0-9a-f]{40}\$')))
                OR (kind = 'backup' AND name = '' AND result IN ('ok', 'failed') AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'tunnel' AND name = '' AND result IN ('ok', 'foreign', 'down', 'unknown', 'unwatched') AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'integrity' AND name = '' AND result IN ('ok', 'mismatch') AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'integrity' AND name ~ '^[a-z][a-z0-9-]*(:[a-z0-9][a-z0-9._-]*){1,2}\$' AND length(name) <= 100 AND result = 'mismatch' AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'sentinel' AND name = '' AND result IN ('seen', 'silent') AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
            )
            SQL;
    }
};
