<?php

declare(strict_types=1);

namespace App\Core\Fulfillment;

use App\Core\Fulfillment\Enums\DeliverySlot;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * お客さんが選んだお届けの日と時間帯（ADR 0059）。どちらかは必ずある（両方とも「指定なし」なら、この形を作らず null）。
 * 日は日本の暦の日付（Y-m-d）。選べる範囲かどうかは、ここでは見ない（店の決まりと今の時刻で決まる。DeliveryDates::problem）。
 * 個人情報ではないので、受渡の平文の列とイベントに入れてよい
 */
final readonly class DeliveryChoice
{
    private const array WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    public function __construct(
        public ?string $date,
        public ?DeliverySlot $slot,
    ) {
        if ($date !== null && ! self::isDate($date)) {
            throw new InvalidArgumentException('お届け日の形が違います（Y-m-d）');
        }

        if ($date === null && $slot === null) {
            throw new InvalidArgumentException('お届けの日も時間帯も無い指定は作りません（指定なしは null）');
        }
    }

    /**
     * 入力・保存した値から（空の文字は指定なし）。両方とも指定なしなら null。形が違えば InvalidArgumentException
     */
    public static function of(mixed $date, mixed $slot): ?self
    {
        $date = is_string($date) && $date !== '' ? $date : null;
        $slot = is_string($slot) && $slot !== '' ? (DeliverySlot::tryFrom($slot) ?? throw new InvalidArgumentException('知らない時間帯です')) : null;

        return $date === null && $slot === null ? null : new self($date, $slot);
    }

    /**
     * 暦にある日付か（2026-02-30 のような日を断る）
     */
    public static function isDate(string $date): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            return false;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Tokyo');

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date;
    }

    /**
     * 「2026年11月7日（土）」の形
     */
    public static function dateLabel(string $date): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Tokyo');
        assert($day instanceof CarbonImmutable);

        return $day->format('Y年n月j日').'（'.self::WEEKDAYS[$day->dayOfWeek].'）';
    }

    /**
     * 「2026年11月7日（土） 午前中」・「日付の指定なし 午前中」・「2026年11月7日（土） 時間帯の指定なし」
     */
    public function label(): string
    {
        return ($this->date === null ? '日付の指定なし' : self::dateLabel($this->date)).' '.($this->slot?->label() ?? '時間帯の指定なし');
    }

    /**
     * @return array{date: string|null, slot: string|null}
     */
    public function toArray(): array
    {
        return ['date' => $this->date, 'slot' => $this->slot?->value];
    }
}
