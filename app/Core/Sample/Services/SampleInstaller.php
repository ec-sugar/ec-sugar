<?php

declare(strict_types=1);

namespace App\Core\Sample\Services;

use App\Core\Catalog\Models\Category;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Event\EventStore;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\AddonService;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\VariantService;
use App\Core\Media\Services\MediaQuota;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Sample\Models\SampleMark;
use App\Core\Sample\SampleSet;
use App\Core\Sample\SampleSets;
use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Tax\TaxClass;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use LogicException;
use RuntimeException;

/**
 * 入れたときのサンプルの中身を 1 つずつ入れる（ShopSamples から分けた。断るかどうか・写真をどこから読むかは ShopSamples が決める）。
 *
 * - 書き込みは核のサービスを通す（商品・写真・種類・オプション・在庫・カテゴリ・テーマの中身・店の設定）。
 *   どれも EventStore::lock() を先に取り、イベントを同じトランザクションで残す。印（sample_marks）も同じトランザクションで書く
 * - 入れるのは 1 つずつ（商品 1 つ・カテゴリ 1 つ…）のトランザクション。途中で落ちても、流し直せば残りだけを入れる。印のある物は二度入れない
 * - お店がもう持っている物（同じ slug のカテゴリ・テーマの中身・特商法の表記・決めた送料）には触らない
 * - 写真のアップロードの 1 分の回数（MediaQuota）に触れそうなら、書き込みの錠の外で待ってから上げる
 * - 写真の出どころ（撮った人・ページ）は印の snapshot（credits・credit）と、商品の説明の終わりの一行に残す
 */
final class SampleInstaller
{
    /** 写真の名前の形（こちらが決めた英小文字・数字・ハイフンだけ） */
    private const string PHOTO_NAME = '/\A[a-z0-9-]+\z/';

    /** 読む写真の拡張子（先にある物から） */
    private const array EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png'];

    /** この回の写真のフォルダ・写真が欠けたら止めるか・欠けた写真（名前 => 理由）・知らせの受け手 */
    private string $directory = '';

    private bool $strict = true;

    /** @var array<string, string> */
    private array $missing = [];

    /** @var (Closure(string, bool): void)|null */
    private ?Closure $notice = null;

    /** 記録の actor（入れたときのサンプルは cli:shop-sample、デモの店は cli:demo-shop） */
    private string $actor = ShopSamples::ACTOR;

    public function __construct(
        private readonly EventStore $events,
        private readonly ListingService $listings,
        private readonly ListingImageService $images,
        private readonly VariantService $variants,
        private readonly AddonService $addons,
        private readonly InventoryOperations $inventory,
        private readonly CategoryService $categories,
        private readonly ShopMediaService $media,
        private readonly ShopSettingsService $settings,
        private readonly ThemeContentService $content,
        private readonly ThemeRegistry $themes,
        private readonly MediaQuota $quota,
    ) {}

    /**
     * セットの中身を入れる（店の文脈の中で）
     *
     * @param  string  $directory  写真のフォルダ（`<名前>.webp|jpg|jpeg|png`）
     * @param  bool  $strict  写真が欠けていたら止めるか（配る物の中の絵）
     * @param  array<string, string>  $missing  もう分かっている欠けた写真（取れなかった写真の名前 => 理由）
     * @param  (Closure(string, bool): void)|null  $notice  途中の知らせ（文と注意か。待つ）
     * @param  string|null  $actor  記録の actor（無ければ cli:shop-sample）
     * @return array{added: int, missing: array<string, string>} この回に入れた物の数・入れられなかった写真
     */
    public function run(SampleSet $set, string $directory, bool $strict, array $missing = [], ?Closure $notice = null, ?string $actor = null): array
    {
        $this->actor = $actor ?? ShopSamples::ACTOR;
        $this->directory = $directory;
        $this->strict = $strict;
        $this->missing = $missing;
        $this->notice = $notice;

        return ['added' => $this->fill($set), 'missing' => $this->missing];
    }

    /**
     * セットの中身を 1 つずつ入れる
     *
     * @return int この回に入れた物の数
     */
    private function fill(SampleSet $set): int
    {
        $added = 0;
        $category = [];

        foreach ($set->categories() as $slug => $name) {
            $added += $this->once(SampleSets::key($set, 'category', $slug), fn (): array => $this->addCategory($slug, $name));
            $category[$slug] = $this->categoryId($slug);
        }

        foreach ($set->listings() as $key => $spec) {
            $markKey = SampleSets::key($set, 'listing', $key);

            if (! SampleMark::query()->where('key', $markKey)->exists()) {
                $this->pace(count($spec['photos']) + count($spec['gallery'] ?? []) + count(array_filter($spec['blocks'], static fn (array $block): bool => ($block['type'] ?? null) === 'image')));
            }

            $added += $this->once($markKey, fn (): array => $this->addListing($set, $key, $spec, $category[$spec['category']] ?? null));
        }

        $themeKey = SampleSets::key($set, 'theme', $set->theme());

        if (! SampleMark::query()->where('key', $themeKey)->exists()) {
            $this->pace(count($set->media()));
        }

        $added += $this->once($themeKey, fn (): array => $this->addThemeContent($set, $category));
        $added += $this->once(SampleSets::key($set, 'legal'), fn (): array => $this->addLegal($set));
        $added += $this->once(SampleSets::key($set, 'shipping'), fn (): array => $this->addShipping($set));

        return $added;
    }

    /**
     * 印がまだ無ければ、1 つのトランザクションで入れて印を書く。入れなかった（お店がもう持っていた）ときも印を閉じて残し、二度と入れない
     *
     * @param  Closure(): list<array{key?: string, type: string, id: string|null, snapshot: array<string, mixed>, kept?: bool}>  $work  付ける印（空なら「入れなかった」）
     * @return int 入れたら 1
     */
    private function once(string $key, Closure $work): int
    {
        return DB::transaction(function () use ($key, $work): int {
            $this->events->lock();

            if (SampleMark::query()->where('key', $key)->exists()) {
                return 0;
            }

            $marks = $work();

            if ($marks === []) {
                // お店がもう持っていた。流し直しで入れ直さないよう、閉じた印だけ残す
                SampleMark::create(['key' => $key, 'subject_type' => self::typeOf($key), 'subject_id' => null, 'snapshot' => ['skipped' => true], 'ended_at' => CarbonImmutable::now(), 'outcome' => 'kept']);

                return 0;
            }

            foreach ($marks as $mark) {
                $kept = $mark['kept'] ?? false;
                SampleMark::create([
                    'key' => $mark['key'] ?? $key,
                    'subject_type' => $mark['type'],
                    'subject_id' => $mark['id'],
                    'snapshot' => $mark['snapshot'],
                    'ended_at' => $kept ? CarbonImmutable::now() : null,
                    'outcome' => $kept ? 'kept' : null,
                ]);
            }

            return 1;
        });
    }

    /**
     * 印の名前から、印の付く物の種類
     */
    private static function typeOf(string $key): string
    {
        return match (SampleSets::kindOf($key)) {
            'category' => 'category',
            'listing' => 'listing',
            'media' => 'shop_media',
            'theme' => 'theme_version',
            default => 'shop_settings',
        };
    }

    /**
     * @return list<array{type: string, id: string|null, snapshot: array<string, mixed>}>
     */
    private function addCategory(string $slug, string $name): array
    {
        if (Category::query()->where('slug', $slug)->exists()) {
            return [];
        }

        $category = $this->categories->create(['name' => $name, 'slug' => $slug], $this->actor);

        return [['type' => 'category', 'id' => $category->id, 'snapshot' => ['slug' => $slug]]];
    }

    private function categoryId(string $slug): ?string
    {
        $id = Category::query()->where('slug', $slug)->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * 商品を 1 つ入れる（説明の写真 → 商品 → 写真 → 種類 → 在庫 → オプション → カテゴリ）。印には入れ終えたときのバージョンと写真と出どころを残す
     *
     * @param  array<string, mixed>  $spec  SampleSet::listings() の 1 つ
     * @return list<array{key?: string, type: string, id: string|null, snapshot: array<string, mixed>}>
     */
    private function addListing(SampleSet $set, string $key, array $spec, ?string $categoryId): array
    {
        /** @var array{title: string, price: int, kind?: string, summary?: string, blocks: list<array<string, mixed>>, specs?: list<array{label: string, value: string}>, photos: array<string, array{0: string, 1: string}>, gallery?: list<array{0: string, 1: string}>, options?: list<array{name: string, values: list<string>}>, variants?: array<string, array{sku: string, stock: int, price?: int, stopped?: bool}>, sku?: string, stock?: int, addons?: list<array<string, mixed>>, tax_class?: 'standard'|'reduced'} $spec */
        $marks = [];
        $used = [];
        $blocks = [];

        // 説明の写真はショップの写真に上げてから指す（写真が無ければブロックを外す）
        foreach ($spec['blocks'] as $block) {
            if (($block['type'] ?? null) === 'image') {
                $name = (string) ($block['photo'] ?? '');
                $path = $this->photo($name);

                if ($path === null) {
                    continue;
                }

                $caption = is_string($block['caption'] ?? null) ? $block['caption'] : null;
                $media = $this->media->add($path, $caption ?? $spec['title'], $this->actor);
                $marks[] = ['key' => SampleSets::key($set, 'media', "{$key}-{$name}"), 'type' => 'shop_media', 'id' => $media->id, 'snapshot' => ['name' => $name, 'credit' => $this->credit($set, $name)]];
                $used[] = $name;
                $block = ['type' => 'image', 'media_id' => $media->id, 'caption' => $caption];
            }

            $blocks[] = $block;
        }

        $photos = [];

        foreach ($spec['photos'] as $value => [$name, $alt]) {
            if (($path = $this->photo($name)) !== null) {
                $photos[] = [(string) $value, $path, $alt];
                $used[] = $name;
            }
        }

        foreach ($spec['gallery'] ?? [] as [$name, $alt]) {
            if (($path = $this->photo($name)) !== null) {
                $photos[] = ['', $path, $alt];
                $used[] = $name;
            }
        }

        $credits = array_values(array_filter(array_map(fn (string $name): ?array => $this->credit($set, $name), array_values(array_unique($used)))));

        if ($credits !== []) {
            $blocks[] = ['type' => 'note', 'tone' => 'info', 'text' => self::creditLine($credits)];
        }

        $data = [
            'title' => $spec['title'],
            'unit_amount' => $spec['price'],
            'status' => ListingStatus::Active,
            'summary' => $spec['summary'] ?? null,
            'description_blocks' => $blocks,
            'specs' => $spec['specs'] ?? [],
        ];

        if (isset($spec['sku'])) {
            $data['sku'] = $spec['sku'];
        }

        // 消費税の区分（ADR 0063）。無ければ標準
        if (isset($spec['tax_class'])) {
            $data['tax_class'] = TaxClass::from($spec['tax_class']);
        }

        // 商品の扱い（無ければ product。デモの店はチケット・予約の品・ダウンロードも作る。ADR 0086）
        $listing = $this->listings->create(Seller::query()->orderBy('created_at')->firstOrFail(), $spec['kind'] ?? 'product', $data, $this->actor);
        $location = Location::query()->where('seller_id', $listing->seller_id)->orderBy('created_at')->firstOrFail();
        $image = [];

        foreach ($photos as [$value, $path, $alt]) {
            $id = $this->images->add($listing, $path, $alt, $this->actor)->id;

            if ($value !== '') {
                $image[$value] = $id;
            }
        }

        if (isset($spec['options'], $spec['variants'])) {
            $listing = $this->variants->define($listing, $listing->version, $spec['options'], $this->actor);

            foreach ($listing->variants()->get() as $variant) {
                $label = $variant->label();
                $variantSpec = $spec['variants'][$label] ?? throw new LogicException("サンプルの種類の書き忘れ: {$label}");
                $changes = ['sku' => $variantSpec['sku']];
                $color = explode(' / ', $label)[0];

                if (isset($variantSpec['price'])) {
                    $changes['unit_amount'] = $variantSpec['price'];
                }

                if (isset($image[$color])) {
                    $changes['image_id'] = $image[$color];
                }

                if ($variantSpec['stopped'] ?? false) {
                    $changes['status'] = VariantStatus::Stopped;
                }

                $this->variants->update($variant, $this->version($listing), $changes, $this->actor);

                if ($variantSpec['stock'] > 0) {
                    $this->inventory->receive($variant, $location, $variantSpec['stock'], $this->actor);
                }
            }
        } elseif (($spec['stock'] ?? 0) > 0) {
            $this->inventory->receive($listing, $location, (int) $spec['stock'], $this->actor);
        }

        if (isset($spec['addons'])) {
            $this->addons->define($listing, $this->version($listing), $spec['addons'], $this->actor);
        }

        if ($categoryId !== null) {
            $this->categories->assign($listing, [$categoryId], null, $this->actor);
        }

        array_unshift($marks, ['type' => 'listing', 'id' => $listing->id, 'snapshot' => ['version' => $this->version($listing), 'images' => ShopSamples::imageIds($listing->id), 'credits' => $credits]]);

        return $marks;
    }

    /**
     * テーマの中身を入れて公開する。お店がもう中身を作っていれば触らない（前のサンプルを消して空に戻したまま、お店が触っていない中身は除く）。
     * 写真はショップの写真に上げ、写真にも印を付ける
     *
     * @param  array<string, string|null>  $category  slug => ID
     * @return list<array{key?: string, type: string, id: string|null, snapshot: array<string, mixed>}>
     */
    private function addThemeContent(SampleSet $set, array $category): array
    {
        $theme = $set->theme();
        $current = $this->content->current($theme);

        if ($current !== null && ! $this->clearedBySamples($current)) {
            return [];
        }

        $marks = [];
        $refs = [];

        foreach ($set->media() as $key => [$name, $alt]) {
            if (($path = $this->photo($name)) === null) {
                continue;
            }

            $markKey = SampleSets::key($set, 'media', $key);
            $refs["media:{$key}"] = $this->media->add($path, $alt, $this->actor)->id;
            $marks[] = ['key' => $markKey, 'type' => 'shop_media', 'id' => $refs["media:{$key}"], 'snapshot' => ['name' => $name, 'credit' => $this->credit($set, $name)]];
        }

        foreach ($category as $slug => $id) {
            $refs["category:{$slug}"] = $id;
        }

        foreach (array_keys($set->listings()) as $key) {
            $id = SampleMark::query()->active()->where('key', SampleSets::key($set, 'listing', $key))->value('subject_id');
            $refs["listing:{$key}"] = is_string($id) && Listing::query()->whereKey($id)->exists() ? $id : null;
        }

        // 写真・カテゴリ・商品は名前で書いてあるので ID に替える（無い物は外す）
        $sections = [];

        foreach ($set->themeSections() as $type => $section) {
            $resolved = self::resolve($section, $refs);
            $sections[$type] = is_array($resolved) ? $resolved : [];
        }

        $schema = $this->themes->get($theme)->schema();
        // 区画の並びのあるテーマでは、今の形（種類 => 値）で送らなかった種類の区画が並びから外れる（SectionValues::fromKeyed）。
        // 見本に値の無い区画（商品の並び・フッターなど）も残すように、トップの最初の並びの種類は空の値で送る
        foreach (ThemeContentService::pageSpec($schema, 'home')->default as $entry) {
            $sections[$entry['type']] ??= [];
        }
        $version = $this->content->saveDraft($theme, $schema, $current?->id, $current->revision ?? 0, $sections, $this->actor);
        $version = $this->content->publish($theme, $schema, $version->id, $version->revision, $this->actor);
        $marks[] = ['type' => 'theme_version', 'id' => $version->id, 'snapshot' => ['revision' => $version->revision]];

        return $marks;
    }

    /**
     * 前のサンプルが消して空に戻し、その後にお店が触っていないテーマの中身か
     */
    private function clearedBySamples(ThemeVersion $version): bool
    {
        return SampleMark::query()->where('subject_type', 'theme_version')->where('subject_id', $version->id)->where('outcome', 'removed')->get()
            ->contains(static fn (SampleMark $mark): bool => ($mark->snapshot['cleared'] ?? null) === $version->revision);
    }

    /**
     * 特商法の表記の見本。お店がもう書いていれば触らない
     *
     * @return list<array{type: string, id: string|null, snapshot: array<string, mixed>}>
     */
    private function addLegal(SampleSet $set): array
    {
        if ($this->settings->legal() !== []) {
            return [];
        }

        $this->settings->setLegal(['items' => $set->legal()], $this->actor);

        return [['type' => 'shop_settings', 'id' => null, 'snapshot' => ['legal' => ShopSamples::legalHash($this->settings->legal())]]];
    }

    /**
     * 送料。お店がまだ決めていない（0 円・無料の線なし）ときだけ入れる。消すときは残す（印は入れたときに閉じる）
     *
     * @return list<array{type: string, id: string|null, snapshot: array<string, mixed>, kept?: bool}>
     */
    private function addShipping(SampleSet $set): array
    {
        // 送料の表（ADR 0064）が入れたときのまま（方法 1 つ・全部 0 円・無料の線なし）の店だけ。入れるのはいちばん上の方法を全国一律に
        if (! app(ShippingMethods::class)->unset()) {
            return [];
        }

        $this->settings->setShipping($set->shipping(), $this->actor);

        return [['type' => 'shop_settings', 'id' => null, 'snapshot' => ['shipping' => $set->shipping()], 'kept' => true]];
    }

    /**
     * フォルダの中の写真。雑貨（配る物の中の絵）は無ければ止める（配った中身が欠けている）。ほかは無ければ null（その写真なしで入れる）
     */
    private function photo(string $name): ?string
    {
        if (preg_match(self::PHOTO_NAME, $name) !== 1) {
            throw new LogicException("サンプルの写真の名前の形が違います: {$name}");
        }

        foreach (self::EXTENSIONS as $extension) {
            $path = "{$this->directory}/{$name}.{$extension}";

            if (is_file($path)) {
                return $path;
            }
        }

        if ($this->strict) {
            throw new RuntimeException("サンプルの写真が見つかりません: {$name}");
        }

        $this->missing[$name] ??= 'フォルダにありません';

        return null;
    }

    /**
     * 写真の出どころ（無ければ null。雑貨の絵には無い）
     *
     * @return array{photo: string, author: string|null, page: string|null, source: string|null}|null
     */
    private function credit(SampleSet $set, string $name): ?array
    {
        $source = $set->photos()[$name] ?? null;

        if ($source === null) {
            return null;
        }

        return ['photo' => $name, 'author' => $source['author'], 'page' => $source['page'], 'source' => $source['id'] ?? $source['url'] ?? null];
    }

    /**
     * 商品の説明の終わりに置く出どころの一行（例「写真: Annie Spratt・Amr Serag（Unsplash）」）
     *
     * @param  list<array{photo: string, author: string|null, page: string|null, source: string|null}>  $credits
     */
    private static function creditLine(array $credits): string
    {
        $authors = array_values(array_unique(array_filter(array_column($credits, 'author'), 'is_string')));

        return $authors === [] ? '写真: Unsplash' : '写真: '.implode('・', $authors).'（Unsplash）';
    }

    /**
     * 値の中の名前（media:… category:… listing:…）を ID に替える。無い物は null にし、並び（リスト）からは外す
     *
     * @param  array<string, string|null>  $refs
     */
    private static function resolve(mixed $value, array $refs): mixed
    {
        if (is_string($value) && preg_match('/\A(media|category|listing):[a-z0-9-]+\z/', $value) === 1) {
            return $refs[$value] ?? null;
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = self::resolve($item, $refs);
        }

        return array_is_list($value) ? array_values(array_filter($out, static fn (mixed $item): bool => $item !== null)) : $out;
    }

    /**
     * 写真のアップロードの 1 分の回数に触れそうなら、書き込みの錠の外で待つ（待つ間に店の書き込みを止めない）
     */
    private function pace(int $count): void
    {
        if ($count === 0) {
            return;
        }

        $wait = $this->quota->secondsUntil($count);

        if ($wait > 0) {
            $this->say("写真を続けてアップロードする回数の上限（1 分あたり）に近いので、{$wait} 秒待ちます");
            Sleep::for($wait)->seconds();
        }
    }

    private function say(string $message): void
    {
        if ($this->notice !== null) {
            ($this->notice)($message, false);
        }
    }

    private function version(Listing $listing): int
    {
        return Listing::query()->whereKey($listing->id)->value('version') ?? throw new LogicException('サンプルの商品が見つかりません');
    }
}
