<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\DeliverySlot;
use App\Core\Shop\DeliveryPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * お届け日時の選べる範囲（ADR 0059）。日本の時間で、受付の日（締め切りの後は翌日・休みなら次の営業日）→ 発送までの営業日 →
 * お届けまでの日数 → 選べる日数。締め切りの前後・休みの曜日と日・月末・年末・時刻の帯（UTC で渡しても日本の日付で数える）を見る
 */
final class DeliveryPolicyTest extends TestCase
{
    public function test_the_cutoff_moves_the_first_date_by_one_day_exactly_at_the_cutoff_time(): void
    {
        // 2026-11-04 は水曜。日曜休み、発送まで 1 営業日、お届けまで 1 日、締め切り 12:00
        $policy = self::policy(cutoff: '12:00', closedWeekdays: [0]);

        $this->assertSame('2026-11-06', $policy->selectableDates(self::jst('2026-11-04 11:59:59'))[0]);
        $this->assertSame('2026-11-07', $policy->selectableDates(self::jst('2026-11-04 12:00:00'))[0]);
        // 締め切りが無ければ、夜でも今日の受付
        $this->assertSame('2026-11-06', self::policy(closedWeekdays: [0])->selectableDates(self::jst('2026-11-04 23:59'))[0]);
    }

    public function test_times_are_counted_in_japan_even_when_given_in_utc(): void
    {
        $policy = self::policy(cutoff: '12:00');

        // UTC の 3 時 = 日本の 12 時（締め切りちょうど）
        $this->assertSame('2026-11-07', $policy->selectableDates(new CarbonImmutable('2026-11-04 03:00:00', 'UTC'))[0]);
        // UTC では 11-03 の 15:30 でも、日本では 11-04 の 0:30
        $this->assertSame('2026-11-06', $policy->selectableDates(new CarbonImmutable('2026-11-03 15:30:00', 'UTC'))[0]);
    }

    public function test_closed_weekdays_are_not_counted_as_shipping_days_but_deliveries_can_land_on_them(): void
    {
        $policy = self::policy(closedWeekdays: [0]);

        // 土曜の受付 → 日曜は数えず月曜に発送 → 火曜から
        $this->assertSame('2026-11-10', $policy->selectableDates(self::jst('2026-11-07 10:00'))[0]);
        // 日曜（休み）の受付は月曜の受付 → 火曜に発送 → 水曜から
        $this->assertSame('2026-11-11', $policy->selectableDates(self::jst('2026-11-08 10:00'))[0]);
        // 届く日は休みでもよい（金曜に発送 → 土曜に届く。日曜も選べる）
        $dates = $policy->selectableDates(self::jst('2026-11-05 10:00'));
        $this->assertSame('2026-11-07', $dates[0]);
        $this->assertContains('2026-11-08', $dates);
    }

    public function test_ship_days_zero_ships_on_the_day_of_receipt(): void
    {
        $policy = self::policy(shipDays: 0, transitDays: 2, cutoff: '15:00');

        $this->assertSame('2026-11-06', $policy->selectableDates(self::jst('2026-11-04 14:59'))[0]);
        $this->assertSame('2026-11-07', $policy->selectableDates(self::jst('2026-11-04 15:00'))[0]);
    }

    public function test_the_span_runs_across_the_end_of_a_month(): void
    {
        // 2026-11-30 は月曜。12-01 に発送 → 12-02 から 5 日
        $this->assertSame(['2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05', '2026-12-06'], self::policy(spanDays: 5, cutoff: '12:00')->selectableDates(self::jst('2026-11-30 10:00')));
        // 2027-02-26 は金曜（土日休み）。締め切りの後は土曜の受付 → 月曜 3-01 の受付 → 3-02 に発送 → 3-03 から
        $this->assertSame('2027-03-03', self::policy(cutoff: '12:00', closedWeekdays: [0, 6])->selectableDates(self::jst('2027-02-26 15:00'))[0]);
        // 2028 年はうるう年（2-29 がある）
        $this->assertSame(['2028-02-29', '2028-03-01'], self::policy(spanDays: 2)->selectableDates(self::jst('2028-02-27 10:00')));
    }

    public function test_closed_dates_at_the_year_end_push_shipping_into_the_new_year(): void
    {
        $policy = self::policy(spanDays: 3, cutoff: '12:00', closedWeekdays: [0], closedDates: ['2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03']);

        // 12-28（月）の締め切りの後 → 12-29 の受付は休み → 1-04 の受付 → 1-05 に発送 → 1-06 から
        $this->assertSame(['2027-01-06', '2027-01-07', '2027-01-08'], $policy->selectableDates(self::jst('2026-12-28 13:00')));
        // 締め切りの前なら 12-28 の受付 → 次の営業日は 1-04 に発送 → 1-05 から
        $this->assertSame('2027-01-05', $policy->selectableDates(self::jst('2026-12-28 11:00'))[0]);
    }

    public function test_choices_outside_the_range_or_unknown_to_the_shop_are_problems(): void
    {
        $policy = self::policy(spanDays: 3, slots: [DeliverySlot::Morning, DeliverySlot::From19To21]);
        $now = self::jst('2026-11-04 10:00');
        // 選べるのは 11-06・11-07・11-08

        $this->assertNull($policy->problem(new DeliveryChoice('2026-11-06', DeliverySlot::Morning), $now));
        $this->assertNull($policy->problem(new DeliveryChoice('2026-11-08', null), $now));
        $this->assertNull($policy->problem(new DeliveryChoice(null, DeliverySlot::From19To21), $now));
        // 範囲の前・後
        $this->assertArrayHasKey('delivery.date', (array) $policy->problem(new DeliveryChoice('2026-11-05', null), $now));
        $this->assertArrayHasKey('delivery.date', (array) $policy->problem(new DeliveryChoice('2026-11-09', null), $now));
        // 店が選んでいない時間帯
        $this->assertSame(['delivery.slot' => 'お選びの時間帯（14時〜16時）は、現在お選びいただけません。時間帯を選び直してください。'], $policy->problem(new DeliveryChoice('2026-11-06', DeliverySlot::From14To16), $now));
        // 日付を受けない店（時間帯だけ受ける）に日付
        $slotsOnly = self::policy(dates: false, slots: [DeliverySlot::Morning]);
        $this->assertTrue($slotsOnly->offered());
        $this->assertSame([], $slotsOnly->selectableDates($now));
        $this->assertArrayHasKey('delivery.date', (array) $slotsOnly->problem(new DeliveryChoice('2026-11-06', null), $now));
        $this->assertNull($slotsOnly->problem(new DeliveryChoice(null, DeliverySlot::Morning), $now));
        // 何も受けない店
        $this->assertFalse((new DeliveryPolicy)->offered());
        $this->assertNotNull((new DeliveryPolicy)->problem(new DeliveryChoice(null, DeliverySlot::Morning), $now));
    }

    public function test_broken_stored_values_fall_back_to_not_offering(): void
    {
        $this->assertFalse(DeliveryPolicy::fromArray(null)->offered());
        // 曜日を全部休みにした値は計算できない
        $this->assertFalse(DeliveryPolicy::fromArray(['dates' => true, 'closed_weekdays' => [0, 1, 2, 3, 4, 5, 6]])->offered());
        // 知らない時間帯・範囲の外の数・壊れた日付と時刻は捨てる
        $policy = DeliveryPolicy::fromArray(['dates' => true, 'slots' => ['am', 'x', '12-14'], 'ship_days' => 999, 'span_days' => 0, 'cutoff' => '25:00', 'closed_dates' => ['2026-02-30', 'x', '2026-12-31'], 'closed_weekdays' => [7, -1, 3]]);
        $this->assertSame([DeliverySlot::Morning], $policy->slots);
        $this->assertSame(1, $policy->shipDays);
        $this->assertSame(1, $policy->spanDays);
        $this->assertNull($policy->cutoff);
        $this->assertSame(['2026-12-31'], $policy->closedDates);
        $this->assertSame([3], $policy->closedWeekdays);
        // 保存の形と行き来できる
        $this->assertEquals($policy, DeliveryPolicy::fromArray($policy->toArray()));
    }

    public function test_a_choice_needs_a_real_calendar_date_and_a_known_slot(): void
    {
        $this->assertNull(DeliveryChoice::of('', ''));
        $this->assertNull(DeliveryChoice::of(null, null));
        $both = DeliveryChoice::of('2026-11-07', 'am');
        $this->assertNotNull($both);
        $this->assertSame(['date' => '2026-11-07', 'slot' => 'am'], $both->toArray());
        $this->assertSame('2026年11月7日（土） 午前中', $both->label());
        $this->assertSame('日付の指定なし 19時〜21時', DeliveryChoice::of('', '19-21')?->label());
        $this->assertSame('2027年1月4日（月） 時間帯の指定なし', DeliveryChoice::of('2027-01-04', null)?->label());

        foreach ([['2026-02-30', null], ['2026-13-01', null], ['2026-1-05', null], ['20261105', null], [null, '12-14'], [null, 'AM']] as [$date, $slot]) {
            try {
                DeliveryChoice::of($date, $slot);
                $this->fail("{$date} {$slot} を通した");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param  list<DeliverySlot>  $slots
     * @param  list<int>  $closedWeekdays
     * @param  list<string>  $closedDates
     */
    private static function policy(bool $dates = true, array $slots = [], int $shipDays = 1, int $transitDays = 1, int $spanDays = 14, ?string $cutoff = null, array $closedWeekdays = [], array $closedDates = []): DeliveryPolicy
    {
        return new DeliveryPolicy($dates, $slots, $shipDays, $transitDays, $spanDays, $cutoff, $closedWeekdays, $closedDates);
    }

    private static function jst(string $time): CarbonImmutable
    {
        return new CarbonImmutable($time, 'Asia/Tokyo');
    }
}
