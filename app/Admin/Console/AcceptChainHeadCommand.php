<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Services\AuditLog;
use App\Admin\Services\ShopDigests;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * 控えた記録の連鎖の先頭を取り直す（ADR 0012）。
 *
 * 運営の様子は、検証のたびに店の記録の先頭（最後のイベントの番号と hash）を中央に控え、次の検証で同じ所にあるかを見る。
 * 無ければ「前に確かめた時より前の状態に戻っている」と知らせ、**控えを進めない**（進めると知らせがひとりでに消える）。
 * バックアップから店を戻した後は、記録が正当に前の状態へ戻るので、このコマンドで控えを忘れさせる。次の検証で今の先頭を控え直す。
 *
 *   php artisan operations:accept-chain-head <店の ID>
 *
 * 巻き戻しに心当たりが無いのにこれを流すと、切り落としの跡を自分で消すことになる。流したことは監査ログに残る
 */
final class AcceptChainHeadCommand extends Command
{
    protected $signature = 'operations:accept-chain-head {tenant : 店の ID}';

    protected $description = '記録したハッシュチェーンの先頭を取り直す（バックアップから戻した後に）';

    public function handle(AuditLog $audit): int
    {
        $id = (string) $this->argument('tenant');

        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,62}\z/', $id) !== 1 || Tenant::query()->whereKey($id)->doesntExist()) {
            $this->error('その ID の店はありません。');

            return self::INVALID;
        }

        // 忘れる前の控え（どこまであったはずか）を監査ログに残す。流した後は、これが唯一の跡になる
        $forgotten = ShopDigests::forgetChainHead($id);
        $audit->record('tenant.chain_head_accepted', null, ['by' => 'cli', 'sequence' => $forgotten['sequence'], 'hash' => $forgotten['hash'], 'rolled_back' => $forgotten['rolled_back']], $id);
        $this->info("店 {$id} の記録した先頭を消しました。次の回（10 分ごと）で今の先頭を記録し直します。");

        return self::SUCCESS;
    }
}
