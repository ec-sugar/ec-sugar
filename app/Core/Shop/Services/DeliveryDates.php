<?php

declare(strict_types=1);

namespace App\Core\Shop\Services;

use App\Core\Event\EventStore;
use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\DeliverySlot;
use App\Core\Shop\DeliveryPolicy;
use App\Core\Shop\Models\ShopSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * お届け日時の指定（ADR 0059）の店の決まりを読む・変える・照らす。変えるのはここだけ。
 * 書き込みは EventStore::lock() → 設定の行のロックの順（ADR 0012）。記録には値をそのまま残す（個人情報を含まない）
 */
final class DeliveryDates
{
    public function __construct(private readonly EventStore $events) {}

    public function policy(): DeliveryPolicy
    {
        $raw = ShopSettings::query()->value('delivery_dates');
        $data = is_string($raw) ? json_decode($raw, true) : $raw;

        return DeliveryPolicy::fromArray(is_array($data) ? $data : null);
    }

    /**
     * 今（日本の時間）注文したときに選べるお届け日
     *
     * @return list<string>
     */
    public function selectableDates(?CarbonImmutable $now = null): array
    {
        return $this->policy()->selectableDates($now ?? CarbonImmutable::now());
    }

    /**
     * 選んだ日時が今この店で選べるか。選べなければ欄の名前と理由
     *
     * @return array<string, string>|null
     */
    public function problem(DeliveryChoice $choice, ?CarbonImmutable $now = null): ?array
    {
        return $this->policy()->problem($choice, $now ?? CarbonImmutable::now());
    }

    /**
     * 決まりを置き換える。形が違えば ValidationException（欄の名前は送った名前のまま）
     *
     * @param  array<string, mixed>  $input  {dates, slots, ship_days, transit_days, span_days, cutoff, closed_weekdays, closed_dates}
     */
    public function update(array $input, ?string $actor = null): DeliveryPolicy
    {
        $data = Validator::make($input, [
            'dates' => ['required', 'boolean'],
            'slots' => ['present', 'array', 'list', 'max:'.count(DeliverySlot::cases())],
            'slots.*' => ['string', 'distinct', Rule::enum(DeliverySlot::class)],
            'ship_days' => ['required', 'integer', 'min:0', 'max:'.DeliveryPolicy::MAX_SHIP_DAYS],
            'transit_days' => ['required', 'integer', 'min:0', 'max:'.DeliveryPolicy::MAX_TRANSIT_DAYS],
            'span_days' => ['required', 'integer', 'min:1', 'max:'.DeliveryPolicy::MAX_SPAN_DAYS],
            'cutoff' => ['present', 'nullable', 'string', 'regex:/\A([01]\d|2[0-3]):[0-5]\d\z/'],
            'closed_weekdays' => ['present', 'array', 'list', 'max:6'],
            'closed_weekdays.*' => ['integer', 'distinct', 'min:0', 'max:6'],
            'closed_dates' => ['present', 'array', 'list', 'max:'.DeliveryPolicy::MAX_CLOSED_DATES],
            'closed_dates.*' => ['string', 'distinct', 'date_format:Y-m-d'],
        ], [
            'closed_weekdays.max' => '定休日は 6 つまでです（毎日休みにすると、お届け日を決められません）。',
            'cutoff.regex' => '締め切りの時刻は 12:00 のように 24 時間表記で入力してください。',
        ], [
            'dates' => '日付の指定', 'slots' => '時間帯', 'slots.*' => '時間帯', 'ship_days' => '発送までの日数', 'transit_days' => 'お届けまでの日数',
            'span_days' => '選べる日数', 'cutoff' => '締め切りの時刻', 'closed_weekdays' => '定休日（曜日）', 'closed_weekdays.*' => '定休日（曜日）',
            'closed_dates' => '臨時の休業日', 'closed_dates.*' => '臨時の休業日',
        ])->validate();

        // 区分の順・日付の順にそろえる（同じ決まりなら記録を増やさない）
        $slots = array_values(array_filter(array_map(static fn (DeliverySlot $s): string => $s->value, DeliverySlot::cases()), static fn (string $s): bool => in_array($s, $data['slots'], true)));
        $weekdays = array_map('intval', $data['closed_weekdays']);
        sort($weekdays);
        $dates = $data['closed_dates'];
        sort($dates);

        $policy = DeliveryPolicy::fromArray([
            'dates' => (bool) $data['dates'],
            'slots' => $slots,
            'ship_days' => (int) $data['ship_days'],
            'transit_days' => (int) $data['transit_days'],
            'span_days' => (int) $data['span_days'],
            'cutoff' => $data['cutoff'],
            'closed_weekdays' => $weekdays,
            'closed_dates' => $dates,
        ]);

        if ($policy->dates && $policy->selectableDates(CarbonImmutable::now()) === []) {
            throw ValidationException::withMessages(['closed_dates' => '休業日が続きすぎて、お届け日を決められません。休業日を減らしてください。']);
        }

        DB::transaction(function () use ($policy, $actor): void {
            $this->events->lock();
            $settings = ShopSettings::query()->lockForUpdate()->first() ?? ShopSettings::create([]);
            $before = $this->policy()->toArray();

            if ($before === $policy->toArray() && $settings->getRawOriginal('delivery_dates') !== null) {
                return;
            }

            DB::table('shop_settings')->where('id', $settings->id)->update(['delivery_dates' => json_encode($policy->toArray(), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            // 値は個人情報を含まないので、そのまま残す（いつから受けたか・休みをどう変えたかを後から追える）
            $this->events->append('shop.delivery_dates_updated', 'shop_settings', $settings->id, $policy->toArray(), $actor);
        });

        return $policy;
    }
}
