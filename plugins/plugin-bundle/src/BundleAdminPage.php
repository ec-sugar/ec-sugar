<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle;

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
use App\Core\Plugin\AdminPage\AdminTable;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\Contracts\AdminPageHandler;
use EcSugar\PluginBundle\Models\BundleComponent;
use Illuminate\Support\Facades\DB;

/**
 * 管理画面の「セット商品」の画面（ADR 0051）。セットの商品を、今いくつ作れるかと一緒に並べる。
 * App は中身（絞り込み・列・行・1 件の事実・押せる操作）だけを返し、描くのは核の部品。
 * 中身を決めるのは商品の画面の App の欄（BundleListingPanel）で、この画面からは「セットをやめる」だけ。
 * 1 件の印はセットの商品の ID なので、ほかの店の ID を入れても見つからない（404）。お客さんの個人情報は出さない
 */
final class BundleAdminPage implements AdminPageHandler
{
    private const array FILTERS = ['all' => 'すべて', 'unbuildable' => '構成の品の在庫切れ'];

    public function title(): string
    {
        return 'セット商品';
    }

    public function filters(): array
    {
        $counts = ['all' => BundleStock::count(), 'unbuildable' => count(BundleStock::unbuildable())];

        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], $counts[$key]), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [new AdminAttention('unbuildable', '構成の品の在庫が足りないセット',
            'これらのセットはショップに表示されていません。構成の品を入荷するか、商品の画面でセットの中身を変更してください。', count(BundleStock::unbuildable()))];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('listing', 'セットの商品', CellType::Link),
            new AdminColumn('parts', '構成の品', CellType::Number),
            new AdminColumn('sets', '販売できるセットの数', CellType::Number),
            new AdminColumn('state', '今の状態', CellType::Status),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $ids = self::listingIds($filter);
        $total = count($ids);
        $page = array_slice($ids, ($page - 1) * $perPage, $perPage);

        if ($page === []) {
            return new AdminRows([], $total);
        }

        $titles = self::titles($page);
        $sets = BundleStock::sets($page);
        $parts = BundleComponent::query()->whereIn('listing_id', $page)
            ->select('listing_id', DB::raw('COUNT(*) AS parts'))->groupBy('listing_id')->pluck('parts', 'listing_id');

        return new AdminRows(array_map(static fn (string $id): AdminRow => new AdminRow($id, [
            'listing' => ['label' => $titles[$id] ?? '（削除された商品）', 'kind' => 'listing', 'id' => $id],
            'parts' => (int) ($parts[$id] ?? 0),
            'sets' => $sets[$id] ?? 0,
            'state' => self::state($sets[$id] ?? 0),
        ]), $page), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $parts = BundleStock::parts($id);

        if ($parts === []) {
            return null;
        }

        $sets = min(array_map(static fn (array $part): int => $part['sets'], $parts));
        $title = self::titles([$id])[$id] ?? '（削除された商品）';

        return new AdminRecord($title, [
            new AdminFact('セットの商品', CellType::Link, ['label' => $title, 'kind' => 'listing', 'id' => $id]),
            new AdminFact('今の状態', CellType::Status, self::state($sets)),
            new AdminFact('販売できるセットの数', CellType::Number, $sets),
            new AdminFact('構成の品', CellType::Number, count($parts)),
        ], [
            new AdminTable('構成の品', [
                new AdminColumn('part', '品', CellType::Link),
                new AdminColumn('sku', '品番（種類）', CellType::Text),
                new AdminColumn('quantity', '1 セットあたりの数', CellType::Number),
                new AdminColumn('free', '注文に使える在庫', CellType::Number),
                new AdminColumn('sets', 'この品の在庫で用意できるセット数', CellType::Number),
            ], array_map(static fn (array $part): AdminRow => new AdminRow($part['variant'], [
                'part' => ['label' => $part['title'], 'kind' => 'listing', 'id' => $part['listing']],
                'sku' => $part['sku'] ?? $part['variant'],
                'quantity' => $part['quantity'],
                'free' => $part['free'],
                'sets' => $part['sets'],
            ]), $parts)),
        ], [
            new AdminAction('clear', 'セットをやめる', 'この商品のセットの中身を削除します。商品そのものは残りますが、'
                .'セットではなくなるので、売れたときに引くのはこの商品の在庫になります。', danger: true),
        ]);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'clear' => app(BundleComponents::class)->clear($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    /**
     * 絞り込みに当たるセットの商品の ID（商品の名前の順。名前の無い〔消された〕商品は後ろ）
     *
     * @return list<string>
     */
    private static function listingIds(string $filter): array
    {
        $ids = $filter === 'unbuildable'
            ? BundleStock::unbuildable()
            : BundleComponent::query()->distinct()->orderBy('listing_id')->pluck('listing_id')->map(static fn (mixed $id): string => (string) $id)->all();

        if ($ids === []) {
            return [];
        }

        $ordered = Listing::query()->whereIn('id', $ids)->orderBy('title')->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)->all();

        return [...$ordered, ...array_values(array_diff($ids, $ordered))];
    }

    /**
     * 今の状態（売れる・今は作れない）
     *
     * @return array{label: string, tone: string}
     */
    private static function state(int $sets): array
    {
        return $sets > 0 ? ['label' => '販売できる', 'tone' => 'good'] : ['label' => '構成の品の在庫切れ', 'tone' => 'bad'];
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
