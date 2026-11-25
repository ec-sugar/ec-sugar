<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Storefront\Catalog\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 管理画面の「お気に入り」（ADR 0051 の App の画面）。お気に入りの多い商品の順位と、商品ごとの数だけを出す。
 * 誰が付けたか（会員・メール）は出さない（数だけ）。操作は無い
 */
final class FavoritesAdminPage implements AdminPageHandler
{
    public function title(): string
    {
        return 'お気に入り';
    }

    public function filters(): array
    {
        return [new AdminFilter('all', 'お気に入りの多い順', (int) DB::query()->fromSub(self::grouped(), 'g')->count())];
    }

    public function attention(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('rank', '順位', CellType::Number),
            new AdminColumn('listing', '商品', CellType::Text),
            new AdminColumn('count', 'お気に入りの数', CellType::Number),
            new AdminColumn('latest', '最後に追加された日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = DB::query()->fromSub(self::grouped(), 'g');
        $total = (int) (clone $query)->count();
        $groups = $query->orderByDesc('count')->orderByDesc('latest')->orderBy('listing_id')->forPage($page, $perPage)->get();
        $titles = Listing::query()->whereKey($groups->pluck('listing_id')->all())->pluck('title', 'id')->all();
        $offset = ($page - 1) * $perPage;

        return new AdminRows($groups->values()->map(static fn (object $g, int $i): AdminRow => new AdminRow((string) $g->listing_id, [
            'rank' => $offset + $i + 1,
            'listing' => self::cut((string) ($titles[$g->listing_id] ?? '（見つからない商品）')),
            'count' => (int) $g->count,
            'latest' => CarbonImmutable::parse((string) $g->latest),
        ]))->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $g = DB::query()->fromSub(self::grouped(), 'g')->where('listing_id', strtolower($id))->first();

        if ($g === null) {
            return null;
        }

        $listing = Listing::query()->find($g->listing_id);
        $published = app(Catalog::class)->isPublished((string) $g->listing_id);
        $watching = DB::table('favorites_items as i')->join('favorites_settings as s', 's.customer_account_id', '=', 'i.customer_account_id')
            ->where('i.listing_id', $g->listing_id);

        return new AdminRecord(self::cut((string) ($listing->title ?? '（見つからない商品）')), [
            new AdminFact('ショップでの公開', CellType::Status, $published ? ['label' => '公開中', 'tone' => 'good'] : ['label' => '非公開', 'tone' => 'muted']),
            new AdminFact('商品の画面', CellType::Link, $listing === null ? null : ['label' => '商品を開く', 'kind' => 'listing', 'id' => $listing->id]),
            new AdminFact('お気に入りの数', CellType::Number, (int) $g->count),
            new AdminFact('値下げのお知らせを希望する人', CellType::Number, (int) (clone $watching)->where('s.price_drop', true)->count()),
            new AdminFact('再入荷のお知らせを希望する人', CellType::Number, (int) (clone $watching)->where('s.restock', true)->count()),
            new AdminFact('最後に追加された日時', CellType::DateTime, CarbonImmutable::parse((string) $g->latest)),
        ]);
    }

    public function act(string $id, string $action, string $actor): void
    {
        throw new AdminRecordNotFound;
    }

    /**
     * 商品ごとの数（会員の列は読まない）
     */
    private static function grouped(): Builder
    {
        return DB::table('favorites_items')->groupBy('listing_id')->select('listing_id')
            ->selectRaw('count(*) AS count')->selectRaw('max(created_at) AS latest');
    }

    private static function cut(string $text): string
    {
        return mb_strlen($text) > CellType::TEXT_MAX ? mb_substr($text, 0, CellType::TEXT_MAX - 1).'…' : $text;
    }
}
