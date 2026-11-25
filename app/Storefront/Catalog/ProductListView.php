<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Plugin\Catalog\CatalogBand;
use App\Core\Plugin\Catalog\CatalogTag;

/**
 * 店頭の商品の一覧（テーマ API の一部）。URL は店頭が作って渡す（テーマは組み立てない）。
 * 並べ替えの選択肢と絞り込みは App「並び順と絞り込み」を使う店だけで増える（使わない店では今までの 3 つと、絞り込みなし）
 */
final readonly class ProductListView
{
    public const array SORTS = ['new' => '新着順', 'price_asc' => '価格の安い順', 'price_desc' => '価格の高い順'];

    /** 店が手で決めた並び（App を使い、並びを決めた店だけ） */
    public const string SHOP_SORT = 'shop';

    /**
     * @param  list<ListingCard>  $items
     * @param  array{name: string, slug: string}|null  $category  カテゴリの一覧のときだけ
     * @param  list<array{name: string, url: string}>  $breadcrumbs  一番上から、このカテゴリの親まで
     * @param  list<array{name: string, url: string}>  $children  下の段のカテゴリ
     * @param  ?CatalogFilters  $filters  並べ替えの選択肢と絞り込み（App を使わない店では null）
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $lastPage,
        public string $sort,
        public ?string $query,
        public ?array $category,
        public array $breadcrumbs,
        public array $children,
        private string $baseUrl,
        private ?CatalogFilters $filters = null,
    ) {}

    public function title(): string
    {
        return $this->category['name'] ?? ($this->query !== null ? "「{$this->query}」の検索結果" : 'すべての商品');
    }

    /**
     * @return list<array{value: string, label: string, url: string, current: bool}>
     */
    public function sorts(): array
    {
        $labels = $this->filters?->sorts() ?? self::SORTS;

        return array_map(fn (string $value): array => [
            'value' => $value, 'label' => $labels[$value], 'url' => $this->url(1, $value), 'current' => $value === $this->sort,
        ], array_keys($labels));
    }

    /**
     * お客さんに出す絞り込み（出さない店では空）。値段の帯・タグ・在庫ありのまとまりごとに、選択肢とその URL。
     * 同じものを選び直すと外れる URL になる（「すべて」は、そのまとまりだけを外す）
     *
     * @return list<array{key: string, label: string, options: list<array{value: string, label: string, url: string, current: bool}>}>
     */
    public function filters(): array
    {
        if ($this->filters === null || ! $this->filters->shown()) {
            return [];
        }

        $groups = [];

        if ($this->filters->bands !== []) {
            $groups[] = [
                'key' => CatalogFilters::PRICE,
                'label' => '価格',
                'options' => [
                    $this->option(CatalogFilters::PRICE, '', 'すべて', $this->filters->band === null, $this->filters->without(CatalogFilters::PRICE)),
                    ...array_map(fn (CatalogBand $band): array => $this->option(
                        CatalogFilters::PRICE, $band->key, $band->label, $this->filters->band?->key === $band->key, $this->filters->toggled(CatalogFilters::PRICE, $band->key),
                    ), $this->filters->bands),
                ],
            ];
        }

        if ($this->filters->tags !== []) {
            $groups[] = [
                'key' => CatalogFilters::TAG,
                'label' => 'タグ',
                'options' => [
                    $this->option(CatalogFilters::TAG, '', 'すべて', $this->filters->tag === null, $this->filters->without(CatalogFilters::TAG)),
                    ...array_map(fn (CatalogTag $tag): array => $this->option(
                        CatalogFilters::TAG, $tag->key, $tag->label, $this->filters->tag?->key === $tag->key, $this->filters->toggled(CatalogFilters::TAG, $tag->key),
                    ), $this->filters->tags),
                ],
            ];
        }

        if ($this->filters->showsInStock) {
            $groups[] = [
                'key' => CatalogFilters::STOCK,
                'label' => '在庫',
                'options' => [$this->option(CatalogFilters::STOCK, '1', '在庫あり', $this->filters->inStock, $this->filters->toggled(CatalogFilters::STOCK, '1'))],
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{value: string, label: string, url: string, current: bool}
     */
    private function option(string $group, string $value, string $label, bool $current, array $params): array
    {
        return ['value' => $value === '' ? $group.'-all' : $group.'-'.$value, 'label' => $label, 'url' => $this->url(1, null, $params), 'current' => $current];
    }

    /**
     * @param  array<string, string>|null  $filters  絞り込みの引数（渡さなければ今の絞り込みのまま）
     */
    public function url(int $page, ?string $sort = null, ?array $filters = null): string
    {
        $sort ??= $this->sort;
        $params = array_filter([
            'q' => $this->query,
            'sort' => $sort === ($this->filters?->defaultSort() ?? 'new') ? null : $sort,
        ], static fn (mixed $value): bool => $value !== null)
            + ($filters ?? $this->filters?->params() ?? []);

        if ($page > 1) {
            $params['page'] = $page;
        }

        return $this->baseUrl.($params === [] ? '' : '?'.http_build_query($params));
    }

    public function previousUrl(): ?string
    {
        return $this->page > 1 ? $this->url($this->page - 1) : null;
    }

    public function nextUrl(): ?string
    {
        return $this->page < $this->lastPage ? $this->url($this->page + 1) : null;
    }

    /**
     * ページ送りに並べる番号（前後 2 つと最初・最後。間は null）
     *
     * @return list<int|null>
     */
    public function pages(): array
    {
        $numbers = array_values(array_unique(array_filter(
            [1, ...range(max(1, $this->page - 2), min($this->lastPage, $this->page + 2)), $this->lastPage],
            fn (int $n): bool => $n >= 1 && $n <= $this->lastPage,
        )));
        sort($numbers);
        $out = [];

        foreach ($numbers as $i => $n) {
            if ($i > 0 && $n - $numbers[$i - 1] > 1) {
                $out[] = null;
            }
            $out[] = $n;
        }

        return $out;
    }
}
