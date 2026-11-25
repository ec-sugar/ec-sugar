<?php

declare(strict_types=1);

namespace App\Core\Listing;

use App\Core\Listing\Enums\OfferSide;
use App\Core\Listing\Enums\Pricing;
use InvalidArgumentException;

/**
 * 出品物の「種類」のレジストリ。拡張点「取引の種類」の Listing 側（CONCEPT 6.3）。
 *
 * 核が登録するのは product だけ。買取受付（buyback_offer）、仕事（service / job_request）、
 * 定期（subscription_plan）はそれぞれのプラグインが登録する。
 * 種類は列挙型ではなく文字列で持つ（閉じた列挙にするとプラグインが足せない）。
 */
final class ListingKinds
{
    /** @var array<string, ListingKindDefinition> */
    private array $kinds = [];

    public function register(ListingKindDefinition $definition): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', $definition->kind) !== 1) {
            throw new InvalidArgumentException("出品物の種類名が不正です: {$definition->kind}");
        }

        if (isset($this->kinds[$definition->kind])) {
            throw new InvalidArgumentException("出品物の種類が二重に登録されています: {$definition->kind}");
        }

        $this->kinds[$definition->kind] = $definition;
    }

    public function has(string $kind): bool
    {
        return isset($this->kinds[$kind]);
    }

    public function get(string $kind): ListingKindDefinition
    {
        return $this->kinds[$kind] ?? throw new InvalidArgumentException("未登録の出品物の種類です: {$kind}");
    }

    /**
     * カートに入れさせない種類の断りの文（入れてよい・知らない種類は null）
     */
    public function cartRefusal(string $kind): ?string
    {
        return $this->kinds[$kind]->cartRefusal ?? null;
    }

    /**
     * 在庫の数を使わない種類か（予約の品など。知らない種類は false）
     */
    public function stockless(string $kind): bool
    {
        return $this->kinds[$kind]->stockless ?? false;
    }

    /**
     * 送らない品の種類か（ダウンロードなど。送料・お届け先・在庫を使わない。知らない種類は false）。役務の種類も送らない品に入る
     */
    public function digital(string $kind): bool
    {
        $definition = $this->kinds[$kind] ?? null;

        return $definition !== null && ($definition->digital || $definition->inPerson);
    }

    /**
     * 役務の種類か（受渡は in_person。ほかの種類の品と同じ注文に入れない。知らない種類は false）
     */
    public function inPerson(string $kind): bool
    {
        return $this->kinds[$kind]->inPerson ?? false;
    }

    /**
     * その種類の商品のうち、今売れる商品の ID（種類が口〔sellable〕を持たなければ全部。知らない種類は全部）
     *
     * @param  list<string>  $listingIds
     * @return list<string>
     */
    public function sellable(string $kind, array $listingIds): array
    {
        $check = $this->kinds[$kind]->sellable ?? null;

        if ($check === null || $listingIds === []) {
            return $listingIds;
        }

        return array_values(array_intersect($listingIds, $check($listingIds)));
    }

    /**
     * その種類を足した App の名前（核の種類・知らない種類は null）
     */
    public function plugin(string $kind): ?string
    {
        return $this->kinds[$kind]->plugin ?? null;
    }

    /**
     * 在庫の数を使わない種類の名前（ホームの在庫切れの数から外す）
     *
     * @return list<string>
     */
    public function stocklessNames(): array
    {
        return array_keys(array_filter($this->kinds, static fn (ListingKindDefinition $d): bool => $d->stockless));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->kinds);
    }

    public static function product(): ListingKindDefinition
    {
        return new ListingKindDefinition(
            kind: 'product',
            offerSide: OfferSide::Sell,
            pricing: Pricing::Fixed,
            label: '商品',
        );
    }
}
