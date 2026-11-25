<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint;

use App\Core\Media\Models\ShopMedia;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Shop\Services\ShopSettingsService;

/**
 * 書類の発行者（店）。名前・所在地・電話は店の設定の「特定商取引法に基づく表記」の「販売業者」「所在地」「電話番号」から
 * （無ければ店の名前だけ）。登録番号・但し書きの既定・ロゴは App の設定から
 */
final readonly class Issuer
{
    public const string DEFAULT_PROVISO = 'お品代として';

    public function __construct(
        public string $name,
        public ?string $address,
        public ?string $phone,
        public ?string $registrationNumber,
        public string $proviso,
        public ?string $logoMediaId,
    ) {}

    public static function current(): self
    {
        $legal = [];

        foreach (app(ShopSettingsService::class)->legal() as $row) {
            $legal[$row['label']] ??= trim($row['value']);
        }

        $settings = app(PluginSettings::class)->publicValues(OrderPrintServiceProvider::NAME) ?? [];
        $text = static fn (string $key): ?string => is_string($settings[$key] ?? null) && $settings[$key] !== '' ? $settings[$key] : null;
        $logo = ($settings['show_logo'] ?? false) === true ? $text('logo') : null;

        return new self(
            name: ($legal['販売業者'] ?? '') !== '' ? $legal['販売業者'] : (string) tenant()?->getAttribute('name'),
            address: ($legal['所在地'] ?? '') !== '' ? $legal['所在地'] : null,
            phone: ($legal['電話番号'] ?? '') !== '' ? $legal['電話番号'] : null,
            registrationNumber: $text('registration_number'),
            proviso: $text('proviso') ?? self::DEFAULT_PROVISO,
            // 選んだ写真を消していたら出さない
            logoMediaId: $logo !== null && ShopMedia::query()->whereKey($logo)->exists() ? strtolower($logo) : null,
        );
    }
}
