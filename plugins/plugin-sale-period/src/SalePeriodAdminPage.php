<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminAttention;
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
use EcSugar\PluginSalePeriod\Models\SaleWindow;
use Illuminate\Database\Eloquent\Builder;

/**
 * 管理画面の「公開の予約と販売期間」の画面（ADR 0051）。日時を決めた商品を、いつ出る・いつ終わるで並べる。
 * App は中身（絞り込み・列・行・1 件の事実・押せる操作）だけを返し、描くのは核の部品。
 * 日時を決めるのは商品の画面の App の欄（SalePeriodListingPanel）で、この画面からは「期間をやめる」だけ。
 * 呼ばれるのは、この店で使っている時の店の文脈の中だけ。お客さんの個人情報は出さない（商品はリンクで核の商品の画面へ）
 */
final class SalePeriodAdminPage implements AdminPageHandler
{
    private const array FILTERS = ['waiting' => '公開待ち', 'selling' => '販売中', 'closed' => '販売終了', 'all' => 'すべて'];

    public function title(): string
    {
        return '公開の予約と販売期間';
    }

    public function filters(): array
    {
        $now = CarbonImmutable::now();
        $counts = [
            'waiting' => self::filtered('waiting', $now)->count(),
            'selling' => self::filtered('selling', $now)->count(),
            'closed' => self::filtered('closed', $now)->count(),
            'all' => SaleWindow::query()->count(),
        ];

        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], $counts[$key]), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        $count = self::filtered('closed', CarbonImmutable::now())->count();

        return [new AdminAttention('closed', '販売期間が終わった商品', 'ショップでは売り切れになっています。販売を続けるなら、商品の画面で「販売を終える日時」を変更してください。', $count)];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('listing', '商品', CellType::Link),
            new AdminColumn('state', '今の状態', CellType::Status),
            new AdminColumn('starts', '公開する日時', CellType::DateTime),
            new AdminColumn('ends', '販売を終える日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $now = CarbonImmutable::now();
        $query = self::filtered($filter, $now);
        $total = (clone $query)->count();
        $windows = $query->orderByRaw('COALESCE(starts_at, created_at)')->orderBy('id')->forPage($page, $perPage)->get();
        $titles = self::titles($windows->pluck('listing_id')->all());

        return new AdminRows($windows->map(static fn (SaleWindow $window): AdminRow => new AdminRow($window->id, [
            'listing' => ['label' => $titles[$window->listing_id] ?? '（削除された商品）', 'kind' => 'listing', 'id' => $window->listing_id],
            'state' => self::state($window, $now),
            'starts' => $window->starts_at,
            'ends' => $window->ends_at,
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $window = SaleWindow::query()->find($id);

        if ($window === null) {
            return null;
        }

        $now = CarbonImmutable::now();
        $title = self::titles([$window->listing_id])[$window->listing_id] ?? '（削除された商品）';

        return new AdminRecord($title, [
            new AdminFact('商品', CellType::Link, ['label' => $title, 'kind' => 'listing', 'id' => $window->listing_id]),
            new AdminFact('今の状態', CellType::Status, self::state($window, $now)),
            new AdminFact('公開する日時', CellType::DateTime, $window->starts_at),
            new AdminFact('販売を終える日時', CellType::DateTime, $window->ends_at),
            new AdminFact('設定した日時', CellType::DateTime, $window->created_at),
            new AdminFact('更新した日時', CellType::DateTime, $window->updated_at),
        ], [], [
            new AdminAction('clear', '期間の設定を解除する', 'この商品の公開の予約と販売期間の設定を解除します。公開中の商品なら、すぐにショップに表示され、ふつうに販売できます。', danger: true),
        ]);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'clear' => app(SaleWindows::class)->clear($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    /**
     * 絞り込みの問い（今の時刻と比べる。SQL の now() ではなく値で渡す）
     *
     * @return Builder<SaleWindow>
     */
    private static function filtered(string $filter, CarbonImmutable $now): Builder
    {
        $query = SaleWindow::query();

        return match ($filter) {
            'waiting' => $query->notYetOpen($now),
            'selling' => $query->where(static fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(static fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
            'closed' => $query->closed($now),
            default => $query,
        };
    }

    /**
     * 今の状態（公開を待っている・販売中・終わり）
     *
     * @return array{label: string, tone: string}
     */
    private static function state(SaleWindow $window, CarbonImmutable $now): array
    {
        if ($window->starts_at !== null && $window->starts_at->greaterThan($now)) {
            return ['label' => '公開待ち', 'tone' => 'muted'];
        }

        if ($window->ends_at !== null && $window->ends_at->lessThanOrEqualTo($now)) {
            return ['label' => '販売終了', 'tone' => 'bad'];
        }

        return ['label' => '販売中', 'tone' => 'good'];
    }

    /**
     * 商品の名前（画面の文の上限に収める）
     *
     * @param  list<string>  $listingIds
     * @return array<string, string>
     */
    private static function titles(array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        return Listing::query()->whereIn('id', $listingIds)->pluck('title', 'id')
            ->map(static fn (mixed $title): string => mb_strlen((string) $title) > CellType::TEXT_MAX
                ? mb_substr((string) $title, 0, CellType::TEXT_MAX - 1).'…'
                : (string) $title)
            ->all();
    }
}
