<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle;

use App\Core\Event\EventStore;
use App\Core\Listing\ListingKinds;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Order\Preorders;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\Contracts\StockComponentsProvider;
use EcSugar\PluginBundle\Models\BundleComponent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * セットの中身を決める。状態を変えるのはここだけ（モデルを直に書き換えない）。
 * DB のトランザクションの中で、最初に連鎖のロック（EventStore::lock()）を取り、その後に行のロックを取り、
 * 同じトランザクションでイベント（bundle.set・bundle.cleared）を残す（conventions.md の 3、ADR 0012）。
 * イベントの中身は商品・種類の ID と数だけ（店の人の書いた文・個人情報は入れない。ADR 0010）。
 *
 * **入れ子は保存のときに断る**（読むときに辿らない）。核（StockComponents）はセットの構成を 1 段しか辿らないので、
 * セットの中にセットを許すと、中のセットの構成の品が引かれずに売れてしまう。断るのは次の 3 つ:
 *
 *   1. 自分自身を構成の品にする
 *   2. 構成の品が、別のセットの商品である
 *   3. セットにしようとしている商品が、別のセットの構成の品である
 *
 * 確かめはどれも連鎖のロックを取った後に行う（2 人が同時に保存しても、片方が必ず後になる）。DB の引き金でも同じ決まりを守る
 *
 * **在庫を見ずに売る商品は、セットにさせない**（assertDrawsStock）。核は送らない種類・在庫を使わない種類・予約の商品を、
 * 在庫を押さえずに注文にする。そういう商品をセットにすると、構成の品が引かれずに売れてしまう
 */
final class BundleComponents
{
    public function __construct(private readonly EventStore $events) {}

    /**
     * 商品の画面の欄から保存する。空なら、その商品のセットをやめる（行を消す）
     *
     * @throws ValidationException 形・品番・入れ子・数が合わないとき（欄の名前 parts で返す）
     */
    public function save(Listing $bundle, ?string $value, string $actor): void
    {
        $wanted = BundleParts::parse($value);

        if (count($wanted) > StockComponentsProvider::MAX_COMPONENTS) {
            throw self::refuse('構成の品は '.StockComponentsProvider::MAX_COMPONENTS.' つまでです。');
        }

        DB::transaction(function () use ($bundle, $wanted, $actor): void {
            $this->events->lock();

            $parts = $this->resolved($bundle, $wanted);
            $rows = BundleComponent::query()->where('listing_id', $bundle->id)->lockForUpdate()->get();

            if ($parts === []) {
                if ($rows->isNotEmpty()) {
                    self::forget($bundle->id, $actor, $this->events);
                }

                return;
            }

            self::assertDrawsStock($bundle);
            self::assertNotNested($bundle, $parts);

            // 無くなった品の行を消し、残る品は数をそろえる（同じ中身の送り直しでは記録を増やさない）
            $changed = false;

            foreach ($rows as $row) {
                if (! isset($parts[$row->variant_id])) {
                    $row->delete();
                    $changed = true;
                }
            }

            foreach ($parts as $variantId => [$variant, $quantity]) {
                $row = $rows->firstWhere('variant_id', $variantId);

                if ($row === null) {
                    BundleComponent::query()->create([
                        'listing_id' => $bundle->id,
                        'part_listing_id' => $variant->listing_id,
                        'variant_id' => $variantId,
                        'quantity' => $quantity,
                    ]);
                    $changed = true;
                } elseif ($row->quantity !== $quantity) {
                    $row->fill(['quantity' => $quantity])->save();
                    $changed = true;
                }
            }

            if (! $changed) {
                return;
            }

            $this->events->append('bundle.set', 'bundle', $bundle->id, [
                'listing_id' => $bundle->id,
                'parts' => array_values(array_map(
                    static fn (array $part): array => ['variant_id' => $part[0]->id, 'quantity' => $part[1]],
                    $parts,
                )),
            ], $actor);
        });
    }

    /**
     * セットをやめる（App の画面の操作。構成の品が無ければ管理画面では 404）
     */
    public function clear(string $listingId, string $actor): void
    {
        DB::transaction(function () use ($listingId, $actor): void {
            $this->events->lock();

            if (BundleComponent::query()->where('listing_id', $listingId)->lockForUpdate()->doesntExist()) {
                throw new AdminRecordNotFound;
            }

            self::forget($listingId, $actor, $this->events);
        });
    }

    /**
     * 構成の品を全部消して記録を残す（連鎖のロックと行のロックを取った後で呼ぶ）
     */
    private static function forget(string $listingId, string $actor, EventStore $events): void
    {
        BundleComponent::query()->where('listing_id', $listingId)->delete();
        $events->append('bundle.cleared', 'bundle', $listingId, ['listing_id' => $listingId], $actor);
    }

    /**
     * 品番（か種類の ID）を、この店のこの出品者の種類に引き当てる（種類の ID => [種類, 1 セットあたりの数]）
     *
     * @param  array<string, int>  $wanted  品番か種類の ID => 1 セットあたりの数
     * @return array<string, array{0: ListingVariant, 1: int}>
     *
     * @throws ValidationException
     */
    private function resolved(Listing $bundle, array $wanted): array
    {
        $parts = [];

        foreach ($wanted as $token => $quantity) {
            $variant = self::variantOf($bundle, (string) $token);

            if ($variant === null) {
                throw self::refuse("品番「{$token}」の品が見つかりません。商品の画面で品番を入れてから、セットの中身に書いてください。");
            }

            if ($variant->listing_id === $bundle->id) {
                throw self::refuse("「{$token}」はこの商品そのものです。セットの中身には、ほかの商品を書いてください。");
            }

            if (isset($parts[$variant->id])) {
                throw self::refuse("「{$token}」と同じ品が 2 回書かれています。");
            }

            $parts[$variant->id] = [$variant, $quantity];
        }

        return $parts;
    }

    /**
     * セットにする商品が、注文のときに在庫を押さえる商品であること。見るのは核が注文と在庫の表示で見ているのと同じ答え:
     *
     *   - 送らない種類（ダウンロード・サービスなど。ListingKinds::digital）: 注文は在庫を押さえずに 1 行にする
     *   - 在庫を使わない種類（日時の予約など。ListingKinds::stockless）: ショップは在庫を見ずに売る
     *   - 発売日の前の予約の商品（Preorders::terms。App「予約販売」を使っている店だけ）: 注文は在庫を押さえず、数は予約の App が数える
     *
     * どれも、セットにしても構成の品が引かれない。セットをやめる（空で保存する）のは、ここを通らないのでいつでもできる。
     * 逆向き（先にセットにして、後から種類や予約を変える）は App からは止められない（README の「気をつけること」）
     *
     * @throws ValidationException
     */
    private static function assertDrawsStock(Listing $bundle): void
    {
        $kinds = app(ListingKinds::class);

        if ($kinds->digital($bundle->kind) || $kinds->stockless($bundle->kind)) {
            throw self::refuse('この商品は在庫を使わない種類（ダウンロード・サービス・日時の予約など）なので、セットにできません。セットにするのは、在庫から発送するふつうの商品にしてください。');
        }

        if (app(Preorders::class)->terms([$bundle->id]) !== []) {
            throw self::refuse('この商品は予約販売の受付中なので、セットにできません（予約は在庫を使わずに受けるため、構成の品の在庫が引かれません）。予約販売をやめるか、発売日になってからセットにしてください。');
        }
    }

    /**
     * セットの中にセットを入れていないこと（連鎖のロックの中で確かめる）
     *
     * @param  array<string, array{0: ListingVariant, 1: int}>  $parts
     *
     * @throws ValidationException
     */
    private static function assertNotNested(Listing $bundle, array $parts): void
    {
        $partListings = array_values(array_unique(array_map(static fn (array $part): string => $part[0]->listing_id, $parts)));

        $nested = BundleComponent::query()->whereIn('listing_id', $partListings)->first();

        if ($nested !== null) {
            throw self::refuse('セットの中にセットは入れられません（構成の品に、別のセットの商品があります）。');
        }

        if (BundleComponent::query()->where('part_listing_id', $bundle->id)->exists()) {
            throw self::refuse('この商品は、ほかのセットの構成に入っています。セットの中にセットは入れられません。');
        }
    }

    /**
     * 品番（先に見る）か種類の ID で、この店のこの出品者の、消していない商品の種類をさがす。
     * 消した種類・消した商品は見つからない（消した品はセットに入れられない）
     */
    private static function variantOf(Listing $bundle, string $token): ?ListingVariant
    {
        $variant = self::variants($bundle)->where('listing_variants.sku', $token)->first();

        if ($variant instanceof ListingVariant) {
            return $variant;
        }

        if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', strtolower($token)) !== 1) {
            return null;
        }

        $variant = self::variants($bundle)->whereKey(strtolower($token))->first();

        return $variant instanceof ListingVariant ? $variant : null;
    }

    /**
     * この店のこの出品者の、消していない商品の種類（消した種類は SoftDeletes が外す）
     *
     * @return Builder<ListingVariant>
     */
    private static function variants(Listing $bundle): Builder
    {
        return ListingVariant::query()
            ->join('listings as part', 'part.id', '=', 'listing_variants.listing_id')
            ->whereNull('part.deleted_at')
            // 出品者が違う商品は、同じ注文に入らない（核が断る）ので、セットにも入れない
            ->where('listing_variants.seller_id', $bundle->seller_id)
            ->select('listing_variants.*');
    }

    private static function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['parts' => $message]);
    }
}
