<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use App\Core\Plugin\Services\PluginSettings;
use EcSugar\PluginDownloads\Models\DownloadListing;

/**
 * App「ダウンロード販売」の設定（ADR 0084）: 期限の日数（1〜365。既定 30）と 1 つのファイルの回数（1〜100。既定 5）。
 * 商品ごとに変えられる（商品の画面の App の欄。空なら App の設定）。範囲の外の値は捨てる（壊れた設定で渡さない）
 */
final class DownloadSettings
{
    public const int DEFAULT_DAYS = 30;

    public const int DEFAULT_LIMIT = 5;

    public const int MAX_DAYS = 365;

    public const int MAX_LIMIT = 100;

    public function __construct(private readonly PluginSettings $settings) {}

    public function days(): int
    {
        return self::within($this->settings->publicValues('downloads')['days'] ?? null, self::MAX_DAYS, self::DEFAULT_DAYS);
    }

    public function limit(): int
    {
        return self::within($this->settings->publicValues('downloads')['limit'] ?? null, self::MAX_LIMIT, self::DEFAULT_LIMIT);
    }

    /**
     * その商品の期限の日数と回数（商品の値が無ければ App の設定）
     *
     * @return array{days: int, limit: int}
     */
    public function forListing(string $listingId): array
    {
        $row = DownloadListing::query()->find($listingId);

        return [
            'days' => $row?->days !== null && $row->days >= 1 && $row->days <= self::MAX_DAYS ? $row->days : $this->days(),
            'limit' => $row?->max_count !== null && $row->max_count >= 1 && $row->max_count <= self::MAX_LIMIT ? $row->max_count : $this->limit(),
        ];
    }

    private static function within(mixed $value, int $max, int $default): int
    {
        $number = is_string($value) && preg_match('/\A[0-9]{1,3}\z/', $value) === 1 ? (int) $value : $default;

        return $number >= 1 && $number <= $max ? $number : $default;
    }
}
