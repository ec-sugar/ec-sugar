<?php

declare(strict_types=1);

namespace App\Core\Shop;

use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\DeliverySlot;
use Carbon\CarbonImmutable;

/**
 * お届け日時の指定の決まり（店の設定。ADR 0059）。計算は日本の時間（Asia/Tokyo）で:
 *
 * - 受付の日: 注文の日。締め切りの時刻（cutoff）を過ぎたら翌日。休み（曜日・特定の日）なら次の営業日
 * - 発送の日: 受付の日から「発送までの日数」（営業日）だけ後
 * - 選べる最初の日: 発送の日から「配達の日数」（暦の日。全国一律の最短。地域ごとの日数は送料の地域とつなぐ時に）後
 * - 選べる最後の日: 最初の日から「選べる日数」の分
 *
 * 時間帯は運送会社の共通の区分から店が選んだものだけ（空なら時間帯は選べない）。日付の指定を受けない店でも、時間帯だけは受けられる
 */
final readonly class DeliveryPolicy
{
    public const int MAX_SHIP_DAYS = 30;

    public const int MAX_TRANSIT_DAYS = 14;

    public const int MAX_SPAN_DAYS = 60;

    public const int MAX_CLOSED_DATES = 120;

    /** 次の営業日を探すのに見る日の数の上限（休みの日は MAX_CLOSED_DATES までで、曜日を全部休みにはできないので足りる） */
    private const int SEARCH_DAYS = 400;

    private const string ZONE = 'Asia/Tokyo';

    /**
     * @param  bool  $dates  日付の指定を受ける
     * @param  list<DeliverySlot>  $slots  受ける時間帯（区分の順）
     * @param  int  $shipDays  受付の日から発送までの営業日の数（0 なら受付の日に発送）
     * @param  int  $transitDays  発送からお届けまでの日数（全国一律の最短）
     * @param  int  $spanDays  選べる日の数（最初の日から）
     * @param  ?string  $cutoff  受付の締め切りの時刻（HH:MM）。過ぎた注文は翌日の受付。null は区切らない
     * @param  list<int>  $closedWeekdays  発送しない曜日（0 が日曜〜6 が土曜）
     * @param  list<string>  $closedDates  発送しない特定の日（Y-m-d。年末年始・臨時の休み）
     */
    public function __construct(
        public bool $dates = false,
        public array $slots = [],
        public int $shipDays = 1,
        public int $transitDays = 1,
        public int $spanDays = 14,
        public ?string $cutoff = null,
        public array $closedWeekdays = [],
        public array $closedDates = [],
    ) {}

    /**
     * お客さんに日時の欄を出すか（日付か時間帯のどちらかを受ける）
     */
    public function offered(): bool
    {
        return $this->dates || $this->slots !== [];
    }

    /**
     * 今（$now）注文したときに選べるお届け日（Y-m-d、早い順）。日付の指定を受けない店は空
     *
     * @return list<string>
     */
    public function selectableDates(CarbonImmutable $now): array
    {
        if (! $this->dates) {
            return [];
        }

        $now = $now->setTimezone(self::ZONE);
        $day = $now->startOfDay();

        if ($this->cutoff !== null && $now->format('H:i') >= $this->cutoff) {
            $day = $day->addDay();
        }

        $day = $this->businessDayFrom($day);

        for ($i = 0; $i < $this->shipDays && $day !== null; $i++) {
            $day = $this->businessDayFrom($day->addDay());
        }

        if ($day === null) {
            return [];
        }

        $first = $day->addDays($this->transitDays);
        $dates = [];

        for ($i = 0; $i < $this->spanDays; $i++) {
            $dates[] = $first->addDays($i)->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * 選んだ日時が、今（$now）この店で選べるか。選べなければ欄の名前（delivery.date・delivery.slot）と理由
     *
     * @return array<string, string>|null
     */
    public function problem(DeliveryChoice $choice, CarbonImmutable $now): ?array
    {
        if ($choice->date !== null) {
            if (! $this->dates) {
                return ['delivery.date' => 'お届け日の指定は、現在受け付けていません。「指定なし」でお進みください。'];
            }

            if (! in_array($choice->date, $this->selectableDates($now), true)) {
                return ['delivery.date' => 'お選びのお届け日（'.DeliveryChoice::dateLabel($choice->date).'）は、現在お選びいただけません。お届け日を選び直してください。'];
            }
        }

        if ($choice->slot !== null && ! in_array($choice->slot, $this->slots, true)) {
            return ['delivery.slot' => 'お選びの時間帯（'.$choice->slot->label().'）は、現在お選びいただけません。時間帯を選び直してください。'];
        }

        return null;
    }

    /**
     * 保存した値から。崩れた値は既定（受けない）に倒す（ショップを止めない）
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return new self;
        }

        $int = static fn (string $key, int $default, int $max): int => is_int($data[$key] ?? null) && $data[$key] >= 0 && $data[$key] <= $max ? $data[$key] : $default;
        $slots = [];
        foreach (DeliverySlot::cases() as $slot) {
            if (in_array($slot->value, (array) ($data['slots'] ?? []), true)) {
                $slots[] = $slot;
            }
        }

        $weekdays = array_values(array_filter((array) ($data['closed_weekdays'] ?? []), static fn (mixed $d): bool => is_int($d) && $d >= 0 && $d <= 6));
        $dates = array_values(array_filter((array) ($data['closed_dates'] ?? []), static fn (mixed $d): bool => is_string($d) && DeliveryChoice::isDate($d)));
        $cutoff = $data['cutoff'] ?? null;

        // 曜日を全部休みにした値は、計算できないので「受けない」に倒す
        if (count(array_unique($weekdays)) >= 7) {
            return new self;
        }

        return new self(
            dates: ($data['dates'] ?? false) === true,
            slots: $slots,
            shipDays: $int('ship_days', 1, self::MAX_SHIP_DAYS),
            transitDays: $int('transit_days', 1, self::MAX_TRANSIT_DAYS),
            spanDays: max(1, $int('span_days', 14, self::MAX_SPAN_DAYS)),
            cutoff: is_string($cutoff) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $cutoff) === 1 ? $cutoff : null,
            closedWeekdays: array_values(array_unique($weekdays)),
            closedDates: array_slice(array_values(array_unique($dates)), 0, self::MAX_CLOSED_DATES),
        );
    }

    /**
     * @return array{dates: bool, slots: list<string>, ship_days: int, transit_days: int, span_days: int, cutoff: string|null, closed_weekdays: list<int>, closed_dates: list<string>}
     */
    public function toArray(): array
    {
        return [
            'dates' => $this->dates,
            'slots' => array_map(static fn (DeliverySlot $s): string => $s->value, $this->slots),
            'ship_days' => $this->shipDays,
            'transit_days' => $this->transitDays,
            'span_days' => $this->spanDays,
            'cutoff' => $this->cutoff,
            'closed_weekdays' => $this->closedWeekdays,
            'closed_dates' => $this->closedDates,
        ];
    }

    /**
     * $day から数えて最初の営業日（休みの曜日・特定の日でない日）。見つからなければ null
     */
    private function businessDayFrom(CarbonImmutable $day): ?CarbonImmutable
    {
        for ($i = 0; $i < self::SEARCH_DAYS; $i++) {
            if (! in_array($day->dayOfWeek, $this->closedWeekdays, true) && ! in_array($day->format('Y-m-d'), $this->closedDates, true)) {
                return $day;
            }

            $day = $day->addDay();
        }

        return null;
    }
}
