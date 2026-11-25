<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Plugin\Catalog\CatalogBand;
use App\Core\Plugin\Catalog\CatalogTag;
use App\Core\Plugin\CatalogBrowse;
use Illuminate\Http\Request;

/**
 * ショップの一覧の並びと絞り込みの選択肢と、お客さんが選んだもの（App「並び順と絞り込み」が無い・使っていない店では、どれも空）。
 *
 * 選択肢は核の窓口（CatalogBrowse）が App に聞いて確かめたものだけ。お客さんの入力は、その選択肢の key と同じ文字の時だけ受け、
 * 違えば「選んでいない」と同じに扱う（断らない。長すぎる値・知らない値・配列で来ても一覧は出る）。
 * 列の名前や SQL をお客さんの入力から作らないので、絞り込みで組み立てるのは「どの帯か・どの商品の ID か・在庫を見るか」だけ
 */
final readonly class CatalogFilters
{
    /** URL の引数の名前 */
    public const string PRICE = 'price';

    public const string TAG = 'tag';

    public const string STOCK = 'stock';

    /** お客さんの入力を見る長さの上限（これより長い値は見ずに捨てる） */
    private const int INPUT_MAX = 24;

    /**
     * @param  list<CatalogBand>  $bands  出す値段の帯（空なら出さない）
     * @param  list<CatalogTag>  $tags  出すタグ（空なら出さない）
     * @param  bool  $showsInStock  「在庫あり」を出すか
     * @param  list<string>  $order  店が手で決めた並び（商品の ID。空なら「おすすめ順」を出さない）
     * @param  bool  $shopOrderFirst  一覧のはじめの並びを「おすすめ順」にするか
     * @param  ?CatalogBand  $band  選ばれた値段の帯
     * @param  ?CatalogTag  $tag  選ばれたタグ
     * @param  list<string>  $taggedIds  選ばれたタグの商品の ID（選んでいなければ空）
     * @param  bool  $inStock  「在庫あり」が選ばれたか
     */
    private function __construct(
        public array $bands = [],
        public array $tags = [],
        public bool $showsInStock = false,
        public array $order = [],
        public bool $shopOrderFirst = false,
        public ?CatalogBand $band = null,
        public ?CatalogTag $tag = null,
        public array $taggedIds = [],
        public bool $inStock = false,
    ) {}

    /**
     * 要求の引数（price・tag・stock）と、App が決めた選択肢から作る
     */
    public static function from(Request $request, CatalogBrowse $browse): self
    {
        $bands = $browse->bands();
        $tags = $browse->tags();
        $showsInStock = $browse->showsInStock();
        $order = $browse->order();

        $chosenBand = self::chosen($request, self::PRICE, array_map(static fn (CatalogBand $b): string => $b->key, $bands));
        $band = $chosenBand === null ? null : array_values(array_filter($bands, static fn (CatalogBand $b): bool => $b->key === $chosenBand))[0];
        $chosenTag = self::chosen($request, self::TAG, array_map(static fn (CatalogTag $t): string => $t->key, $tags));
        $tag = $chosenTag === null ? null : array_values(array_filter($tags, static fn (CatalogTag $t): bool => $t->key === $chosenTag))[0];
        $stock = $showsInStock && self::chosen($request, self::STOCK, ['1']) !== null;

        return new self(
            bands: $bands,
            tags: $tags,
            showsInStock: $showsInStock,
            order: $order,
            shopOrderFirst: $browse->shopOrderFirst(),
            band: $band,
            tag: $tag,
            taggedIds: $tag === null ? [] : $browse->taggedListings($tag->key),
            inStock: $stock,
        );
    }

    /**
     * 並べ替えの選択肢（店の並びがある店だけ「おすすめ順」を足す）
     *
     * @return array<string, string>
     */
    public function sorts(): array
    {
        return $this->order === [] ? ProductListView::SORTS : [ProductListView::SHOP_SORT => 'おすすめ順'] + ProductListView::SORTS;
    }

    /**
     * 並べ替えを選んでいないお客さんに出す並び
     */
    public function defaultSort(): string
    {
        return $this->order !== [] && $this->shopOrderFirst ? ProductListView::SHOP_SORT : 'new';
    }

    /**
     * 絞り込みを 1 つでも選んでいるか
     */
    public function active(): bool
    {
        return $this->band !== null || $this->tag !== null || $this->inStock;
    }

    /**
     * 絞り込みを 1 つでも出すか（出さない店では、テーマは何も描かない）
     */
    public function shown(): bool
    {
        return $this->bands !== [] || $this->tags !== [] || $this->showsInStock;
    }

    /**
     * 今の絞り込みを URL の引数に（ページ送り・並べ替えのリンクに付ける）
     *
     * @return array<string, string>
     */
    public function params(): array
    {
        return array_filter([
            self::PRICE => $this->band?->key,
            self::TAG => $this->tag?->key,
            self::STOCK => $this->inStock ? '1' : null,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * 1 つの絞り込みだけを差し替えた URL の引数（同じものを選び直すと外れる）
     *
     * @return array<string, string>
     */
    public function toggled(string $name, string $value): array
    {
        $params = $this->params();

        if (($params[$name] ?? null) === $value) {
            unset($params[$name]);

            return $params;
        }

        return [$name => $value] + $params;
    }

    /**
     * その絞り込みを外した URL の引数
     *
     * @return array<string, string>
     */
    public function without(string $name): array
    {
        $params = $this->params();
        unset($params[$name]);

        return $params;
    }

    /**
     * 要求の引数のうち、選択肢にあるものだけ（無ければ null）
     *
     * @param  list<string>  $allowed
     */
    private static function chosen(Request $request, string $name, array $allowed): ?string
    {
        $value = $request->query($name);

        if (! is_string($value) || $value === '' || strlen($value) > self::INPUT_MAX || ! in_array($value, $allowed, true)) {
            return null;
        }

        return $value;
    }
}
