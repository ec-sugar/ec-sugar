<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;

/**
 * 管理画面の商品の画面の「会員ランク」の欄（ADR 0050 の口。ADR 0079）: この商品を見せる相手（誰でも・会員だけ・ランク以上）。
 * 書くのは RankListings（連鎖のロック → 行の錠 → イベント）
 */
final class RankListingPanel implements ListingPanelHandler
{
    /**
     * @return list<SettingSpec>
     */
    public function fields(): array
    {
        $options = [RankListings::EVERYONE => 'すべての人に表示', RankListings::MEMBERS => '会員だけに表示'];

        foreach (app(RankSettings::class)->ranks() as $rank) {
            $options['rank'.$rank->position] = mb_substr($rank->name, 0, 20).'以上の会員だけに表示';
        }

        return [
            new SettingSpec(key: 'visibility', type: SettingType::Choice, label: 'この商品を表示する相手',
                help: '会員だけ・特定のランク以上だけに表示すると、それ以外の方には、ショップの一覧・検索・商品の画面・カートに表示されず、URL を開いても見つかりません。商品フィードとサイトマップにも載せません。',
                options: $options, default: RankListings::EVERYONE),
        ];
    }

    public function note(Listing $listing): ?string
    {
        $visibility = app(RankListings::class)->visibility($listing->id);

        if ($visibility === RankListings::EVERYONE) {
            return null;
        }

        $ranks = app(RankSettings::class)->ranks();
        $position = $visibility === RankListings::MEMBERS ? 0 : (int) substr($visibility, 4);

        if ($position > 0 && ! isset($ranks[$position])) {
            return '今はこのランクを使っていないため、この商品はどなたにも表示されません。';
        }

        return $position === 0 ? 'ログインした会員だけに表示しています。' : $ranks[$position]->name.'以上の会員だけに表示しています。';
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function read(Listing $listing): array
    {
        $visibility = app(RankListings::class)->visibility($listing->id);

        // 今は使っていないランクの決まりも、そのまま見せる（選択肢に無い値は欄が既定に戻すので、案内の文で伝える）
        return ['visibility' => $visibility];
    }

    /**
     * @param  array<string, mixed>  $values  核が形を確かめ、全部の欄をそろえた値
     */
    public function write(Listing $listing, array $values, string $actor): void
    {
        app(RankListings::class)->save($listing, is_string($values['visibility'] ?? null) ? $values['visibility'] : RankListings::EVERYONE, $actor);
    }
}
