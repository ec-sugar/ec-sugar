<?php

declare(strict_types=1);

namespace App\Admin\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * 売上の集計の期間（ADR 0060）。日の区切りは日本の時刻（config('app.display_timezone')）。
 * 決まった期間（今日・今週〔月曜から〕・今月・先月）か、好きな期間（始まりと終わりの日。両方を含む）。
 * 重い集計にしないよう、長さは 366 日まで。終わりの日は今日まで（先の日は数えるものが無い）
 */
final readonly class SalesPeriod
{
    public const int MAX_DAYS = 366;

    public const array PRESETS = ['today', 'week', 'month', 'last_month', 'custom'];

    /**
     * @param  CarbonImmutable  $from  始まりの日の 0 時（日本の時刻）
     * @param  CarbonImmutable  $until  終わりの日の次の日の 0 時（日本の時刻。この時刻は含まない）
     */
    private function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $until,
    ) {}

    /**
     * @throws InvalidArgumentException 好きな期間の日付が違う・長すぎる・先の日
     */
    public static function of(string $preset, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): self
    {
        $zone = self::zone();
        $today = ($now ?? CarbonImmutable::now())->setTimezone($zone)->startOfDay();

        return match ($preset) {
            'today' => new self($preset, $today, $today->addDay()),
            'week' => new self($preset, $today->startOfWeek(CarbonImmutable::MONDAY), $today->addDay()),
            'month' => new self($preset, $today->startOfMonth(), $today->addDay()),
            'last_month' => new self($preset, $today->startOfMonth()->subMonth(), $today->startOfMonth()),
            'custom' => self::custom($from, $to, $today),
            default => throw new InvalidArgumentException('期間の指定が正しくありません'),
        };
    }

    /**
     * 期間の日（日本の時刻の日付。古い順）
     *
     * @return list<string>
     */
    public function days(): array
    {
        $days = [];

        for ($day = $this->from; $day < $this->until; $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /** 終わりの日（含む） */
    public function lastDay(): string
    {
        return $this->until->subDay()->format('Y-m-d');
    }

    /**
     * 画面と API に返す形
     *
     * @return array{preset: string, from: string, to: string, days: int}
     */
    public function toArray(): array
    {
        return ['preset' => $this->preset, 'from' => $this->from->format('Y-m-d'), 'to' => $this->lastDay(), 'days' => count($this->days())];
    }

    public static function zone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Tokyo');
    }

    private static function custom(?string $from, ?string $to, CarbonImmutable $today): self
    {
        $start = self::date($from);
        $end = self::date($to);

        if ($start === null || $end === null) {
            throw new InvalidArgumentException('開始日と終了日を入力してください');
        }

        if ($end < $start) {
            throw new InvalidArgumentException('終了日は開始日より後にしてください');
        }

        if ($end > $today) {
            throw new InvalidArgumentException('終了日は今日以前の日付にしてください');
        }

        if ((int) round($start->diffInDays($end)) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('期間は '.self::MAX_DAYS.' 日までにしてください');
        }

        return new self('custom', $start, $end->addDay());
    }

    /**
     * 「YYYY-MM-DD」だけを受け、日本の時刻のその日の 0 時にする（ありえない日付〔2 月 30 日など〕は断る）
     */
    private static function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 2000) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, self::zone());
    }
}
