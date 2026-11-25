<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Tax\TaxClass;

/**
 * 商品の CSV の 1 つの形（列の名前と、状態などの値の呼び名の対応）。
 *
 * 中では列を決まった鍵（下の KEYS）で扱い、形ごとに「見出しの文字 → 鍵」を持つ。
 * ほかのサービスの CSV を読めるようにするときは、ここに形を 1 つ足し（見出しの名前と値の呼び名を写す）、ProductCsvFormats::all() に並べる。
 * 書き出すのはいつも EC-SUGAR の形だけ。
 */
final readonly class ProductCsvFormat
{
    /** 列の鍵（中で使う名前）。書き出しの列の順もこの順 */
    public const array KEYS = [
        'listing_id', 'sku', 'title', 'status', 'unit_amount', 'variant_amount', 'stock', 'variant_status',
        'option1_name', 'option1_value', 'option2_name', 'option2_value', 'option3_name', 'option3_value',
        'categories', 'summary', 'description', 'addons', 'tax_class',
        // 写真。列を足すときは末尾に（前からの CSV の列の並びを崩さない）
        'photo1', 'photo2', 'photo3', 'photo4', 'photo5', 'photo6', 'photo7', 'photo8', 'photo9', 'photo10',
    ];

    /** 商品の行を決める列（どれか 1 つは要る） */
    public const array IDENTIFYING = ['listing_id', 'sku', 'title'];

    /**
     * @param  array<string, string>  $headers  見出しの文字 => 鍵
     * @param  array<string, string>  $statuses  状態の呼び名 => ListingStatus の値
     * @param  array<string, string>  $variantStatuses  種類の販売の呼び名 => VariantStatus の値
     * @param  array<string, string>  $taxClasses  税率の呼び名 => TaxClass の値（ADR 0063）
     */
    public function __construct(
        public string $name,
        public array $headers,
        public array $statuses,
        public array $variantStatuses,
        public array $taxClasses = [],
    ) {}

    /**
     * EC-SUGAR の形（書き出すのと同じ）。状態は画面の呼び名のほか、英語の値も読む
     */
    public static function ecSugar(): self
    {
        return new self(
            name: 'ec-sugar',
            headers: [
                '商品ID' => 'listing_id',
                '品番' => 'sku',
                '商品名' => 'title',
                '状態' => 'status',
                '価格' => 'unit_amount',
                '種類の価格' => 'variant_amount',
                '在庫' => 'stock',
                '種類の販売' => 'variant_status',
                '選ぶ項目1' => 'option1_name',
                '選ぶ項目1の値' => 'option1_value',
                '選ぶ項目2' => 'option2_name',
                '選ぶ項目2の値' => 'option2_value',
                '選ぶ項目3' => 'option3_name',
                '選ぶ項目3の値' => 'option3_value',
                'カテゴリ' => 'categories',
                '短い説明' => 'summary',
                '説明' => 'description',
                'オプション' => 'addons',
                '税率' => 'tax_class',
                '写真1' => 'photo1',
                '写真2' => 'photo2',
                '写真3' => 'photo3',
                '写真4' => 'photo4',
                '写真5' => 'photo5',
                '写真6' => 'photo6',
                '写真7' => 'photo7',
                '写真8' => 'photo8',
                '写真9' => 'photo9',
                '写真10' => 'photo10',
            ],
            statuses: [
                '下書き' => ListingStatus::Draft->value,
                '公開中' => ListingStatus::Active->value,
                '終了' => ListingStatus::Archived->value,
                'draft' => ListingStatus::Draft->value,
                'active' => ListingStatus::Active->value,
                'archived' => ListingStatus::Archived->value,
            ],
            variantStatuses: [
                '販売中' => VariantStatus::Active->value,
                '停止中' => VariantStatus::Stopped->value,
                'active' => VariantStatus::Active->value,
                'stopped' => VariantStatus::Stopped->value,
            ],
            // 消費税の区分（ADR 0063）。書き出しは「標準」「軽減」。率の書き方と英語の値も読む
            taxClasses: [
                '標準' => TaxClass::Standard->value,
                '軽減' => TaxClass::Reduced->value,
                '10%' => TaxClass::Standard->value,
                '8%' => TaxClass::Reduced->value,
                'standard' => TaxClass::Standard->value,
                'reduced' => TaxClass::Reduced->value,
            ],
        );
    }

    /**
     * 読める形の一覧（見出しの行と合わせて、最初に合ったものを使う）。ほかのサービスの形はここに足す
     *
     * @return list<self>
     */
    public static function formats(): array
    {
        return [self::ecSugar()];
    }

    /**
     * 鍵 => 見出しの文字（書き出しの見出し）
     */
    public function headerOf(string $key): string
    {
        $header = array_search($key, $this->headers, true);

        return is_string($header) ? $header : $key;
    }

    /**
     * 値の呼び名（書き出しに使う。最初に書いた呼び名）
     *
     * @param  array<string, string>  $names
     */
    public static function nameOf(array $names, string $value): string
    {
        $name = array_search($value, $names, true);

        return is_string($name) ? $name : $value;
    }
}
