<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Models\ShopDigest;
use Illuminate\Console\Command;

/**
 * 店ごとの記録の連鎖の先頭を、サーバーの外へ送る形で出す（ADR 0110 の 5）。
 *
 * 出すのは、10 分ごとの回が確かめてから中央に控えた先頭（ShopDigests。巻き戻しを見つけた時と連鎖が切れている時は控えを進めない）。
 * 1 行が「店の ID・番号・hash・控えた時刻」のタブ区切り。ホストの ec-sugar がこれを受け、形を確かめてから
 * サーバーの外の消せない保存先（heads/）へ送る。中央の DB ごと書き換えられても、外に送った先頭と照らせば、記録の末尾の切り落としに気づける
 * （照らすのは operations:check-chain-heads）。
 *
 * 記録がまだ無い店は出さない（守るものが無い）。ホストが受けない形の店の ID は、出さずに標準エラーへ書く
 * （1 店のために全部の送信が止まらないように）。個人情報は入らない。
 */
final class ChainHeadsCommand extends Command
{
    /** ホストの ec_offsite_heads_valid と同じ形（店を作るコマンドが許す形） */
    public const string SHOP_ID = '/\A[a-z0-9][a-z0-9-]{0,30}[a-z0-9]\z/';

    protected $signature = 'operations:chain-heads';

    protected $description = '店ごとの記録の連鎖の先頭（番号と hash）を、サーバーの外へ送る形で出す';

    public function handle(): int
    {
        $rows = ShopDigest::query()
            ->whereNotNull('chain_head_hash')
            ->whereNotNull('chain_head_sequence')
            ->whereNotNull('collected_at')
            ->orderBy('tenant_id')
            ->get(['tenant_id', 'chain_head_sequence', 'chain_head_hash', 'collected_at']);

        foreach ($rows as $row) {
            $id = (string) $row->tenant_id;

            if (preg_match(self::SHOP_ID, $id) !== 1) {
                fwrite(STDERR, "店 {$id} の ID はサーバーの外へ送る形でないので、先頭を出しません。\n");

                continue;
            }

            $this->line(implode("\t", [$id, (string) $row->chain_head_sequence, (string) $row->chain_head_hash, $row->collected_at?->utc()->format('Y-m-d\TH:i:s\Z')]));
        }

        return self::SUCCESS;
    }
}
