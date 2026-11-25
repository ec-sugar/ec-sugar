<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use App\Core\Plugin\Preorder\PreorderTerms;
use EcSugar\PluginPreorder\Models\PreorderListing;

/**
 * 管理画面の商品の画面の「予約販売」の欄（ADR 0050 の口）: 予約販売にする・発売日・予約の受付の終わり・予約の数の上限。
 * 上の案内に、今の予約の数と発売日を出す。書くのは PreorderListings（連鎖のロック → 行のロック → イベント）
 */
final class PreorderListingPanel implements ListingPanelHandler
{
    private const string DATE = '[0-9]{4}-[0-9]{2}-[0-9]{2}';

    /**
     * @return list<SettingSpec>
     */
    public function fields(): array
    {
        return [
            new SettingSpec(key: 'enabled', type: SettingType::Toggle, label: '予約販売にする',
                help: 'オンにすると、発売日の前は在庫がなくても予約を受け付けます（ショップに「予約商品（◯月◯日ごろ発送）」と出て、ボタンは「予約する」になります）。お支払いはご注文の時です。発売日からは通常の在庫での販売に戻ります。'),
            new SettingSpec(key: 'release_date', type: SettingType::Text, label: '発売日（発送を始める日）',
                help: '「2026-11-20」の形で入力します。予約の商品を含む注文は、この日まで発送できません。変更すると、まだ発送していない予約の注文の発送日も新しい日に変わり、お支払い済みのお客さんへメールでお知らせします（発売日を過ぎても、未発送の注文があれば延期できます）。発売日を過ぎて発送も終わった後に次の予約を受け付けるときは、App の「予約販売」の画面で「次の予約を始める」を押してから入力します。', pattern: self::DATE, max: 10),
            new SettingSpec(key: 'ends_on', type: SettingType::Text, label: '予約の締切日（任意）',
                help: '「2026-11-15」の形で入力すると、その日まで予約を受け付けます。空なら発売日の前日まで受け付けます。', pattern: self::DATE, max: 10),
            new SettingSpec(key: 'capacity', type: SettingType::Text, label: '予約の数の上限（任意）',
                help: '入荷する数に合わせて入力します（在庫とは別に数えます）。空なら上限なしで受け付けます。', pattern: '[1-9][0-9]{0,5}', max: 6),
        ];
    }

    public function note(Listing $listing): ?string
    {
        $row = PreorderListing::query()->where('listing_id', $listing->id)->first();

        if ($row === null || ! $row->active) {
            return null;
        }

        $taken = PreorderCounts::taken([$listing->id])[$listing->id] ?? 0;
        $date = PreorderTerms::dateLabel($row->releaseDate());

        if ($row->releaseDate() <= PreorderTerms::today()) {
            $pending = PreorderListings::pendingOrders($row);

            return "発売日（{$date}）になりました。今は通常の在庫で販売しています。今回の予約は {$taken} 点".($pending > 0
                ? "で、まだ発送していない予約の注文が {$pending} 件あります（発売日を延期すると、その注文の発送日も新しい日に変わります）。"
                : ($taken > 0 ? 'です。次の予約は、App の「予約販売」の画面の「次の予約を始める」から始められます。' : 'です。新しい発売日を入力すると、また予約を受け付けます。'));
        }

        return "予約 {$taken} 点".($row->capacity === null ? '' : "（上限 {$row->capacity} 点）")."・発売日 {$date}。";
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function read(Listing $listing): array
    {
        $row = PreorderListing::query()->where('listing_id', $listing->id)->first();

        return [
            'enabled' => $row->active ?? false,
            'release_date' => $row?->releaseDate(),
            'ends_on' => $row?->endsOn(),
            'capacity' => $row?->capacity === null ? null : (string) $row->capacity,
        ];
    }

    /**
     * @param  array<string, mixed>  $values  核が形を確かめ、全部の欄をそろえた値
     */
    public function write(Listing $listing, array $values, string $actor): void
    {
        app(PreorderListings::class)->save($listing, [
            'enabled' => ($values['enabled'] ?? false) === true,
            'release_date' => is_string($values['release_date'] ?? null) ? $values['release_date'] : null,
            'ends_on' => is_string($values['ends_on'] ?? null) ? $values['ends_on'] : null,
            'capacity' => is_string($values['capacity'] ?? null) ? $values['capacity'] : null,
        ], $actor);
    }
}
