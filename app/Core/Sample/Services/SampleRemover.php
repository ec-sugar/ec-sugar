<?php

declare(strict_types=1);

namespace App\Core\Sample\Services;

use App\Core\Catalog\Models\Category;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Listing\Services\ListingService;
use App\Core\Media\Models\ShopMedia;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Sample\Models\SampleMark;
use App\Core\Section\SectionValues;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 入れたときのサンプルをまとめて消す（ShopSamples から分けた）。管理画面のホームと `shop:sample --remove` から。
 *
 * - 消すのは印の付いた物だけ、全部を 1 つのトランザクションで（途中で落ちたら何も消えない）
 * - 注文に使われた商品と、お店が書き換えた物・使っている物は残し、印を「残した（kept）」で閉じる（お店の物になる）
 * - テーマの中身は空のトップに戻して公開し、戻した後の改訂を印に残す（cleared）。お店がその後に触っていなければ、別のセットの中身を入れてよい印になる
 * - テーマの切り替えは戻さない（お店の見た目は変えない）。送料は入れたときに印を閉じてあるので残る
 */
final class SampleRemover
{
    public function __construct(
        private readonly EventStore $events,
        private readonly ListingService $listings,
        private readonly ListingImageService $images,
        private readonly CategoryService $categories,
        private readonly ShopMediaService $media,
        private readonly ShopSettingsService $settings,
        private readonly ThemeContentService $content,
        private readonly ThemeRegistry $themes,
    ) {}

    /**
     * @return array{removed: int, kept: list<array{type: string, title: string|null, reason: string}>}
     */
    public function remove(string $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $this->events->lock();
            $marks = SampleMark::query()->active()->orderBy('created_at')->orderBy('id')->lockForUpdate()->get()->groupBy('subject_type');

            if ($marks->isEmpty()) {
                return ['removed' => 0, 'kept' => []];
            }

            $removed = 0;
            $kept = [];
            $close = function (SampleMark $mark, ?string $keptReason, ?string $title = null) use (&$removed, &$kept): void {
                $mark->forceFill(['ended_at' => CarbonImmutable::now(), 'outcome' => $keptReason === null ? 'removed' : 'kept'])->save();

                if ($keptReason === null) {
                    $removed++;
                } else {
                    $kept[] = ['type' => $mark->subject_type, 'title' => $title, 'reason' => $keptReason];
                }
            };

            // 順番: テーマの中身（写真とカテゴリを指す）→ 商品（写真・カテゴリを持つ）→ 表記 → 写真 → カテゴリ
            foreach ($marks->get('theme_version', []) as $mark) {
                $close($mark, $this->removeThemeContent($mark, $actor));
            }

            foreach ($marks->get('listing', []) as $mark) {
                [$reason, $title] = $this->removeListing($mark, $actor);
                $close($mark, $reason, $title);
            }

            foreach ($marks->get('shop_settings', []) as $mark) {
                $close($mark, $this->removeLegal($mark, $actor));
            }

            foreach ($marks->get('shop_media', []) as $mark) {
                $close($mark, $this->removeMedia($mark, $actor));
            }

            foreach ($marks->get('category', []) as $mark) {
                [$reason, $title] = $this->removeCategory($mark, $actor);
                $close($mark, $reason, $title);
            }

            // 記録には数だけ（商品名などの文は入れない）
            $this->events->append('shop.samples_removed', 'shop_samples', strtolower((string) Str::ulid()), ['removed' => $removed, 'kept' => count($kept)], $actor);

            return ['removed' => $removed, 'kept' => $kept];
        });
    }

    /**
     * テーマの中身を空に戻して公開する。お店が下書きを変えた・公開し直した（改訂が進んだ）なら残す。
     * 戻した後の改訂を印に残す（別のセットを入れるとき、お店がその後に触っていないかを見る）
     *
     * @return string|null 残した理由（消したら null）
     */
    private function removeThemeContent(SampleMark $mark, string $actor): ?string
    {
        $version = ThemeVersion::query()->whereKey($mark->subject_id)->first();

        if ($version === null) {
            return null;
        }

        if ($version->revision !== ($mark->snapshot['revision'] ?? null)) {
            return 'edited';
        }

        $schema = $this->themes->get($version->theme)->schema();
        // トップをテーマの最初の並び（値は空）に戻す。今の形で空を送ると、並びのあるテーマでは区画が全部外れてしまう
        $version = $this->content->savePage($version->theme, $schema, 'home', $version->id, $version->revision,
            SectionValues::defaultPage($schema, ThemeContentService::pageSpec($schema, 'home')), $actor);
        $version = $this->content->publish($version->theme, $schema, $version->id, $version->revision, $actor);
        $mark->snapshot = [...$mark->snapshot, 'cleared' => $version->revision];

        return null;
    }

    /**
     * 商品を消す（写真のファイルも）。注文に使われた・お店が書き換えた（バージョンか写真が違う）なら残す
     *
     * @return array{0: string|null, 1: string|null} [残した理由, 商品名]
     */
    private function removeListing(SampleMark $mark, string $actor): array
    {
        $listing = Listing::query()->whereKey($mark->subject_id)->lockForUpdate()->first();

        if ($listing === null) {
            return [null, null];
        }

        if (TransactionLine::query()->where('listing_id', $listing->id)->exists()) {
            return ['ordered', $listing->title];
        }

        if ($listing->version !== ($mark->snapshot['version'] ?? null) || ShopSamples::imageIds($listing->id) !== ($mark->snapshot['images'] ?? null)) {
            return ['edited', $listing->title];
        }

        foreach (ListingImage::query()->where('listing_id', $listing->id)->orderBy('position')->get() as $image) {
            $this->images->remove($image, $actor);
        }

        $this->listings->delete($listing, $listing->version, $actor);

        return [null, null];
    }

    /**
     * 特商法の表記を空に戻す。お店が書き換えていたら残す
     */
    private function removeLegal(SampleMark $mark, string $actor): ?string
    {
        if (! isset($mark->snapshot['legal'])) {
            return null;
        }

        if (ShopSamples::legalHash($this->settings->legal()) !== $mark->snapshot['legal']) {
            return 'edited';
        }

        $this->settings->setLegal(['items' => []], $actor);

        return null;
    }

    /**
     * ショップの写真を消す。お店が使っている（テーマの中身・商品の説明）なら残す
     */
    private function removeMedia(SampleMark $mark, string $actor): ?string
    {
        $media = ShopMedia::query()->whereKey($mark->subject_id)->first();

        if ($media === null) {
            return null;
        }

        if ($this->media->usages($media->id) !== []) {
            return 'in_use';
        }

        $this->media->remove($media->id, $actor);

        return null;
    }

    /**
     * カテゴリを消す。下にカテゴリがある・お店の商品が入っている・テーマの中身が指しているなら残す
     *
     * @return array{0: string|null, 1: string|null} [残した理由, カテゴリの名前]
     */
    private function removeCategory(SampleMark $mark, string $actor): array
    {
        $category = Category::query()->whereKey($mark->subject_id)->first();

        if ($category === null) {
            return [null, null];
        }

        $inUse = Category::query()->where('parent_id', $category->id)->exists()
            || DB::table('listing_categories')->join('listings', 'listings.id', '=', 'listing_categories.listing_id')
                ->where('listing_categories.category_id', $category->id)->whereNull('listings.deleted_at')->exists()
            || ThemeVersion::query()->get(['draft', 'published'])->contains(static fn (ThemeVersion $version): bool => str_contains((string) json_encode([$version->draft, $version->published]), "\"{$category->id}\""));

        if ($inUse) {
            return ['in_use', $category->name];
        }

        $this->categories->delete($category, $actor);

        return [null, null];
    }
}
