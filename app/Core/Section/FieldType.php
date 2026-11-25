<?php

declare(strict_types=1);

namespace App\Core\Section;

/**
 * 区画の欄の種類（ADR 0028 の 2）。核が決めた種類だけで、テーマは足せない。
 * 上限は核の決まりで、テーマの宣言はこれより小さくだけできる（テーマが大きな数を書いても越えられない）。
 */
enum FieldType: string
{
    case Text = 'text';             // 1 行の文
    case Textarea = 'textarea';     // 複数行の文
    case Image = 'image';           // ショップの写真（shop_media の ID）
    case Link = 'link';             // 店の中の道筋か https の URL
    case Products = 'products';     // 商品の並び（新着・カテゴリ・選んだ商品）
    case Categories = 'categories'; // カテゴリの並び（選ばなければ一番上の段）
    case Choice = 'choice';         // 決めた選択肢から 1 つ
    case Html = 'html';             // HTML をそのまま書く（分かる人向け。保存と描く前に核が決まったタグと属性だけに整える。SectionHtml）
    case Richtext = 'richtext';     // 見たまま書ける文（管理画面のボタンで整える。保存の形と整え方は html と同じ）

    public const int LINK_MAX = 500;

    /** 商品の並びの出どころ */
    public const array PRODUCT_SOURCES = ['new', 'category', 'pick'];

    /**
     * HTML を持つ欄（html・richtext）。核の区画だけが使える（テーマは宣言できない。SectionSchema::fromManifest）
     */
    public function isHtml(): bool
    {
        return $this === self::Html || $this === self::Richtext;
    }

    /**
     * 長さ（文）か数（並び）の上限。上限の無い種類は null
     */
    public function cap(): ?int
    {
        return match ($this) {
            self::Text => 200,
            self::Textarea => 2000,
            self::Products => 24,
            self::Categories => 12,
            self::Html, self::Richtext => SectionHtml::MAX_CHARS,
            self::Image, self::Link, self::Choice => null,
        };
    }
}
