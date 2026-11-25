<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites;

use App\Storefront\Catalog\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use WeakMap;

/**
 * 会員のお気に入り（足す・外す・読む）と、知らせの選択・退会の片付け。お気に入りの表を書くのはここだけ。
 *
 * お気に入りは「会員が付けた印」で、取引・在庫・お金の状態ではなく、監査の源泉にもならない（conventions.md の 3 の例外。再入荷のお知らせの登録と同じ扱い）。
 * 連鎖のロックもイベントも使わず、会員ごとの錠（pg_advisory_xact_lock）と一意の制約で守る:
 * - 同じ商品を同時に 2 回足しても 1 行（会員 × 商品の一意。2 つ目は何もしない）
 * - 1 人 500 件まで（数えて足すのを会員ごとの錠の中で行う。同時に 2 つ足しても 500 を越えない）
 * - 足せるのはショップに出ている商品だけ（公開を終えた商品は、もう付いている印を外すだけできる）
 *
 * ショップの画面は札の枚数だけ「付いているか」を聞くので、会員の印は 1 回の要求で 1 度だけ読んで覚える（要求が終われば消える）
 */
final class Favorites
{
    public const int MAX = 500;

    /** @var WeakMap<Request, array<string, array<string, true>>>|null */
    private static ?WeakMap $memo = null;

    public function __construct(private readonly Catalog $catalog) {}

    public function add(string $accountId, string $listingId): AddOutcome
    {
        $listingId = strtolower($listingId);

        if (! $this->catalog->isPublished($listingId)) {
            return AddOutcome::Unavailable;
        }

        $outcome = DB::transaction(static function () use ($accountId, $listingId): AddOutcome {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['favorites|'.$accountId]);
            $mine = DB::table('favorites_items')->where('customer_account_id', $accountId);

            if ((clone $mine)->where('listing_id', $listingId)->exists()) {
                return AddOutcome::Already;
            }

            if ((clone $mine)->count() >= self::MAX) {
                return AddOutcome::Full;
            }

            $added = DB::table('favorites_items')->insertOrIgnore([
                'id' => strtolower((string) Str::ulid()),
                'customer_account_id' => $accountId,
                'listing_id' => $listingId,
                'created_at' => CarbonImmutable::now(),
            ]);

            return $added === 1 ? AddOutcome::Added : AddOutcome::Already;
        });

        self::forget($accountId);

        return $outcome;
    }

    /**
     * 外す（公開を終えた商品も外せる）。外したら真
     */
    public function remove(string $accountId, string $listingId): bool
    {
        $removed = DB::table('favorites_items')->where('customer_account_id', $accountId)->where('listing_id', strtolower($listingId))->delete() > 0;
        self::forget($accountId);

        return $removed;
    }

    /**
     * 付いているか（この要求で 1 度だけ会員の印を読む）
     */
    public function has(string $accountId, string $listingId): bool
    {
        return isset($this->ids($accountId)[strtolower($listingId)]);
    }

    /**
     * 会員の印の付いた商品（ID => 真）。この要求の中で覚える
     *
     * @return array<string, true>
     */
    public function ids(string $accountId): array
    {
        $request = request();
        self::$memo ??= new WeakMap;
        $seen = self::$memo[$request] ?? [];

        if (! isset($seen[$accountId])) {
            $seen[$accountId] = array_fill_keys(DB::table('favorites_items')->where('customer_account_id', $accountId)->pluck('listing_id')->map(static fn ($id): string => (string) $id)->all(), true);
            self::$memo[$request] = $seen;
        }

        return $seen[$accountId];
    }

    /**
     * 会員の印を新しい順に（マイページの一覧）
     *
     * @return list<array{listing_id: string, created_at: CarbonImmutable}>
     */
    public function list(string $accountId): array
    {
        return DB::table('favorites_items')->where('customer_account_id', $accountId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX)->get(['listing_id', 'created_at'])
            ->map(static fn (object $row): array => ['listing_id' => (string) $row->listing_id, 'created_at' => CarbonImmutable::parse((string) $row->created_at)])
            ->values()->all();
    }

    /**
     * 値下げ・再入荷を知らせるかの選択（無ければどちらも切）
     *
     * @return array{price_drop: bool, restock: bool}
     */
    public function settings(string $accountId): array
    {
        $row = DB::table('favorites_settings')->where('customer_account_id', $accountId)->first();

        return ['price_drop' => (bool) ($row->price_drop ?? false), 'restock' => (bool) ($row->restock ?? false)];
    }

    public function choose(string $accountId, bool $priceDrop, bool $restock): void
    {
        $now = CarbonImmutable::now();
        DB::table('favorites_settings')->upsert(
            [['customer_account_id' => $accountId, 'price_drop' => $priceDrop, 'restock' => $restock, 'created_at' => $now, 'updated_at' => $now]],
            ['customer_account_id'],
            ['price_drop', 'restock', 'updated_at'],
        );
    }

    /**
     * 退会した会員の印・選択・知らせの記録を消す（退会と同じトランザクション）
     */
    public function leave(string $accountId): void
    {
        DB::table('favorites_items')->where('customer_account_id', $accountId)->delete();
        DB::table('favorites_settings')->where('customer_account_id', $accountId)->delete();
        DB::table('favorites_notices')->where('customer_account_id', $accountId)->delete();
        self::forget($accountId);
    }

    private static function forget(string $accountId): void
    {
        $request = request();

        if (self::$memo !== null && isset(self::$memo[$request])) {
            $seen = self::$memo[$request];
            unset($seen[$accountId]);
            self::$memo[$request] = $seen;
        }
    }
}
