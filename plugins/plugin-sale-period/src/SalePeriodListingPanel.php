<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use Carbon\CarbonImmutable;
use EcSugar\PluginSalePeriod\Models\SaleWindow;

/**
 * 管理画面の商品の画面の「公開の予約と販売期間」の欄（ADR 0050 の口）: 出す日時と終わる日時。
 * 欄の形は核の型（文の項目）だけで、描くのは核の部品。書くのは SaleWindows（連鎖のロック → 行のロック → イベント）。
 * 上の案内に、今その商品がどの状態か（まだ出していない・販売中・終わり）を日本時間で出す
 */
final class SalePeriodListingPanel implements ListingPanelHandler
{
    /**
     * @return list<SettingSpec>
     */
    public function fields(): array
    {
        return [
            new SettingSpec(key: 'starts_at', type: SettingType::Text, label: 'ショップに公開する日時（任意）',
                help: '「'.SalePeriodTime::FORMAT.'」の形（日本時間）で入れます。その日時までは、公開中の商品でもショップの一覧・検索・商品の画面に表示されません。空なら、公開にした時から表示されます。',
                pattern: SalePeriodTime::PATTERN, max: 16),
            new SettingSpec(key: 'ends_at', type: SettingType::Text, label: '販売を終える日時（任意）',
                help: '「'.SalePeriodTime::FORMAT.'」の形（日本時間）で入れます。その日時になると、商品の画面は「売り切れ」になり、カートにも入れられなくなります（商品そのものはショップに残ります）。空なら、販売を終える日時は決めません。',
                pattern: SalePeriodTime::PATTERN, max: 16),
        ];
    }

    public function note(Listing $listing): ?string
    {
        $row = SaleWindow::query()->where('listing_id', $listing->id)->first();

        if ($row === null) {
            return null;
        }

        $now = CarbonImmutable::now();
        $starts = SalePeriodTime::label($row->starts_at);
        $ends = SalePeriodTime::label($row->ends_at);

        if ($row->starts_at !== null && $row->starts_at->greaterThan($now)) {
            return "今はショップに表示されていません（{$starts} から表示されます".($ends === null ? '' : "・{$ends} に販売を終えます").'）。';
        }

        if ($row->ends_at !== null && $row->ends_at->lessThanOrEqualTo($now)) {
            return "販売期間を終えました（{$ends}）。商品の画面は売り切れになり、カートに入れられません。販売を再開するときは、「販売を終える日時」を先の日時にするか、空にしてください。";
        }

        return '販売中です。'.($ends === null ? '公開した日時は '.$starts.' でした。' : "{$ends} に販売を終えます。");
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function read(Listing $listing): array
    {
        $row = SaleWindow::query()->where('listing_id', $listing->id)->first();

        return [
            'starts_at' => SalePeriodTime::field($row?->starts_at),
            'ends_at' => SalePeriodTime::field($row?->ends_at),
        ];
    }

    /**
     * @param  array<string, mixed>  $values  核が形（文字・長さ・形の決まり）を確かめた後の値
     */
    public function write(Listing $listing, array $values, string $actor): void
    {
        app(SaleWindows::class)->save(
            $listing,
            is_string($values['starts_at'] ?? null) ? $values['starts_at'] : null,
            is_string($values['ends_at'] ?? null) ? $values['ends_at'] : null,
            $actor,
        );
    }
}
