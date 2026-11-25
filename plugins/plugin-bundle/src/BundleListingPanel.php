<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;

/**
 * 管理画面の商品の画面の「セット商品」の欄（ADR 0050 の口）: セットの中身（品番と 1 セットあたりの数）。
 * 欄の形は核の型（文の項目）だけで、描くのは核の部品。書くのは BundleComponents（連鎖のロック → 行のロック → イベント）。
 * 上の案内に、今の中身と「いくつ作れるか」を出す（在庫はセットそのものではなく構成の品から決まる）
 */
final class BundleListingPanel implements ListingPanelHandler
{
    public function fields(): array
    {
        return [
            new SettingSpec(key: 'parts', type: SettingType::Text, label: 'セットの中身（品番と 1 セットあたりの数）',
                help: '「'.BundleParts::FORMAT.'」の形で、コンマで区切って書きます（例: '.BundleParts::EXAMPLE.'）。'
                    .'空にして保存すると、セットをやめます。品番を付けていない品は、下の案内に出る種類の ID でも書けます。',
                pattern: BundleParts::PATTERN, max: BundleParts::MAX),
        ];
    }

    public function note(Listing $listing): string
    {
        $parts = BundleStock::parts($listing->id);

        if ($parts === []) {
            return 'この商品はまだセットではありません。構成の品の品番を書くと、セットになります（セットの在庫は持たず、'
                .'売れるたびに構成の品の在庫から引きます）。セットの中にセットは入れられません。';
        }

        $sets = min(array_map(static fn (array $part): int => $part['sets'], $parts));
        $words = mb_substr(BundleParts::words($listing->id), 0, 120);
        $note = "セットの中身: {$words}。".($sets > 0
            ? "今の在庫で {$sets} セット販売できます。"
            : '構成の品の在庫が足りないため、今はショップに表示されていません（構成の品を入荷すると、また表示されます）。')
            .'売れるたびに引くのは構成の品の在庫で、この商品の在庫は使いません。';

        // 案内は核の上限（300 文字）まで。品が多い店でも欄が壊れないように、ここで切る
        return mb_substr($note, 0, self::NOTE_MAX);
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function read(Listing $listing): array
    {
        return ['parts' => BundleParts::field($listing->id)];
    }

    /**
     * @param  array<string, mixed>  $values  核が形（文字・長さ・形の決まり）を確かめた後の値
     */
    public function write(Listing $listing, array $values, string $actor): void
    {
        app(BundleComponents::class)->save($listing, is_string($values['parts'] ?? null) ? $values['parts'] : null, $actor);
    }
}
