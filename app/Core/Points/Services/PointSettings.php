<?php

declare(strict_types=1);

namespace App\Core\Points\Services;

use App\Core\Event\EventStore;
use App\Core\Plugin\MemberBenefits;
use App\Core\Shop\Models\ShopSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 店のポイントの設定（ADR 0068 の 7。設定 › ポイント）: 使う／使わない（既定は使う）と率（万分率。既定 100 = 1%、0〜2000 = 0〜20%）。
 * 1 ポイントは 1 円（決まり）。値は店の設定の行（shop_settings）に置き、変えるのはここだけ。行の無い店は既定の値。
 * 使わないにすると、新しい注文では付けない・使えない。残っている残高は消さない（期限で消える）。
 * 書き込みは EventStore::lock() → 設定の行のロックの順（ADR 0012）
 */
final class PointSettings
{
    public const int DEFAULT_RATE_BP = 100;

    public const int MAX_RATE_BP = 2000;

    public function __construct(private readonly EventStore $events) {}

    public function enabled(): bool
    {
        $value = ShopSettings::query()->value('points_enabled');

        return $value === null ? true : (bool) $value;
    }

    /**
     * 付ける率（万分率。1% は 100）
     */
    public function rateBp(): int
    {
        $value = ShopSettings::query()->value('points_rate_bp');

        return $value === null ? self::DEFAULT_RATE_BP : max(0, min(self::MAX_RATE_BP, (int) $value));
    }

    /**
     * 今の注文で付ける率（使わない店は 0）
     */
    public function activeRateBp(): int
    {
        return $this->enabled() ? $this->rateBp() : 0;
    }

    /**
     * その会員の注文で付ける率（使わない店は 0）。店の率に、会員の特典の上乗せ（App「会員ランク」のランクごとの率。ADR 0079）を足し、
     * 合わせて MAX_RATE_BP（20%）までに抑える（注文に写す率 order_points.rate_bp の DB の決まりと同じ上限）
     */
    public function rateBpFor(?string $customerAccountId): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        return min(self::MAX_RATE_BP, $this->rateBp() + app(MemberBenefits::class)->pointBonusBp($customerAccountId));
    }

    /**
     * @return array{enabled: bool, rate_bp: int}
     */
    public function show(): array
    {
        return ['enabled' => $this->enabled(), 'rate_bp' => $this->rateBp()];
    }

    /**
     * 変える。形が違えば ValidationException。率は 0〜20% を 0.1% きざみ（万分率で 10 の倍数）
     *
     * @param  array<string, mixed>  $input  {enabled, rate_bp}
     * @return array{enabled: bool, rate_bp: int}
     */
    public function update(array $input, ?string $actor = null): array
    {
        $data = Validator::make($input, [
            'enabled' => ['required', 'boolean'],
            'rate_bp' => ['required', 'integer', 'min:0', 'max:'.self::MAX_RATE_BP, 'multiple_of:10'],
        ], ['rate_bp.multiple_of' => '率は 0.1% 単位で入力してください。'], ['enabled' => 'ポイントを使うか', 'rate_bp' => '付与率'])->validate();

        $enabled = (bool) $data['enabled'];
        $rate = (int) $data['rate_bp'];

        DB::transaction(function () use ($enabled, $rate, $actor): void {
            $this->events->lock();
            $settings = ShopSettings::query()->lockForUpdate()->first() ?? ShopSettings::create([]);
            $current = ['enabled' => (bool) ($settings->points_enabled ?? true), 'rate_bp' => (int) ($settings->points_rate_bp ?? self::DEFAULT_RATE_BP)];

            if ($current === ['enabled' => $enabled, 'rate_bp' => $rate]) {
                return;
            }

            ShopSettings::query()->whereKey($settings->id)->update(['points_enabled' => $enabled, 'points_rate_bp' => $rate, 'updated_at' => now()]);
            $this->events->append('shop.points_updated', 'shop_settings', $settings->id, ['enabled' => $enabled, 'rate_bp' => $rate], $actor);
        });

        return $this->show();
    }
}
