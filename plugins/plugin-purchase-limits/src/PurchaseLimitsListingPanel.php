<?php

declare(strict_types=1);

namespace EcSugar\PluginPurchaseLimits;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use EcSugar\PluginPurchaseLimits\Models\ListingLimit;
use Illuminate\Validation\ValidationException;

/**
 * 管理画面の商品の画面の「購入の制限」の欄（ADR 0050 の口）: 1 回のご注文の上限（1〜999。空なら決めない）。
 * 欄の形は核の型（文の項目）だけで、描くのは核の部品。書くのは ListingLimits（連鎖のロック → 行のロック → イベント）
 */
final class PurchaseLimitsListingPanel implements ListingPanelHandler
{
    /** 数の形（1〜999。先頭の 0・符号・小数・全角は通さない） */
    public const string PATTERN = '[1-9][0-9]{0,2}';

    /**
     * @return list<SettingSpec>
     */
    public function fields(): array
    {
        return [
            new SettingSpec(key: 'per_order', type: SettingType::Text, label: '1 回のご注文の上限（任意）',
                help: '半角の数字で '.ListingLimits::MIN.'〜'.ListingLimits::MAX.' を入れます。種類（サイズ・色など）が違っても、同じ商品は合わせて数えます。1 回のご注文ごとの決まりなので、ご注文を分ければまた買えます。空なら、上限は設けません。',
                pattern: self::PATTERN, max: 3),
        ];
    }

    public function note(Listing $listing): ?string
    {
        $limit = self::limitOf($listing);

        return $limit === null ? null : "この商品は、1 回のご注文で {$limit} 点までです。";
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function read(Listing $listing): array
    {
        $limit = self::limitOf($listing);

        return ['per_order' => $limit === null ? null : (string) $limit];
    }

    /**
     * @param  array<string, mixed>  $values  核が形（文字・長さ・形の決まり）を確かめた後の値
     */
    public function write(Listing $listing, array $values, string $actor): void
    {
        $value = $values['per_order'] ?? null;

        if ($value === null || $value === '') {
            app(ListingLimits::class)->save($listing, null, $actor);

            return;
        }

        // 核が形を確かめた後でも、ここでもう一度確かめる（数でないものを 0 に読み替えて「上限をやめる」にしない）
        if (! is_string($value) || preg_match('/\A(?:'.self::PATTERN.')\z/', $value) !== 1) {
            throw ValidationException::withMessages(['per_order' => ListingLimits::rangeMessage()]);
        }

        app(ListingLimits::class)->save($listing, (int) $value, $actor);
    }

    private static function limitOf(Listing $listing): ?int
    {
        return ListingLimit::query()->whereKey($listing->id)->first()?->per_order;
    }
}
