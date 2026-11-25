<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Models\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * APP_KEY の控えをサーバーの外に取ったかの確かめ。
 *
 * APP_KEY はお客さんの個人情報の暗号の鍵の元で、バックアップには入らない。控えが無いと、機械ごと失った時にバックアップを戻しても
 * 個人情報を開けない。控えを取るのは人の手（サーバーで sudo ec-sugar app-key）なので、アプリが知れるのは「オーナーが取ったと答えたか」だけ。
 * 答えるまで、運営の様子の「近いうちに」に出し続ける（OperationsOverview）。
 *
 * - 持つのは「いつ・誰が答えたか」だけ（中央の operations_confirmations）。鍵・設定の値・控えの置き場は読まない・持たない
 * - 出すのは、ホストの報告が一度でも来たサーバーだけ（ec-sugar で入れた形。手元の開発には控える鍵が無い。ほかのホストの困りごとと同じ分け方）
 * - 最初の答えを残す（押し直しても時刻と人を書き換えない）
 */
final class AppKeyBackup
{
    /** 運営の様子の困りごとの印 */
    public const string ISSUE = 'app_key_backup_unconfirmed';

    /** 監査ログの種類 */
    public const string AUDIT = 'operations.app_key_backup_confirmed';

    private const string KEY = 'app_key_backup';

    /**
     * 答えた時刻（まだなら null）
     */
    public static function confirmedAt(): ?CarbonImmutable
    {
        $at = self::central()->table('operations_confirmations')->where('key', self::KEY)->value('confirmed_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }

    /**
     * 「控えを取りました」の答えを残す。もう答えてあれば何も変えない（false）。
     * 最初の答えの時だけ、同じトランザクションで中央の監査ログに残す（誰が・いつ・どこから。中身は空。答えだけ残って記録が無い、を作らない）
     */
    public static function confirm(Operator $operator, CarbonImmutable $now, AuditLog $audit, ?string $ip = null): bool
    {
        if (tenant() !== null) {
            throw new LogicException('APP_KEY の控えの確認は店の文脈の外で書く');
        }

        $central = self::central();

        return $central->transaction(static function () use ($central, $operator, $now, $audit, $ip): bool {
            // 同時に 2 人が押しても 1 行（主キー）。先の答えが残る
            $first = $central->table('operations_confirmations')->insertOrIgnore([
                'key' => self::KEY,
                'confirmed_at' => $now->utc(),
                'operator_id' => $operator->id,
            ]) === 1;

            if ($first) {
                $audit->record(self::AUDIT, $operator, [], null, $ip);
            }

            return $first;
        });
    }

    private static function central(): ConnectionInterface
    {
        return DB::connection(ShopDigests::central());
    }
}
