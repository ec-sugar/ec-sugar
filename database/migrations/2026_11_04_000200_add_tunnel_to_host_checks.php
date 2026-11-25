<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ホストから見た状態に、Cloudflare Tunnel の見張りの結果を足す（見直しの中 5、ADR 0052）。
 * ホストの ec-sugar watch が、トンネルの接続役のつなぎ元がこのサーバー自身かを確かめ、決まった語と時刻だけを渡す
 * （ok・foreign〔よそからつながっている〕・down〔接続役が 0〕・unknown〔確かめられない〕・unwatched〔読むだけの鍵が無い〕）。
 * 住所・鍵・API の答えは置かない
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_kind');
        DB::statement("ALTER TABLE host_checks ADD CONSTRAINT host_checks_kind CHECK (kind IN ('certificate', 'disk', 'update', 'backup', 'tunnel'))");
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_shape');
        DB::statement(self::shape(true));
    }

    public function down(): void
    {
        DB::table('host_checks')->where('kind', 'tunnel')->delete();
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_shape');
        DB::statement(self::shape(false));
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_kind');
        DB::statement("ALTER TABLE host_checks ADD CONSTRAINT host_checks_kind CHECK (kind IN ('certificate', 'disk', 'update', 'backup'))");
    }

    /**
     * 形の決まり（前のマイグレーションと同じもの。$tunnel なら見張りの行を足す）
     */
    private static function shape(bool $tunnel): string
    {
        $tunnelRule = $tunnel ? <<<'SQL'

                OR (kind = 'tunnel' AND name = '' AND result IN ('ok', 'foreign', 'down', 'unknown', 'unwatched') AND at IS NOT NULL
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
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL){$tunnelRule}
            )
            SQL;
    }
};
