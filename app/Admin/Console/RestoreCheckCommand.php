<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Ledger\Models\LedgerPosting;
use App\Core\Listing\Models\ListingImage;
use App\Core\Media\ImageProcessor;
use App\Core\Media\Models\ShopMedia;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * バックアップから戻した後の確かめ（ADR 0082）。店ごとに、取引の数・台帳の行と正の合計〔受け取る権利の側〕と合計（0 のはず）・記録の数（と鎖）・写真の数と
 * 見つからない写真のファイルの数を出す。読むだけで何も書かない（本番でも流してよい。戻す前に本番で流した数と、写しで流した数を比べる）。
 * ホストからは sudo ec-sugar restore-check（ec-sugar restore の終わりにも流れる）。
 *
 *   operations:restore-check            数を出す
 *   operations:restore-check --verify   記録の鎖も先頭から確かめる（全件を読むので店が大きいと時間がかかる）
 *
 * 出すのは件数と金額と店の ID だけ（お客さん・注文の中身・商品名は出さない）。店に入れない（APP_KEY が取った所と違うと店の鍵を開けられない）・
 * 台帳の合計が 0 でない・鎖が切れている・写真のファイルが見つからない店があれば、終わりの番号を 0 でなくする
 */
final class RestoreCheckCommand extends Command
{
    protected $signature = 'operations:restore-check
        {--verify : 記録の鎖も先頭から確認する}';

    protected $description = '戻した後の確認: 店ごとの取引・会計の記録・記録・写真の数を出す（読むだけ）';

    public function handle(EventStore $events): int
    {
        $verify = (bool) $this->option('verify');
        $rows = [];
        $problems = 0;
        $shops = 0;

        foreach (Tenant::query()->orderBy('id')->cursor() as $tenant) {
            $shops++;
            $id = (string) $tenant->getTenantKey();

            // 1 店が開けなくても（鍵・DB が無い）、後ろの店は続ける
            try {
                tenancy()->initialize($tenant);
                $row = $this->count($id, $verify ? $events : null);
                $problems += $row['problem'] ? 1 : 0;
                unset($row['problem']);
                $rows[] = $row;
            } catch (Throwable $e) {
                $problems++;
                $why = $e instanceof DecryptException ? '店の鍵を開けられない（APP_KEY が取った所と違う見込み）' : '店に入れない（'.class_basename($e).'）';
                $rows[] = ['shop' => $id, 'transactions' => $why, 'postings' => '-', 'debits' => '-', 'balance' => '-', 'events' => '-', 'images' => '-', 'missing' => '-'];
            } finally {
                tenancy()->end();
            }
        }

        $this->line("店 {$shops}");
        $this->table(['店', '取引', '会計の記録の行', '会計の記録の正の合計（円）', '会計の記録の合計（0 のはず）', '記録'.($verify ? '（鎖）' : ''), '写真', '見つからない写真のファイル'], $rows);

        if ($problems > 0) {
            $this->error("確認が要る店が {$problems} あります（上の表。会計の記録の合計は 0、見つからない写真のファイルは 0 のはず）");

            return self::FAILURE;
        }

        $this->info('どの店も開けて、会計の記録の合計が 0 で、写真のファイルがそろっています');

        return self::SUCCESS;
    }

    /**
     * 今の店の数（店の文脈の中で）
     *
     * @return array{shop: string, transactions: int, postings: int, debits: int, balance: int, events: string, images: int, missing: int, problem: bool}
     */
    private function count(string $tenantId, ?EventStore $events): array
    {
        $balance = (int) LedgerPosting::query()->sum('amount');
        $chain = '';

        if ($events !== null) {
            $result = $events->verify();
            $chain = $result->intact() ? '（無傷）' : "（{$result->brokenAt} で切れている）";
        }

        $images = 0;
        $missing = 0;
        $disk = Storage::disk('media');

        foreach (ListingImage::query()->select(['id', 'listing_id'])->lazyById(500) as $image) {
            $images++;

            foreach (array_keys(ImageProcessor::VARIANTS) as $variant) {
                $missing += $disk->exists(ListingImage::path($tenantId, $image->listing_id, $image->id, $variant)) ? 0 : 1;
            }
        }

        foreach (ShopMedia::query()->select(['id'])->lazyById(500) as $media) {
            $images++;

            foreach (array_keys(ImageProcessor::SHOP_VARIANTS) as $variant) {
                $missing += $disk->exists(ShopMedia::path($tenantId, $media->id, $variant)) ? 0 : 1;
            }
        }

        return [
            'shop' => $tenantId,
            'transactions' => Transaction::query()->count(),
            'postings' => LedgerPosting::query()->count(),
            'debits' => (int) LedgerPosting::query()->where('amount', '>', 0)->sum('amount'),
            'balance' => $balance,
            'events' => DomainEvent::query()->count().$chain,
            'images' => $images,
            'missing' => $missing,
            'problem' => $balance !== 0 || $missing > 0 || str_contains($chain, '切れて'),
        ];
    }
}
