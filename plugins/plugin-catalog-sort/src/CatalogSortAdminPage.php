<?php

declare(strict_types=1);

namespace EcSugar\PluginCatalogSort;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminField;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\AdminPage\FieldType;
use App\Core\Plugin\Contracts\AdminFormHandler;
use App\Core\Plugin\Contracts\AdminPageHandler;
use EcSugar\PluginCatalogSort\Models\CatalogSortItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 管理画面の「並び順と絞り込み」（ADR 0051 の App の画面と、足す・直すの欄）。
 * 一覧はショップの「おすすめ順」と同じ並びで、1 件の画面から「上へ」「下へ」で動かし、「一覧から外す」で並びを外す。
 * タグは商品ごとにカンマ区切りで書き、ショップの絞り込みに出る。
 * App は中身だけを返し、描くのは核の部品。お客さんの個人情報は扱わない（商品と、店の人が書いたタグだけ）
 */
final class CatalogSortAdminPage implements AdminFormHandler, AdminPageHandler
{
    /** 商品の選択肢に出す数（核の上限に収める） */
    private const int LISTING_OPTIONS = FieldType::OPTIONS_MAX;

    private const array FILTERS = ['all' => 'すべて', 'tagged' => 'タグあり', 'untagged' => 'タグなし'];

    public function title(): string
    {
        return '並び順と絞り込み';
    }

    public function filters(): array
    {
        $all = CatalogSortItem::query()->count();
        $tagged = CatalogSortItem::query()->whereRaw('jsonb_array_length(tags) > 0')->count();
        $counts = ['all' => $all, 'tagged' => $tagged, 'untagged' => $all - $tagged];

        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], $counts[$key]), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('listing', '商品', CellType::Text),
            new AdminColumn('rank', '順番', CellType::Number),
            new AdminColumn('tags', 'タグ', CellType::Text),
            new AdminColumn('updated', '更新した日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $items = $query->orderBy('position')->forPage($page, $perPage)->get();
        $titles = self::titles($items);
        $first = ($page - 1) * $perPage;

        return new AdminRows($items->values()->map(static fn (CatalogSortItem $item, int $i): AdminRow => new AdminRow($item->id, [
            'listing' => self::listingLabel($item, $titles),
            // 「並び」は一覧に出ている順の番号（並びの値そのものは入れ替えで飛ぶので見せない）
            'rank' => $first + $i + 1,
            'tags' => self::cut(implode('、', $item->tags)),
            'updated' => $item->updated_at,
        ]))->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $item = CatalogSortItem::query()->find($id);

        if ($item === null) {
            return null;
        }

        $titles = self::titles(collect([$item]));
        $rank = CatalogSortItem::query()->where('position', '<=', $item->position)->count();
        $last = $rank === CatalogSortItem::query()->count();
        $actions = [];

        if ($rank > 1) {
            $actions[] = new AdminAction('move_up', '上へ', '1 つ上へ移します。', repeatable: true);
        }

        if (! $last) {
            $actions[] = new AdminAction('move_down', '下へ', '1 つ下へ移します。', repeatable: true);
        }

        $actions[] = new AdminAction('remove', '一覧から削除', 'この商品を並びの一覧から削除します（商品そのものは消えません。ショップではほかの商品と同じ新着順に戻ります）。', danger: true);

        return new AdminRecord(self::listingLabel($item, $titles), [
            new AdminFact('順番', CellType::Number, $rank),
            new AdminFact('タグ', CellType::Text, self::cut($item->tags === [] ? '（なし）' : implode('、', $item->tags))),
            new AdminFact('商品の画面', CellType::Link, isset($titles[$item->listing_id]) ? ['label' => '商品を開く', 'kind' => 'listing', 'id' => $item->listing_id] : null),
            new AdminFact('追加した日時', CellType::DateTime, $item->created_at),
            new AdminFact('更新した日時', CellType::DateTime, $item->updated_at),
        ], [], $actions);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'move_up' => self::items()->move($id, 'up', $actor),
            'move_down' => self::items()->move($id, 'down', $actor),
            'remove' => self::items()->remove($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    public function createLabel(): string
    {
        return '商品を追加';
    }

    public function fields(): array
    {
        $options = [];

        foreach (Listing::query()->orderBy('title')->orderBy('id')->limit(self::LISTING_OPTIONS)->get(['id', 'title']) as $listing) {
            $options[$listing->id] = self::cut($listing->title);
        }

        // まだ商品が無い店でも欄は出す（核は空の選択肢を断るので、選べない 1 つを出して、押したら欄の誤りで返す）
        if ($options === []) {
            $options = [CatalogSortItems::NO_LISTING => '（商品がまだありません）'];
        }

        return [
            new AdminField('listing', FieldType::Choice, '商品', help: '一覧のいちばん後ろに追加します。「上へ」「下へ」で順番を変えられます。', required: true, options: $options),
            new AdminField('tags', FieldType::Text, 'タグ', help: 'ショップの絞り込みに出ます。カンマ区切りで '.CatalogSortItems::MAX_TAGS.' 個まで（1 つ '.CatalogSortItems::TAG_MAX.' 文字まで）。例: 新作, ギフト', max: CatalogSortItems::TAGS_INPUT_MAX),
        ];
    }

    public function values(string $id): ?array
    {
        $item = CatalogSortItem::query()->find($id);

        return $item === null ? null : ['listing' => $item->listing_id, 'tags' => implode(', ', $item->tags)];
    }

    public function create(array $values, string $actor): string
    {
        return self::items()->create($values, $actor);
    }

    public function update(string $id, array $values, string $actor): void
    {
        self::items()->update($id, $values, $actor);
    }

    /**
     * 書く係（店の文脈の中で呼ばれた時に作る。登録の時には作らない）
     */
    private static function items(): CatalogSortItems
    {
        return app(CatalogSortItems::class);
    }

    /**
     * @return Builder<CatalogSortItem>
     */
    private static function filtered(string $filter): Builder
    {
        $query = CatalogSortItem::query();

        return match ($filter) {
            'tagged' => $query->whereRaw('jsonb_array_length(tags) > 0'),
            'untagged' => $query->whereRaw('jsonb_array_length(tags) = 0'),
            default => $query,
        };
    }

    /**
     * 商品の名前（消した商品は入らない）
     *
     * @param  Collection<int, CatalogSortItem>  $items
     * @return array<string, string>
     */
    private static function titles(Collection $items): array
    {
        $ids = $items->pluck('listing_id')->unique()->values()->all();

        /** @var array<string, string> */
        return $ids === [] ? [] : Listing::query()->whereKey($ids)->pluck('title', 'id')->all();
    }

    /**
     * @param  array<string, string>  $titles
     */
    private static function listingLabel(CatalogSortItem $item, array $titles): string
    {
        return isset($titles[$item->listing_id]) ? self::cut($titles[$item->listing_id]) : '（削除した商品）';
    }

    /**
     * 画面の文の上限（CellType::TEXT_MAX 文字）に収める
     */
    private static function cut(string $text): string
    {
        return mb_strlen($text) > CellType::TEXT_MAX ? mb_substr($text, 0, CellType::TEXT_MAX - 1).'…' : $text;
    }
}
