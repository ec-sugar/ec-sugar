<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ホストから見た状態に、サーバーの外のバックアップの結果を足す（ADR 0110）。
 * ホストの ec-sugar offsite run が残した結果のうち、決まった語と時刻だけを渡す（置き場を設定したサーバーだけ。設定していなければ行が無い）:
 *   name が空       最後の回（ok・failed）。at は最後にバックアップを送れた時刻（まだ 1 回も送れていなければ空）、checked_at は最後に試した時刻
 *   name が reason  失敗の理由の決まった語（最後の回が失敗の時だけ）
 *   name が lock    置き場が上書きと削除を断ったか（ok・open・unknown）
 *   name が heads   記録の連鎖の先頭を送れたか（ok・failed）。at は最後に送れた時刻
 * 入口の URL・バケットの名前・鍵・バックアップの名前・置き場の答えは置かない
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_kind');
        DB::statement("ALTER TABLE host_checks ADD CONSTRAINT host_checks_kind CHECK (kind IN ('certificate', 'disk', 'update', 'backup', 'tunnel', 'integrity', 'sentinel', 'offsite'))");
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_shape');
        DB::statement(self::shape(true));
    }

    public function down(): void
    {
        // 戻す前に、新しい種類の行を消す（次の報告で入れ直る）
        DB::table('host_checks')->where('kind', 'offsite')->delete();
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_shape');
        DB::statement(self::shape(false));
        DB::statement('ALTER TABLE host_checks DROP CONSTRAINT host_checks_kind');
        DB::statement("ALTER TABLE host_checks ADD CONSTRAINT host_checks_kind CHECK (kind IN ('certificate', 'disk', 'update', 'backup', 'tunnel', 'integrity', 'sentinel'))");
    }

    /**
     * 形の決まり（前のマイグレーションと同じもの。$offsite ならサーバーの外のバックアップの行を足す）
     */
    private static function shape(bool $offsite): string
    {
        $offsiteRule = $offsite ? <<<'SQL'

                OR (kind = 'offsite' AND name = '' AND result IN ('ok', 'failed') AND checked_at IS NOT NULL
                    AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'offsite' AND name = 'reason' AND result IN ('config', 'curl-old', 'no-gpg', 'no-recipient', 'bad-recipient', 'put', 'gave-up')
                    AND at IS NULL AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'offsite' AND name = 'lock' AND result IN ('ok', 'open', 'unknown')
                    AND at IS NULL AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
                OR (kind = 'offsite' AND name = 'heads' AND result IN ('ok', 'failed')
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL)
            SQL : '';

        return <<<SQL
            ALTER TABLE host_checks ADD CONSTRAINT host_checks_shape CHECK (
                (kind = 'certificate' AND name ~ '^[a-z0-9.-]{1,253}$' AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL
                    AND ((result IS NULL AND at IS NOT NULL) OR (result = 'unreadable' AND at IS NULL)))
                OR (kind = 'disk' AND name IN ('data', 'backup') AND result IS NULL AND at IS NULL AND checked_at IS NULL AND version IS NULL
                    AND free_percent BETWEEN 0 AND 100 AND free_bytes >= 0)
                OR (kind = 'update' AND name IN ('', 'yes', 'no') AND free_percent IS NULL AND free_bytes IS NULL
                    AND (result IS NULL OR result IN ('ok', 'rolled_back', 'failed', 'stopped', 'manual_rollback'))
                    AND (result IS NULL) = (at IS NULL) AND (result IS NOT NULL OR checked_at IS NOT NULL OR name <> '')
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
                    AND checked_at IS NULL AND version IS NULL AND free_percent IS NULL AND free_bytes IS NULL){$offsiteRule}
            )
            SQL;
    }
};
