<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock;

use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\Contracts\AdminPageHandler;
use Carbon\CarbonImmutable;
use EcSugar\PluginRestock\Models\RestockRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 管理画面の「再入荷のお知らせ」（ADR 0051 の App の画面）。種類ごとに、入荷を待っている人の数・確かめ待ちの数・知らせた数だけを出す。
 * 誰が登録したか（メール）は出さない（数だけ。お客さんが頼んだ知らせのためのメールで、店の人の連絡先の一覧にしない）。操作は無い（知らせるのは入荷した時に自動で）
 */
final class RestockAdminPage implements AdminPageHandler
{
    private const array FILTERS = ['waiting' => '入荷を待っている', 'all' => 'すべて'];

    public function title(): string
    {
        return '再入荷のお知らせ';
    }

    public function filters(): array
    {
        $all = (int) DB::query()->fromSub(self::grouped(), 'g')->count();
        $waiting = (int) DB::query()->fromSub(self::grouped(), 'g')->where('waiting', '>', 0)->count();

        return [new AdminFilter('waiting', self::FILTERS['waiting'], $waiting), new AdminFilter('all', self::FILTERS['all'], $all)];
    }

    public function attention(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('listing', '商品', CellType::Text),
            new AdminColumn('variant', '種類', CellType::Text),
            new AdminColumn('waiting', '待っている人', CellType::Number),
            new AdminColumn('pending', '確認待ち', CellType::Number),
            new AdminColumn('notified', '知らせた数', CellType::Number),
            new AdminColumn('last', '最後に知らせた日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = DB::query()->fromSub(self::grouped(), 'g')->when($filter !== 'all', static fn (Builder $q) => $q->where('waiting', '>', 0));
        $total = (int) (clone $query)->count();
        $groups = $query->orderByDesc('waiting')->orderByDesc('latest')->orderBy('variant_id')->forPage($page, $perPage)->get();
        $titles = Listing::query()->whereKey($groups->pluck('listing_id')->unique()->values()->all())->pluck('title', 'id')->all();
        $variants = ListingVariant::query()->whereKey($groups->pluck('variant_id')->all())->get()->keyBy('id');

        return new AdminRows($groups->map(static fn (object $g): AdminRow => new AdminRow((string) $g->variant_id, [
            'listing' => self::cut((string) ($titles[$g->listing_id] ?? '（見つからない商品）')),
            'variant' => self::variantLabel($variants->get($g->variant_id)),
            'waiting' => (int) $g->waiting,
            'pending' => (int) $g->pending,
            'notified' => (int) $g->notified,
            'last' => $g->last === null ? null : CarbonImmutable::parse((string) $g->last),
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $g = DB::query()->fromSub(self::grouped(), 'g')->where('variant_id', strtolower($id))->first();

        if ($g === null) {
            return null;
        }

        $listing = Listing::query()->find($g->listing_id);
        $variant = ListingVariant::query()->find($g->variant_id);
        $inStock = app(RestockTargets::class)->available((string) $g->variant_id);

        return new AdminRecord(self::cut(($listing->title ?? '（見つからない商品）').($variant !== null && $variant->label() !== '' ? '（'.$variant->label().'）' : '')), [
            new AdminFact('ショップでの在庫', CellType::Status, $inStock ? ['label' => '在庫あり', 'tone' => 'good'] : ['label' => '売り切れ', 'tone' => 'muted']),
            new AdminFact('商品の画面', CellType::Link, $listing === null ? null : ['label' => '商品を開く', 'kind' => 'listing', 'id' => $listing->id]),
            new AdminFact('種類', CellType::Text, self::variantLabel($variant)),
            new AdminFact('入荷を待っている人', CellType::Number, (int) $g->waiting),
            new AdminFact('確認待ち（確認メールのリンクをまだ押していない人）', CellType::Number, (int) $g->pending),
            new AdminFact('知らせた数', CellType::Number, (int) $g->notified),
            new AdminFact('取り消した数', CellType::Number, (int) $g->cancelled),
            new AdminFact('最後に通知した日時', CellType::DateTime, $g->last === null ? null : CarbonImmutable::parse((string) $g->last)),
        ]);
    }

    public function act(string $id, string $action, string $actor): void
    {
        throw new AdminRecordNotFound;
    }

    /**
     * 種類ごとの数（確かめ待ちは期限の内だけ）。メールの列は読まない
     */
    private static function grouped(): Builder
    {
        return DB::table((new RestockRequest)->getTable())
            ->groupBy('variant_id', 'listing_id')
            ->select('variant_id', 'listing_id')
            ->selectRaw("count(*) FILTER (WHERE status = 'waiting') AS waiting")
            ->selectRaw("count(*) FILTER (WHERE status = 'pending' AND expires_at > ?) AS pending", [CarbonImmutable::now()])
            ->selectRaw("count(*) FILTER (WHERE status = 'notified') AS notified")
            ->selectRaw("count(*) FILTER (WHERE status = 'cancelled') AS cancelled")
            ->selectRaw('max(notified_at) AS last')
            ->selectRaw('max(created_at) AS latest');
    }

    /**
     * 種類の名前（軸の無い商品は null。画面は「—」）
     */
    private static function variantLabel(?ListingVariant $variant): ?string
    {
        $label = $variant?->label() ?? '';

        return $label === '' ? null : self::cut($label);
    }

    private static function cut(string $text): string
    {
        return mb_strlen($text) > CellType::TEXT_MAX ? mb_substr($text, 0, CellType::TEXT_MAX - 1).'…' : $text;
    }
}
