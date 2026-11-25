<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral;

use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Services\PointSettings;

/**
 * 友だち紹介の設定（App の設定の画面。ADR 0075）: 紹介した人・紹介された人に付けるポイント（0〜10,000。既定 500）と、
 * 1 人が 1 か月に紹介でポイントを受け取れる人数（1〜100。既定 10）。値の形はマニフェストの決まりで確かめてあるが、読む時も範囲に収める
 */
final class ReferralSettings
{
    public const string PLUGIN = 'referral';

    public const int DEFAULT_POINTS = 500;

    public const int MAX_POINTS = 10000;

    public const int DEFAULT_MONTHLY_LIMIT = 10;

    public const int MAX_MONTHLY_LIMIT = 100;

    /** 紹介のリンクの印の Cookie が効く日数（会員になる前のこの日数のうちだけ） */
    public const int COOKIE_DAYS = 30;

    public function referrerPoints(): int
    {
        return $this->number('referrer_points', self::DEFAULT_POINTS, 0, self::MAX_POINTS);
    }

    public function friendPoints(): int
    {
        return $this->number('friend_points', self::DEFAULT_POINTS, 0, self::MAX_POINTS);
    }

    public function monthlyLimit(): int
    {
        return $this->number('monthly_limit', self::DEFAULT_MONTHLY_LIMIT, 1, self::MAX_MONTHLY_LIMIT);
    }

    public function enabled(): bool
    {
        return tenant() !== null && app(PluginStates::class)->enabled(self::PLUGIN);
    }

    /**
     * 紹介を受け付けるか（App を使っていて、店がポイントを使っている。ADR 0068 の 7）
     */
    public function active(): bool
    {
        return $this->enabled() && app(PointSettings::class)->enabled();
    }

    private function number(string $key, int $default, int $min, int $max): int
    {
        $value = app(PluginSettings::class)->publicValues(self::PLUGIN)[$key] ?? null;

        if (! is_string($value) || preg_match('/\A[0-9]{1,5}\z/', $value) !== 1) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
