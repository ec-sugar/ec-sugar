<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use App\Core\Plugin\Services\PluginSettings;
use App\Core\Shop\Models\ShopSettings;

/**
 * 送り状のご依頼主（荷送人。店）。この App の設定を先に使い、空の項目は店の設定から埋める
 * （名前は店の名前、郵便番号と住所は特定商取引法の表記の「所在地」、電話は同じく「電話番号」）。
 * 店の事実で、お客さんの個人情報ではない
 */
final readonly class Shipper
{
    public function __construct(
        public string $name,
        public string $zip,
        public string $address,
        public string $phone,
    ) {}

    public static function current(): self
    {
        $settings = app(PluginSettings::class)->publicValues(ShippingCsvServiceProvider::NAME) ?? [];
        $legal = self::legal();
        $place = self::firstLabelled($legal, ['所在地', '住所']);
        [$legalZip, $legalAddress] = self::splitZip($place);

        return new self(
            name: self::setting($settings, 'shipper_name') ?? (string) tenant()?->getAttribute('name'),
            zip: self::zip(self::setting($settings, 'shipper_zip') ?? $legalZip),
            address: self::setting($settings, 'shipper_address') ?? $legalAddress,
            phone: self::setting($settings, 'shipper_phone') ?? self::phone(self::firstLabelled($legal, ['電話'])),
        );
    }

    /**
     * この App の設定の値（秘密でない文。空は null）
     *
     * @param  array<string, bool|string>  $settings
     */
    public static function setting(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function complete(): bool
    {
        return $this->name !== '' && $this->zip !== '' && $this->address !== '' && $this->phone !== '';
    }

    /**
     * 郵便番号を 123-4567 の形に（全角の数字も。数字が 7 桁でなければ、そのまま）
     */
    public static function zip(string $raw): string
    {
        $digits = (string) preg_replace('/\D/', '', mb_convert_kana($raw, 'n'));

        return strlen($digits) === 7 ? substr($digits, 0, 3).'-'.substr($digits, 3) : trim($raw);
    }

    /**
     * 特定商取引法の表記の項目（名前 => 内容）
     *
     * @return list<array{label: string, value: string}>
     */
    private static function legal(): array
    {
        $items = ShopSettings::query()->first()->legal ?? [];
        $out = [];

        foreach ($items as $item) {
            if (is_array($item) && is_string($item['label'] ?? null) && is_string($item['value'] ?? null)) {
                $out[] = ['label' => $item['label'], 'value' => $item['value']];
            }
        }

        return $out;
    }

    /**
     * 名前に $words のどれかを含む最初の項目の内容（改行は空白に）
     *
     * @param  list<array{label: string, value: string}>  $items
     * @param  list<string>  $words
     */
    private static function firstLabelled(array $items, array $words): string
    {
        foreach ($items as $item) {
            foreach ($words as $word) {
                if (str_contains($item['label'], $word)) {
                    return trim((string) preg_replace('/\s+/u', ' ', $item['value']));
                }
            }
        }

        return '';
    }

    /**
     * 所在地を郵便番号と住所に分ける（「〒150-0001 東京都…」の形。郵便番号が無ければ住所だけ）
     *
     * @return array{0: string, 1: string}
     */
    private static function splitZip(string $place): array
    {
        if (preg_match('/〒?\s*([0-9０-９]{3})\s*[-－ー‐]?\s*([0-9０-９]{4})/u', $place, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return ['', $place];
        }

        $address = trim(substr($place, 0, $m[0][1]).substr($place, $m[0][1] + strlen($m[0][0])));

        return [$m[1][0].$m[2][0], $address];
    }

    /**
     * 電話番号の部分（数字とハイフン。全角も半角に）
     */
    private static function phone(string $text): string
    {
        return preg_match('/[0-9][0-9-]{8,12}[0-9]/', mb_convert_kana($text, 'a'), $m) === 1 ? $m[0] : '';
    }
}
