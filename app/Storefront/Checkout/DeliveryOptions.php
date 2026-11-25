<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\DeliverySlot;
use App\Core\Shop\DeliveryPolicy;
use Carbon\CarbonImmutable;

/**
 * 注文の入力の「お届け日時」の欄に出す選択肢（テーマ API の一部。ADR 0059）。核の部品 <x-storefront::delivery-fields> が出す。
 * 選択肢はサーバーが今の時刻と店の決まりで作る（JavaScript は使わない）。送られた値は確定までに何度か照らし直す
 */
final readonly class DeliveryOptions
{
    /**
     * @param  list<array{value: string, label: string}>  $dates  選べるお届け日（早い順）。日付の指定を受けない店は空
     * @param  list<array{value: string, label: string}>  $slots  選べる時間帯。受けない店は空
     */
    public function __construct(
        public array $dates,
        public array $slots,
    ) {}

    /**
     * 店が日時の指定を受けていなければ null（欄を出さない）
     */
    public static function of(DeliveryPolicy $policy, ?CarbonImmutable $now = null): ?self
    {
        if (! $policy->offered()) {
            return null;
        }

        $dates = $policy->selectableDates($now ?? CarbonImmutable::now());

        return new self(
            array_map(static fn (string $d): array => ['value' => $d, 'label' => DeliveryChoice::dateLabel($d)], $dates),
            array_map(static fn (DeliverySlot $s): array => ['value' => $s->value, 'label' => $s->label()], $policy->slots),
        );
    }

    /**
     * 選べる最初の日の見せ方（「最短 2026年11月7日（土）」の案内に）。日付を受けない店は null
     */
    public function earliestLabel(): ?string
    {
        return $this->dates[0]['label'] ?? null;
    }
}
