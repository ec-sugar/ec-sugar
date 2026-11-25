<?php

declare(strict_types=1);

namespace App\Admin\Demo\Shops;

use App\Admin\Demo\DemoDefinition;
use App\Core\Listing\ListingKinds;

/**
 * デモの店の共通の形（ADR 0086）。商品は短い表（PRODUCTS）で書き、入れたときのサンプルの形（ListingSpec）にして渡す。
 * 店の名前・人の名前・所在地はどれも架空（特商法の表記にも「架空」と書く）。写真は Unsplash（出どころは photos()）。
 * 商品の扱い（kind）を足す App が入っていない木（公開用の 1.0 には予約・チケットの App が無い。ADR 0098）では、その扱いの商品を作らない
 *
 * 商品の表: 名前 => [商品名, 値段, 在庫, カテゴリ, 写真の名前の並び, 短い説明, 説明, 仕様, その他]
 * その他は ListingSpec の残り（options・variants・kind・tax_class・addons）
 *
 * @phpstan-import-type ListingSpec from \App\Core\Sample\SampleSet
 *
 * @phpstan-type Product array{0: string, 1: int, 2: int, 3: string, 4: list<string>, 5: string, 6: string, 7: list<array{label: string, value: string}>, 8?: array<string, mixed>}
 */
abstract class BaseDemo implements DemoDefinition
{
    /** 送料（デモの店はどれも ¥800・¥5,000 以上で無料。表記の文と合わせる） */
    public const array SHIPPING = ['fee' => 800, 'free_over' => 5000];

    /** 説明の先頭に置く知らせ */
    public const string NOTE = 'デモのお店の商品です（架空のお店・架空の商品）。注文しても代金は動かず、商品は届きません。';

    /**
     * 商品の短い表
     *
     * @return array<string, Product>
     */
    abstract protected function products(): array;

    /**
     * 写真の代わりの文字（写真の名前 => 文）
     *
     * @return array<string, string>
     */
    abstract protected function alts(): array;

    /**
     * 架空の事業者（販売業者・運営責任者・所在地・電話・メール）
     *
     * @return array{seller: string, person: string, address: string, phone: string, email: string}
     */
    abstract protected function company(): array;

    public function name(): string
    {
        return $this->kind();
    }

    public function label(): string
    {
        return $this->names()[0];
    }

    public function bundled(): bool
    {
        return false;
    }

    public function listings(): array
    {
        $listings = [];
        $kinds = app(ListingKinds::class);

        foreach ($this->products() as $key => $row) {
            [$title, $price, $stock, $category, $photos, $summary, $body, $specs] = $row;
            $extra = $row[8] ?? [];

            if (! $kinds->has((string) ($extra['kind'] ?? 'product'))) {
                continue;
            }

            $alts = $this->alts();
            $named = array_map(static fn (string $photo): array => [$photo, $alts[$photo] ?? $title], $photos);

            /** @var ListingSpec $spec */
            $spec = [
                'title' => $title,
                'price' => $price,
                'category' => $category,
                'summary' => $summary,
                'blocks' => [
                    ['type' => 'note', 'tone' => 'info', 'text' => self::NOTE],
                    ...array_map(static fn (string $p): array => ['type' => 'paragraph', 'text' => $p], explode("\n\n", $body)),
                ],
                'specs' => $specs,
                'photos' => isset($extra['options']) ? [] : ['' => $named[0]],
                'gallery' => isset($extra['options']) ? $named : array_slice($named, 1),
                'stock' => $stock,
            ] + $extra;
            $listings[$key] = $spec;
        }

        return $listings;
    }

    public function shipping(): array
    {
        return self::SHIPPING;
    }

    public function legal(): array
    {
        $c = $this->company();

        return [
            ['label' => '販売業者', 'value' => "{$c['seller']}（デモのための架空の事業者です）"],
            ['label' => '運営責任者', 'value' => "{$c['person']}（架空）"],
            ['label' => '所在地', 'value' => "{$c['address']}（架空の住所です）"],
            ['label' => '電話番号', 'value' => "{$c['phone']}（架空の番号です）"],
            ['label' => 'メールアドレス', 'value' => $c['email']],
            ['label' => '販売価格', 'value' => '各商品のページに表示しています（税込み）'],
            ['label' => '商品代金以外の必要料金', 'value' => "送料 ¥800\n¥5,000 以上のご注文で送料無料"],
            ['label' => 'お支払いの方法と時期', 'value' => 'クレジットカード（ご注文の確定のとき）。デモのお店では代金は動きません'],
            ['label' => '引き渡しの時期', 'value' => 'デモのお店のため、商品はお届けしません'],
            ['label' => '返品・交換', 'value' => 'デモのお店のため、返品・交換はありません（注文の取消と返金の流れは管理画面で試せます）'],
        ];
    }

    public function files(): array
    {
        return [];
    }

    public function forms(): array
    {
        return [];
    }

    public function settings(): array
    {
        return [];
    }
}
