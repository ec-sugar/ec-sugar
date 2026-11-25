<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Core\Event\EventStore;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * サーバーの外へ送った記録の連鎖の先頭と、今の店の記録を照らす（ADR 0110 の 5）。
 *
 * 外の保存先（heads/）から取った 1 つのファイル（operations:chain-heads が出した形）を標準入力で受け、行ごとに
 * 「その店の記録の同じ番号に、同じ hash の記録が今もあるか」を見る。無ければ、その時より後に記録の末尾が切り落とされたか、
 * 書き換えられた。中央の DB ごと取られて、中の控えまで書き換えられた時に使う（中の控えは信じられない）。
 *
 *   sudo ec-sugar offsite pull で取ったファイルを: docker compose exec -T app php artisan operations:check-chain-heads < heads.tsv
 *
 * 返り: 0 全部合う、1 合わない・無い店がある、2 ファイルの形が違う
 */
final class CheckChainHeadsCommand extends Command
{
    private const int MAX_LINES = 100_000;

    protected $signature = 'operations:check-chain-heads
        {--file= : 標準入力の代わりに読むファイル（コンテナの中の道筋）}';

    protected $description = 'サーバーの外へ送った記録の連鎖の先頭（標準入力）と、今の店の記録を照らす';

    public function handle(EventStore $events): int
    {
        $lines = [];
        $n = 0;
        $file = $this->option('file');
        $stream = is_string($file) && $file !== '' ? @fopen($file, 'rb') : STDIN;

        if ($stream === false) {
            $this->error('ファイルを開けません。');

            return 2;
        }

        while (($line = fgets($stream)) !== false) {
            $line = rtrim($line, "\r\n");

            if ($line === '') {
                continue;
            }

            if (++$n > self::MAX_LINES) {
                $this->error('行が多すぎます（'.self::MAX_LINES.' 行まで）。');

                return 2;
            }

            $parts = explode("\t", $line);

            if (count($parts) !== 4 || preg_match(ChainHeadsCommand::SHOP_ID, $parts[0]) !== 1 || preg_match('/\A[0-9]{1,18}\z/', $parts[1]) !== 1
                || preg_match('/\A[0-9a-f]{64}\z/', $parts[2]) !== 1 || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})\z/', $parts[3]) !== 1) {
                $this->error("{$n} 行目の形が違います（店の ID・番号・hash・時刻のタブ区切り）。");

                return 2;
            }

            $lines[] = $parts;
        }

        if ($lines === []) {
            $this->error('照らす先頭がありません（標準入力が空）。');

            return 2;
        }

        $bad = 0;

        foreach ($lines as [$id, $sequence, $hash, $at]) {
            $tenant = Tenant::query()->find($id);

            if (! $tenant instanceof Tenant) {
                $this->line("NG {$id}: 店がありません（{$at} に控えた先頭 {$sequence}）");
                $bad++;

                continue;
            }

            try {
                tenancy()->initialize($tenant);
                $now = $events->hashAt((int) $sequence);
            } catch (Throwable $e) {
                report($e);
                $this->line("NG {$id}: 記録を読めません");
                $bad++;

                continue;
            } finally {
                tenancy()->end();
            }

            if ($now === null) {
                $this->line("NG {$id}: {$at} に控えた番号 {$sequence} の記録がありません（末尾が切り落とされた恐れ）");
                $bad++;
            } elseif (! hash_equals($hash, $now)) {
                $this->line("NG {$id}: {$at} に控えた番号 {$sequence} の記録の hash が違います（書き換えられた恐れ）");
                $bad++;
            } else {
                $this->line("ok {$id}: {$at} に控えた番号 {$sequence} の記録が、同じ hash で今もあります");
            }
        }

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
