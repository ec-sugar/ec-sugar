<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Shop\Services\DeliveryDates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 設定 › お届け日時（ADR 0059）: 既定は受けない。読むのは閲覧から・変えるのは運用から。形の誤り（毎日休み・時刻・日付・知らない時間帯）は 422。
 * 変えたら記録に値を残す（個人情報を含まない）。店をまたがない
 */
final class DeliveryDateSettingsAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string API = '/api/tenants/dds/shop-settings/delivery-dates';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->travelTo(new CarbonImmutable('2026-11-04 10:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_off_by_default_read_level_reads_and_operate_level_changes_it(): void
    {
        $this->tenant('dds');
        $this->tenant('ddt');
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'dds', 'read', 'test');

        $this->send('GET', self::API)->assertOk()
            ->assertJsonPath('delivery.dates', false)->assertJsonPath('delivery.slots', [])->assertJsonPath('preview', null)
            ->assertJsonPath('slot_options.0', ['value' => 'am', 'label' => '午前中'])->assertJsonCount(5, 'slot_options');
        $this->send('PUT', self::API, self::valid())->assertForbidden();
        // ほかの店（権限の無い店）は見えない
        $this->send('GET', '/api/tenants/ddt/shop-settings/delivery-dates')->assertNotFound();

        $access->grant($staff, 'dds', 'operate', 'test');
        $this->send('PUT', self::API, [...self::valid(), 'slots' => ['19-21', 'am'], 'closed_weekdays' => [6, 0], 'closed_dates' => ['2026-12-31', '2026-12-30']])->assertOk()
            ->assertJsonPath('delivery.dates', true)
            // 区分の順・日付の順にそろえる
            ->assertJsonPath('delivery.slots', ['am', '19-21'])->assertJsonPath('delivery.closed_weekdays', [0, 6])->assertJsonPath('delivery.closed_dates', ['2026-12-30', '2026-12-31'])
            // 今注文したら選べる日の見本（水曜 10:00、締め切り 12:00 → 木曜に発送 → 金曜から 14 日）
            ->assertJsonPath('preview', ['first' => '2026年11月6日（金）', 'last' => '2026年11月19日（木）']);
        // 同じ値なら記録は増えない
        $this->send('PUT', self::API, [...self::valid(), 'slots' => ['am', '19-21'], 'closed_weekdays' => [0, 6], 'closed_dates' => ['2026-12-30', '2026-12-31']])->assertOk();

        $this->inside('dds', function () use ($staff): void {
            $event = DomainEvent::query()->where('type', 'shop.delivery_dates_updated')->sole();
            $this->assertSame('operator:'.$staff->id, $event->actor);
            $this->assertSame(['am', '19-21'], $event->payload['slots']);
            $this->assertTrue(app(DeliveryDates::class)->policy()->offered());
        });
        $this->inside('ddt', fn () => $this->assertFalse(app(DeliveryDates::class)->policy()->offered()));
    }

    public function test_malformed_settings_are_refused(): void
    {
        $this->tenant('dds');
        $this->enrolledOperator();

        $refused = [
            'closed_weekdays' => ['closed_weekdays' => [0, 1, 2, 3, 4, 5, 6]],   // 毎日休み
            'closed_weekdays.0' => ['closed_weekdays' => [7]],
            'closed_weekdays.1' => ['closed_weekdays' => [1, 1]],
            'cutoff' => ['cutoff' => '24:00'],
            'closed_dates.0' => ['closed_dates' => ['2026-02-30']],
            'slots.0' => ['slots' => ['12-14']],
            'ship_days' => ['ship_days' => 31],
            'transit_days' => ['transit_days' => -1],
            'span_days' => ['span_days' => 0],
            'dates' => ['dates' => 'たぶん'],
        ];

        foreach ($refused as $field => $values) {
            $this->send('PUT', self::API, [...self::valid(), ...$values])->assertStatus(422)->assertJsonValidationErrors($field);
        }

        // 休みの日は 120 日まで
        $days = [];
        for ($d = CarbonImmutable::parse('2026-11-04'); count($days) < 120; $d = $d->addDay()) {
            $days[] = $d->format('Y-m-d');
        }
        $this->send('PUT', self::API, [...self::valid(), 'closed_dates' => [...$days, '2027-12-31']])->assertStatus(422)->assertJsonValidationErrors('closed_dates');
        $this->inside('dds', fn () => $this->assertSame(0, DomainEvent::query()->where('type', 'shop.delivery_dates_updated')->count()));

        // 上限いっぱいの休み（2027-03-03 まで）と、日曜だけ発送する店でも、選べる日は決まる（休みの後の最初の日曜に受付 → 次の日曜に発送 → 月曜から）
        $this->send('PUT', self::API, [...self::valid(), 'closed_dates' => $days, 'closed_weekdays' => [1, 2, 3, 4, 5, 6]])->assertOk()
            ->assertJsonPath('preview.first', '2027年3月15日（月）');
    }

    /**
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        return ['dates' => true, 'slots' => [], 'ship_days' => 1, 'transit_days' => 1, 'span_days' => 14, 'cutoff' => '12:00', 'closed_weekdays' => [], 'closed_dates' => []];
    }
}
