<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites;

use App\Core\Crypto\BlindIndex;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Plugin\Services\MarketingConsents;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Catalog\ListingCard;
use Carbon\CarbonImmutable;
use EcSugar\PluginFavorites\Jobs\SendFavoriteNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * お気に入りの商品の値下げ・再入荷の知らせ（毎日 1 回の favorites:notify の中身。ADR 0071）。何度流しても、同じ日に 2 通は送らない。
 *
 * 1. 知らせを選んだ会員のお気に入りにある商品を、ショップに出ているものだけ今の札（Catalog。値段は種類のいちばん安い値段の税込み）で読み、
 *    前の日に見た値段・売り切れか（favorites_prices）と比べる。値下げ＝今の値段が前の日より安い、再入荷＝前の日は売り切れで今は買える。
 *    見た値段は日に 1 回だけ書き換える（同じ日の 2 回目は、もう見た商品を飛ばす）。初めて見た商品は控えるだけ（比べる相手が無い）
 * 2. 変わった商品をお気に入りにしていて、その知らせを選んだ会員ごとに、1 通にまとめる（会員 × 日で 1 行の記録。一意の制約で日に 1 通まで）
 * 3. 送らない: 退会した会員・お知らせのメールの受け取りに同意していない会員（広告にあたるので、核の口 MarketingConsent に聞く。
 *    答える App〔メルマガ〕が無ければ誰にも送らない）・再入荷の App で同じ商品の知らせを待っている／送った会員の再入荷（2 通にしない）
 *
 * 同じ店で 2 つ同時に流れても、店ごとの錠で 1 つずつ（見た値段の書き換えと記録を 1 つのトランザクションで。落ちたら全部戻り、次に流した時にやり直す）
 */
final class FavoriteNotices
{
    /** 1 度に読む商品の数 */
    public const int CHUNK = 500;

    /** 1 通に載せる商品の数 */
    public const int PER_MAIL = 20;

    /** 再入荷の App が知らせてからこの日数のうちは、こちらから再入荷を知らせない */
    public const int RESTOCK_OVERLAP_DAYS = 3;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly MarketingConsents $consents,
        private readonly PluginStates $states,
    ) {}

    /**
     * @return array{changed: int, queued: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $today = $now->timezone('Asia/Tokyo')->toDateString();

        return DB::transaction(function () use ($now, $today): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['favorites-notices']);
            $changes = $this->changes($now, $today);
            $queued = $changes === [] ? 0 : $this->queue($changes, $now, $today);

            return ['changed' => count($changes), 'queued' => $queued];
        });
    }

    /**
     * 商品ごとの変わり方（ID => 種類 => [前, 今]）を出し、見た値段を今日のものに書き換える
     *
     * @return array<string, array<string, array{0: int, 1: int}>>
     */
    private function changes(CarbonImmutable $now, string $today): array
    {
        $changes = [];
        $watched = DB::table('favorites_items as i')
            ->join('favorites_settings as s', 's.customer_account_id', '=', 'i.customer_account_id')
            ->where(static fn ($q) => $q->where('s.price_drop', true)->orWhere('s.restock', true))
            ->distinct()->orderBy('i.listing_id')->select('i.listing_id');

        $watched->chunk(self::CHUNK, function ($rows) use (&$changes, $now, $today): void {
            $ids = $rows->pluck('listing_id')->map(static fn ($id): string => (string) $id)->all();
            $seen = DB::table('favorites_prices')->whereIn('listing_id', $ids)->get()->keyBy('listing_id');
            $snapshots = [];

            foreach ($this->catalog->picked($ids) as $card) {
                $before = $seen->get($card->id);
                $soldOut = $card->availability === ListingCard::SOLD_OUT;

                // 今日もう見た商品は飛ばす（同じ日の 2 回目）
                if ($before !== null && (string) $before->seen_on === $today) {
                    continue;
                }

                if ($before !== null) {
                    if ($card->price < (int) $before->price) {
                        $changes[$card->id]['price_drop'] = [(int) $before->price, $card->price];
                    }

                    if ((bool) $before->sold_out && ! $soldOut) {
                        $changes[$card->id]['restock'] = [0, 0];
                    }
                }

                $snapshots[] = ['listing_id' => $card->id, 'price' => $card->price, 'sold_out' => $soldOut, 'seen_on' => $today, 'updated_at' => $now];
            }

            if ($snapshots !== []) {
                DB::table('favorites_prices')->upsert($snapshots, ['listing_id'], ['price', 'sold_out', 'seen_on', 'updated_at']);
            }
        });

        return $changes;
    }

    /**
     * 変わった商品を選んだ知らせの会員ごとにまとめ、送る記録を積む。積んだ数を返す
     *
     * @param  array<string, array<string, array{0: int, 1: int}>>  $changes
     */
    private function queue(array $changes, CarbonImmutable $now, string $today): int
    {
        $byMember = [];

        foreach (array_chunk(array_keys($changes), self::CHUNK) as $ids) {
            $rows = DB::table('favorites_items as i')
                ->join('favorites_settings as s', 's.customer_account_id', '=', 'i.customer_account_id')
                ->whereIn('i.listing_id', $ids)
                ->orderBy('i.created_at')
                ->get(['i.customer_account_id', 'i.listing_id', 's.price_drop', 's.restock']);

            foreach ($rows as $row) {
                foreach ($changes[(string) $row->listing_id] as $kind => [$before, $after]) {
                    if (($kind === 'price_drop' && (bool) $row->price_drop) || ($kind === 'restock' && (bool) $row->restock)) {
                        $byMember[(string) $row->customer_account_id][] = ['listing_id' => (string) $row->listing_id, 'kind' => $kind, 'before' => $before, 'after' => $after];
                    }
                }
            }
        }

        $queued = 0;
        $restockApp = $this->states->enabled('restock') && Schema::hasTable('restock_requests');
        $accounts = CustomerAccount::query()->whereKey(array_keys($byMember))->get()->keyBy('id');

        foreach ($byMember as $accountId => $items) {
            $account = $accounts->get($accountId);

            // 退会・まとめられた会員と、お知らせのメールの受け取りに同意していない会員には送らない
            if (! $account instanceof CustomerAccount || $account->disabled_at !== null || $account->merged_into_id !== null || ! $this->consents->consents($account->email)) {
                continue;
            }

            if ($restockApp) {
                $items = $this->withoutRestockOverlap($account, $items, $now);
            }

            if ($items === []) {
                continue;
            }

            $id = strtolower((string) Str::ulid());
            $inserted = DB::table('favorites_notices')->insertOrIgnore([
                'id' => $id,
                'customer_account_id' => $accountId,
                'notice_on' => $today,
                'items' => json_encode(array_slice($items, 0, self::PER_MAIL), JSON_THROW_ON_ERROR),
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                $queued++;
                DB::afterCommit(static fn () => SendFavoriteNotice::dispatch($id));
            }
        }

        return $queued;
    }

    /**
     * 再入荷の App（restock）が同じ商品を知らせる・知らせた会員の再入荷は外す（2 通にしない）。
     * 会員の台帳か、同じメールの索引で頼んだ登録のうち、入荷を待っているものと、少し前に知らせたもの
     *
     * @param  list<array{listing_id: string, kind: string, before: int, after: int}>  $items
     * @return list<array{listing_id: string, kind: string, before: int, after: int}>
     */
    private function withoutRestockOverlap(CustomerAccount $account, array $items, CarbonImmutable $now): array
    {
        $restocks = array_values(array_unique(array_column(array_filter($items, static fn (array $i): bool => $i['kind'] === 'restock'), 'listing_id')));

        if ($restocks === []) {
            return $items;
        }

        $index = BlindIndex::of('email', $account->email);
        $covered = DB::table('restock_requests')
            ->whereIn('listing_id', $restocks)
            ->where(static fn ($q) => $q->where('customer_account_id', $account->id)->orWhere('email_index', $index))
            ->where(static fn ($q) => $q->where('status', 'waiting')
                ->orWhere(static fn ($n) => $n->where('status', 'notified')->where('notified_at', '>=', $now->subDays(self::RESTOCK_OVERLAP_DAYS))))
            ->distinct()->pluck('listing_id')->map(static fn ($id): string => (string) $id)->all();

        // 知らせた登録はメールの索引を消すので、台帳の ID で見る（会員が頼んだ登録には台帳の ID が残る）
        return array_values(array_filter($items, static fn (array $i): bool => $i['kind'] !== 'restock' || ! in_array($i['listing_id'], $covered, true)));
    }

    /**
     * 配信の停止のリンクの合い言葉（32 バイトの乱数。URL に載る形で 43 文字）
     */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
