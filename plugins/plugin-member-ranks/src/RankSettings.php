<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks;

use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;

/**
 * 会員ランクの設定（App の設定の画面。ADR 0079）: 使うランクの数（1〜3）と、ランクごとの名前・なる購入額・会員価格・ポイントの上乗せ、上がった時の知らせ。
 * 空の項目は既定の例（ブロンズ・シルバー・ゴールド）の値。値の形はマニフェストの決まりで確かめてあるが、読む時も範囲に収める。
 * 上のランクの額は下のランクより大きくないと使わない（そこから上のランクは無いものとして扱い、App の画面のやることに出す）
 */
final class RankSettings
{
    public const string PLUGIN = 'member-ranks';

    /** 既定の例（位置 => [名前, 額, 会員価格 %, 上乗せの万分率]） */
    public const array EXAMPLES = [
        1 => ['ブロンズ', 10000, 0, 50],
        2 => ['シルバー', 50000, 3, 100],
        3 => ['ゴールド', 100000, 5, 200],
    ];

    public const int MAX_THRESHOLD = 99_999_999;

    private const array COUNTS = ['one' => 1, 'two' => 2, 'three' => 3];

    public function enabled(): bool
    {
        return tenant() !== null && app(PluginStates::class)->enabled(self::PLUGIN);
    }

    public function notify(): bool
    {
        return ($this->values()['notify'] ?? true) !== false;
    }

    /**
     * 使うランク（位置 => ランク。下から順。額が下のランク以下のランクと、そこから上は入れない）
     *
     * @return array<int, Rank>
     */
    public function ranks(): array
    {
        return $this->read()[0];
    }

    /**
     * 額が下のランク以下で使えないランクの位置（無ければ null）
     */
    public function brokenAt(): ?int
    {
        return $this->read()[1];
    }

    /**
     * @return array{0: array<int, Rank>, 1: int|null}
     */
    private function read(): array
    {
        $values = $this->values();
        $count = self::COUNTS[is_string($values['ranks'] ?? null) ? $values['ranks'] : 'three'] ?? 3;
        $ranks = [];
        $previous = -1;

        for ($position = 1; $position <= $count; $position++) {
            [$name, $threshold, $discount, $bonus] = self::EXAMPLES[$position];
            $rank = new Rank(
                $position,
                self::text($values["rank{$position}_name"] ?? null) ?? $name,
                self::number($values["rank{$position}_threshold"] ?? null, $threshold, 0, self::MAX_THRESHOLD),
                self::number($values["rank{$position}_discount"] ?? null, $discount, 0, 50),
                self::bonus($values["rank{$position}_points"] ?? null, $bonus),
            );

            if ($rank->threshold <= $previous) {
                return [$ranks, $position];
            }

            $ranks[$position] = $rank;
            $previous = $rank->threshold;
        }

        return [$ranks, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function values(): array
    {
        return app(PluginSettings::class)->publicValues(self::PLUGIN) ?? [];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || mb_strlen($value) > 20 || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1 ? null : $value;
    }

    private static function number(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_string($value) || preg_match('/\A[0-9]{1,8}\z/', $value) !== 1) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * ポイントの上乗せ（「0.5」→ 50。0〜10% を 0.1 きざみ）
     */
    private static function bonus(mixed $value, int $default): int
    {
        if (! is_string($value) || preg_match('/\A([0-9]{1,2})(?:\.([0-9]))?\z/', $value, $m) !== 1) {
            return $default;
        }

        return max(0, min(1000, (int) $m[1] * 100 + (int) ($m[2] ?? 0) * 10));
    }
}
