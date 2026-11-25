<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\DeliverySlot;
use App\Core\Shop\DeliveryPolicy;
use App\Core\Shop\Services\DeliveryDates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 店の設定「お届け日時」（EnterTenant の後。ADR 0059）。読むのは閲覧から、変えるのは運用から（EnterTenant の既定）。
 * 応答には決まりと、今注文したら選べる日の見本（最初と最後の日）を返す（決まりの効き方を画面で確かめる）
 */
final class DeliveryDateSettingsController
{
    public function __construct(private readonly DeliveryDates $dates) {}

    public function show(): JsonResponse
    {
        return response()->json($this->present($this->dates->policy()));
    }

    public function update(Request $request): JsonResponse
    {
        $policy = $this->dates->update($request->only(['dates', 'slots', 'ship_days', 'transit_days', 'span_days', 'cutoff', 'closed_weekdays', 'closed_dates']), TenantActor::of($request));

        return response()->json($this->present($policy));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DeliveryPolicy $policy): array
    {
        $dates = $this->dates->selectableDates();

        return [
            'delivery' => $policy->toArray(),
            'slot_options' => array_map(static fn (DeliverySlot $s): array => ['value' => $s->value, 'label' => $s->label()], DeliverySlot::cases()),
            // 今注文したら選べる日（決まりの効き方の見本。日付を受けない店は null）
            'preview' => $dates === [] ? null : ['first' => DeliveryChoice::dateLabel($dates[0]), 'last' => DeliveryChoice::dateLabel($dates[array_key_last($dates)])],
        ];
    }
}
