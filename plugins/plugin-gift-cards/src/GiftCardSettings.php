<?php

declare(strict_types=1);

namespace EcSugar\PluginGiftCards;

use App\Core\Plugin\Services\PluginSettings;

/**
 * App「ギフトカード」の設定（ADR 0076）: 額面の選択肢（1,000〜50,000 円、8 つまで。既定 3,000・5,000・10,000）と有効期限の日数（1〜1095。既定 180）。
 * 形はマニフェストの決まりで保存の時に確かめてある。範囲の外の値は捨てる（壊れた設定で売らない）
 */
final class GiftCardSettings
{
    public const array DEFAULT_DENOMINATIONS = [3000, 5000, 10000];

    public const int DEFAULT_VALID_DAYS = 180;

    public const int MIN_AMOUNT = 1000;

    public const int MAX_AMOUNT = 50000;

    public const int MAX_VALID_DAYS = 1095;

    public const int DEFAULT_DAILY_LIMIT = 100000;

    public function __construct(private readonly PluginSettings $settings) {}

    /**
     * 選べる額面（小さい順・重なりなし）
     *
     * @return list<int>
     */
    public function denominations(): array
    {
        $value = $this->settings->publicValues('gift-cards')['denominations'] ?? null;
        $amounts = [];

        foreach (is_string($value) && $value !== '' ? explode(',', $value) : [] as $part) {
            $amount = (int) $part;

            if ($amount >= self::MIN_AMOUNT && $amount <= self::MAX_AMOUNT) {
                $amounts[$amount] = $amount;
            }
        }

        ksort($amounts);

        return $amounts === [] ? self::DEFAULT_DENOMINATIONS : array_slice($amounts, 0, 8);
    }

    /**
     * 同じメール・同じ接続元から 24 時間に買える額の合計の上限（1,000〜1,000,000 円。既定 100,000 円）
     */
    public function dailyLimit(): int
    {
        $value = $this->settings->publicValues('gift-cards')['daily_limit'] ?? null;
        $limit = is_string($value) && $value !== '' ? (int) $value : self::DEFAULT_DAILY_LIMIT;

        return $limit >= 1000 && $limit <= 1_000_000 ? $limit : self::DEFAULT_DAILY_LIMIT;
    }

    public function validDays(): int
    {
        $value = $this->settings->publicValues('gift-cards')['valid_days'] ?? null;
        $days = is_string($value) && $value !== '' ? (int) $value : self::DEFAULT_VALID_DAYS;

        return $days >= 1 && $days <= self::MAX_VALID_DAYS ? $days : self::DEFAULT_VALID_DAYS;
    }
}
