<?php

declare(strict_types=1);

namespace EcSugar\PluginCatalogSort;

use App\Core\Plugin\Services\PluginSettings;

/**
 * App「並び順と絞り込み」の設定: どの絞り込みを出すか（値段の帯・タグ・在庫あり）、値段の帯の境目、
 * 一覧をはじめから「おすすめ順」で出すか。形はマニフェストの決まりで保存の時に確かめてあり、ここでは範囲の外の値を捨てる
 */
final class CatalogSortSettings
{
    public const string PLUGIN = 'catalog-sort';

    /** 値段の帯の境目（設定が空の店はこれ） */
    public const array DEFAULT_BANDS = [1000, 3000, 5000, 10000];

    /** 境目の数（帯はこれ ＋ 1 本になる） */
    public const int MAX_EDGES = 5;

    /** 境目の金額の上限（円） */
    public const int MAX_EDGE = 9_999_999;

    public function __construct(private readonly PluginSettings $settings) {}

    public function showsPrice(): bool
    {
        return $this->on('price_filter');
    }

    public function showsTags(): bool
    {
        return $this->on('tag_filter');
    }

    public function showsInStock(): bool
    {
        return $this->on('stock_filter');
    }

    public function shopOrderFirst(): bool
    {
        return $this->on('order_first');
    }

    /**
     * 値段の帯の境目（小さい順・重なりなし。空なら既定）
     *
     * @return list<int>
     */
    public function bandEdges(): array
    {
        $value = $this->values()['price_bands'] ?? null;
        $edges = [];

        foreach (is_string($value) && $value !== '' ? explode(',', $value) : [] as $part) {
            $edge = (int) $part;

            if ($edge > 0 && $edge <= self::MAX_EDGE) {
                $edges[$edge] = $edge;
            }
        }

        ksort($edges);

        return $edges === [] ? self::DEFAULT_BANDS : array_slice(array_values($edges), 0, self::MAX_EDGES);
    }

    private function on(string $key): bool
    {
        return ($this->values()[$key] ?? false) === true;
    }

    /**
     * @return array<string, bool|string>
     */
    private function values(): array
    {
        return $this->settings->publicValues(self::PLUGIN) ?? [];
    }
}
