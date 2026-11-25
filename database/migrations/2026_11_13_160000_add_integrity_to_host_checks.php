<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ホストから見た状態に、中の照らしと外の見張りの生存を足す（ADR 0105）。ホストの ec-sugar integrity check が毎分照らし、
 * 変わった時だけ決まった語と時刻と困りごとの名前を渡す:
 *   integrity（name は空）      ok・mismatch と、結果が変わった時刻
 *   integrity（name は困りごと） あるべき姿との違いの名前（image:app・file:compose など）ごとに 1 行。result は mismatch
 *   sentinel                    外の見張り（ec-sugar-sentinel）が来ている（seen）・30 分来ていない（silent）と、最後に来た時刻。来たことが無ければ行は無い
 * 値・パス・ハッシュ・鍵は置かない。前のバージョンへ戻しても、前のプログラムは知らない kind の行を読まない（次の報告で消える）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_kind');
        DB::statement("ALTER TABLE host_checks ADD CONSTRAINT host_checks_kind CHECK (kind IN ('certificate', 'disk', 'update', 'backup', 'tunnel', 'integrity', 'sentinel'))");
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_shape');
        DB::statement(self::shape(true));
    }

    public function down(): void
    {
        DB::table('host_checks')->whereIn('kind', ['integrity', 'sentinel'])->delete();
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_shape');
        DB::statement(self::shape(false));
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_kind');
        DB::statement("ALTER TABLE host_checks ADD CONSTRAINT host_checks_kind CHECK (kind IN ('certificate', 'disk', 'update', 'backup', 'tunnel'))");
    }

    /**
     * 形の決まり（前のマイグレーションと同じもの。$integrity なら中の照らしと外の見張りの行を足す）
     */
    private static function shape(bool $integrity): string
    {
        $integrityRule = $integrity ? <<<'SQL'

                OR (kind = 'integrity' AND name = '' AND result IN ('ok', 'mismatch') AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'integrity' AND name ~ '^[a-z][a-z0-9-]*(:[a-z0-9][a-z0-9._-]*){1,2}$' AND length(name) <= 100 AND result = 'mismatch' AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'sentinel' AND name = '' AND result IN ('seen', 'silent') AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
            SQL : '';

        return <<<SQL
            ALTER TABLE host_checks ADD CONSTRAINT host_checks_shape CHECK (
                (kind = 'certificate' AND name ~ '^[a-z0-9.-]{1,253}$' AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL
                    AND ((result IS NULL AND at IS NOT NULL) OR (result = 'unreadable' AND at IS NULL)))
                OR (kind = 'disk' AND name IN ('data', 'backup') AND result IS NULL AND at IS NULL AND checked_at IS NULL AND version IS NULL
                    AND free_percent BETWEEN 0 AND 100 AND free_bytes >= 0)
                OR (kind = 'update' AND name = '' AND free_percent IS NULL AND free_bytes IS NULL
                    AND (result IS NULL OR result IN ('ok', 'rolled_back', 'failed', 'stopped', 'manual_rollback'))
                    AND (result IS NULL) = (at IS NULL) AND (result IS NOT NULL OR checked_at IS NOT NULL)
                    AND (version IS NULL OR (result IS NOT NULL AND version ~ '^[0-9a-f]{40}$')))
                OR (kind = 'backup' AND name = '' AND result IN ('ok', 'failed') AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'tunnel' AND name = '' AND result IN ('ok', 'foreign', 'down', 'unknown', 'unwatched') AND at IS NOT NULL
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL){$integrityRule}
            )
            SQL;
    }
};
