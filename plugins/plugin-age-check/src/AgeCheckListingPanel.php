<?php

declare(strict_types=1);

namespace EcSugar\PluginAgeCheck;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AgeCheck\AgeRule;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use EcSugar\PluginAgeCheck\Models\AgeCheckListing;

/**
 * 管理画面の商品の画面の「年齢確認」の欄（ADR 0050 の口）: 年齢の確かめが要る・年齢（20 歳〔お酒・たばこ〕・18 歳・15 歳。既定は 20 歳）。
 * 書くのは AgeCheckListings（連鎖のロック → 行のロック → イベント）
 */
final class AgeCheckListingPanel implements ListingPanelHandler
{
    /**
     * @return list<SettingSpec>
     */
    public function fields(): array
    {
        return [
            new SettingSpec(key: 'required', type: SettingType::Toggle, label: '年齢確認が必要',
                help: 'オンにすると、商品の画面に「20 歳未満の方はお求めいただけません」と表示し、注文の時に生年月日と「20 歳以上です」の同意を必ず確認します（確認できなければ注文できません）。お酒・たばこなどに使います。'),
            new SettingSpec(key: 'age', type: SettingType::Choice, label: '年齢',
                help: 'この年齢に満たない方は注文できません。生年月日から、日本時間の今日時点の満年齢で判定します。',
                options: ['age20' => '20 歳以上（お酒・たばこ）', 'age18' => '18 歳以上', 'age15' => '15 歳以上'], default: 'age'.AgeRule::DEFAULT_AGE),
        ];
    }

    public function note(Listing $listing): ?string
    {
        $age = AgeCheckListing::query()->whereKey($listing->id)->value('age');

        return $age === null ? null : "この商品は {$age} 歳以上の方だけが注文できます。年齢を確認した注文の画面と納品書・配送の CSV に「年齢確認済み（{$age} 歳以上）」が付きます。";
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function read(Listing $listing): array
    {
        $age = AgeCheckListing::query()->whereKey($listing->id)->value('age');

        return ['required' => $age !== null, 'age' => 'age'.($age ?? AgeRule::DEFAULT_AGE)];
    }

    /**
     * @param  array<string, mixed>  $values  核が形を確かめ、全部の欄をそろえた値
     */
    public function write(Listing $listing, array $values, string $actor): void
    {
        $age = is_string($values['age'] ?? null) && preg_match('/\Aage(\d{2})\z/', $values['age'], $m) === 1 ? (int) $m[1] : null;
        app(AgeCheckListings::class)->save($listing, ($values['required'] ?? false) === true, $age, $actor);
    }
}
