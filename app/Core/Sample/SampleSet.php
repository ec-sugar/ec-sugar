<?php

declare(strict_types=1);

namespace App\Core\Sample;

/**
 * 入れたときのサンプルのセット。1 つのセットは 1 つのテーマに合わせた、商品・カテゴリ・テーマの中身・送料・特商法の見本の文のひとそろい。
 * 入れるのは ShopSamples。名前と文でサンプルと分かるようにする（商品名の「（サンプル）」と、説明の先頭の知らせ NOTE）。品番は SAMPLE- で始める。
 *
 * 写真の書き方: 写真はこちらが決めた名前（英小文字・数字・ハイフン）で書き、写真のフォルダの `<名前>.webp|jpg|jpeg|png` を読む。
 * 写真を配る物に入れるのは雑貨（自作の絵。bundled）だけ。ほかのセットは photos() に写真ごとの出どころ（Unsplash）を持ち、入れる時に取る（SamplePhotos）。
 *
 * @phpstan-type VariantSpec array{sku: string, stock: int, price?: int, stopped?: bool}
 * @phpstan-type ListingSpec array{
 *     title: string,
 *     price: int,
 *     category: string,
 *     summary?: string,
 *     blocks: list<array<string, mixed>>,
 *     specs?: list<array{label: string, value: string}>,
 *     photos: array<string, array{0: string, 1: string}>,
 *     gallery?: list<array{0: string, 1: string}>,
 *     options?: list<array{name: string, values: list<string>}>,
 *     variants?: array<string, VariantSpec>,
 *     sku?: string,
 *     stock?: int,
 *     addons?: list<array<string, mixed>>,
 *     tax_class?: 'standard'|'reduced',
 *     kind?: string,
 * }
 * @phpstan-type PhotoSource array{id?: string, url?: string, author: string|null, page: string|null}
 *
 * - listings の kind は商品の扱い（無ければ product。App の扱い〔ticket・booking・download〕はデモの店が使う。ADR 0086）
 * - listings の tax_class は消費税の区分（ADR 0063。無ければ標準 10%。食べ物のセットは軽減 8%）
 * - listings の photos は [写真の名前, 代わりの文字]。種類のある商品は 1 つめの軸（色）の値ごと、無い商品は '' の 1 枚。gallery は色に紐づけない写真（photos の後に並ぶ）
 * - 説明のブロックの写真（type: image）は media_id の代わりに photo（写真の名前）を書く。入れる時にショップの写真に上げてから指す（写真が無ければブロックを外す）
 * - themeSections の値は、写真を `media:<MEDIA の名前>`、カテゴリを `category:<slug>`、商品を `listing:<LISTINGS の名前>` で書き、入れる時に ID へ替える（無い物は外す）
 */
interface SampleSet
{
    /** 説明の先頭に置く知らせ（どのセットの商品にも） */
    public const string NOTE = 'サンプルの商品です。実際には販売していません。管理画面のホームの「サンプルをまとめて消す」で、ほかのサンプルと一緒に消せます。';

    /** 特商法の表記の見本で、お店が書き換える所に付ける印 */
    public const string LEGAL_TODO = '（見本です。公開の前に書き換えてください）';

    /** セットの名前（英小文字。コマンドの --set） */
    public function name(): string;

    /** 画面とコマンドに出す名前（例「雑貨のお店」） */
    public function label(): string;

    /** 合わせたテーマ（入れる時に店のテーマをこれに切り替える） */
    public function theme(): string;

    /** 写真を配る物に入れているか（入れているなら、写真が欠けていれば止める。取りには行かない） */
    public function bundled(): bool;

    /**
     * カテゴリ（slug => 名前）。同じ slug のカテゴリがお店にもうあれば、それを使う（印は付けない）
     *
     * @return array<string, string>
     */
    public function categories(): array;

    /**
     * 商品（サンプルの中の名前 => 中身）。古い順に作る（ショップの新着は新しい順なので、最後の物が先頭に出る）
     *
     * @return array<string, ListingSpec>
     */
    public function listings(): array;

    /**
     * ショップの写真（サンプルの中の名前 => [写真の名前, 代わりの文字]）。テーマの中身が使う
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function media(): array;

    /**
     * テーマの区画の値（今の形: 種類 => {values, items, blocks}）
     *
     * @return array<string, array<string, mixed>>
     */
    public function themeSections(): array;

    /**
     * 送料（お店がまだ決めていないときだけ入れる。消さずに残す）。消しても残るので、どのセットも同じ値にする（¥800・¥5,000 以上で無料。表記の文と合わせる）
     *
     * @return array{fee: int, free_over: int|null}
     */
    public function shipping(): array;

    /**
     * 特定商取引法に基づく表記の見本（お店がまだ書いていないときだけ入れる）
     *
     * @return list<array{label: string, value: string}>
     */
    public function legal(): array;

    /**
     * 写真ごとの出どころ（写真の名前 => Unsplash の写真の ID か images.unsplash.com の URL・撮った人・ページ）。bundled なセットは空
     *
     * @return array<string, PhotoSource>
     */
    public function photos(): array;
}
