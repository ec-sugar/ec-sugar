<?php

declare(strict_types=1);

namespace App\Core\Section;

/**
 * テーマの区画の「役目」（ページの雛形のため）。区画の種類の名前はテーマごとに違う（角砂糖の products、kinari の new_arrivals など）ので、
 * 核のページの雛形（PageTemplates）は「ヒーロー」「カテゴリ」「商品の並び」「安心の一行」を役目で書き、テーマは theme.json の区画に
 * "role": "hero" のように書いて、どの種類がその役目かを知らせる。
 *
 * テーマが役目を書いていない・そのページに置けないときは、核の区画で代わりにする（fallback）。代わりも置けなければ、その行を飛ばす。
 * 役目の名前は消さない・変えない（書いたテーマが登録できなくなる）
 */
enum SectionRole: string
{
    case Hero = 'hero';
    case Categories = 'categories';
    case Products = 'products';
    case Promises = 'promises';

    /**
     * 画面と文書に出す名前
     */
    public function label(): string
    {
        return match ($this) {
            self::Hero => 'ヒーロー',
            self::Categories => 'カテゴリ',
            self::Products => '商品の並び',
            self::Promises => '安心の一行',
        };
    }

    /**
     * テーマに役目の区画が無いときに代わりに置く核の区画（種類・パターン・見本の値の上書き）。
     * ヒーローは写真の上の呼びかけの帯、カテゴリは入口を 3 つ、商品の並びは核の商品の並び、安心の一行は特長の 3 列
     *
     * @return array{type: string, pattern: string, values?: array<string, mixed>}
     */
    public function fallback(): array
    {
        return match ($this) {
            self::Hero => ['type' => 'basic_cta', 'pattern' => 'photo', 'values' => [
                'heading' => 'ここにページのいちばん大きな見出しを書きます',
                'body' => 'ここに短い説明を書きます。背景の写真も選べます。',
                'button_label' => '商品を見る',
                'button_link' => '/products',
            ]],
            self::Categories => ['type' => 'basic_entries', 'pattern' => 'three'],
            self::Products => ['type' => 'basic_products', 'pattern' => 'default'],
            self::Promises => ['type' => 'basic_features', 'pattern' => 'three'],
        };
    }
}
