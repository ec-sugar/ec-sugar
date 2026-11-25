<?php

declare(strict_types=1);

namespace App\Storefront\Themes;

use App\Core\Catalog\Models\Category;
use App\Core\Listing\Models\ListingImage;
use App\Core\Media\Models\ShopMedia;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Section\FieldType;
use App\Core\Section\SectionSchema;
use App\Core\Section\SectionValues;
use App\Core\Theme\Services\StaleThemeVersionException;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Models\Tenant;
use App\Storefront\Catalog\Catalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 店の設定にあった見せ方の項目（区切り 2c で控えの表 legacy_storefront_settings へ移した）を、テーマの区画の値へ写す（ADR 0028）。
 * テナント文脈の中で呼ぶ。区画を宣言したテーマへ初めて切り替えたとき（ThemeSelector）と、コマンドから。
 *
 * - 写すのは、そのテーマにまだバージョンが無いときだけ（何度走らせても同じ。店の人が区画を編集した後は触らない）
 * - 区画と欄は決まった名前で写し（お知らせの帯 announcement.text、ヒーロー hero.heading・body・image、安心の一行 promises の繰り返し、
 *   トップの区画 categories.list・looks・seasonal・favorites.products）、テーマが宣言していないものは捨てる（SectionValues::sanitize）
 * - 特集（looks・seasonal）の写真は商品の写真を借りていたので、ショップの写真へ写し直す（商品を取り下げても特集の写真が消えないように）
 * - 写すのは公開中の商品の写真だけ（今のショップに出ていない写真を、写しで出さない。Catalog::publishedImage）。同じ写真は 1 枚だけ写す
 * - ヒーローの写真は控えでは外部キーで守っていないので、置き場から消えていたら外す。カテゴリの並びも、今は無いカテゴリを外す
 * - 古い文のタブなどの制御文字は空白にしてから写す（新しい値は制御文字を断るので、黙って消さない）
 * - 写した値の下書きの保存と公開は 1 つのトランザクションで行う（途中で落ちて「下書きだけのバージョン」が残り、やり直しても直らない、を防ぐ）
 *
 * 写し直した写真は、そのあとの保存で失敗すると使われない写真として置き場に残る（消すのは店の人。数は置き場の上限で抑えられる）。
 */
final readonly class LegacyContentImport
{
    public function __construct(
        private ThemeContentService $content,
        private ShopMediaService $media,
        private Catalog $catalog,
    ) {}

    /**
     * @return bool 写したか（すでにバージョンがある・写すものが無いなら false）
     */
    public function import(ThemeDefinition $theme, string $actor): bool
    {
        $schema = $theme->schema();

        if ($schema->sections === [] || $this->content->current($theme->name) !== null) {
            return false;
        }

        $legacy = DB::table('legacy_storefront_settings')->first();

        if ($legacy === null) {
            return false;
        }

        $text = self::object($legacy->storefront);
        $old = self::object($legacy->sections);
        $hero = is_array($text['hero'] ?? null) ? $text['hero'] : [];

        $copies = [];
        $copy = function (mixed $imageId) use (&$copies, $actor): ?string {
            return is_string($imageId) ? ($copies[$imageId] ??= $this->copy($imageId, $actor)) : null;
        };

        $sections = [
            'announcement' => ['values' => ['text' => $text['announcement'] ?? null]],
            'hero' => ['values' => ['heading' => $hero['heading'] ?? null, 'body' => $hero['body'] ?? null, 'image' => $legacy->hero_media_id]],
            'promises' => ['items' => array_values(array_map(static fn (mixed $p): array => is_array($p) ? ['icon' => $p['icon'] ?? null, 'title' => $p['title'] ?? null, 'body' => $p['body'] ?? null] : [],
                is_array($text['promises'] ?? null) ? $text['promises'] : []))],
            'categories' => ['values' => ['list' => self::existingCategories($old['categories'] ?? null)]],
        ];

        $favorites = is_array($old['favorites'] ?? null) ? array_values(array_filter($old['favorites'], 'is_string')) : [];
        if ($favorites !== []) {
            $sections['favorites'] = ['values' => ['products' => ['source' => 'pick', 'ids' => $favorites]]];
        }

        // 特集の写真は、テーマが区画を宣言しているときだけ写し直す（使わない写真を置き場に増やさない）
        if ($schema->has('looks') && is_array($old['looks'] ?? null)) {
            $looks = $old['looks'];
            $sections['looks'] = [
                'values' => ['heading' => $looks['heading'] ?? null, 'body' => $looks['body'] ?? null],
                'items' => array_values(array_map(fn (mixed $row): array => [
                    'title' => is_array($row) ? ($row['title'] ?? null) : null,
                    'image' => is_array($row) ? $copy($row['image_id'] ?? null) : null,
                ], is_array($looks['items'] ?? null) ? $looks['items'] : [])),
            ];
        }

        if ($schema->has('seasonal') && is_array($old['seasonal'] ?? null)) {
            $s = $old['seasonal'];
            $sections['seasonal'] = ['values' => [
                'label' => $s['label'] ?? null, 'heading' => $s['heading'] ?? null, 'body' => $s['body'] ?? null,
                'highlight' => $s['highlight'] ?? null, 'note' => $s['note'] ?? null, 'image' => $copy($s['image_id'] ?? null),
            ]];
        }

        // 宣言に無い区画・合わない値は捨てる（読むときと同じ寛い読み方）。無い写真は外す。何も残らなければバージョンを作らない
        $values = self::withoutMissingImages($schema, SectionValues::sanitize($schema, self::withoutControls(array_intersect_key($sections, $schema->sections))));
        $values = array_filter($values, static fn (array $section): bool => $section['items'] !== [] || array_filter($section['values'], static fn (mixed $v): bool => $v !== null && $v !== []) !== []);

        if ($values === []) {
            return false;
        }

        // 並びのあるテーマ（ADR 0039）では、種類 => 値の保存は送らなかった種類の区画を並びから外すので、
        // トップの最初の並びの種類は空の値でも送る（写したものだけのトップにせず、新着の商品などの区画を残す）
        $home = $schema->page('home');
        if ($home !== null && ! $home->fixed) {
            foreach ($home->default as $entry) {
                $values[$entry['type']] ??= SectionValues::sanitize($schema, [$entry['type'] => []])[$entry['type']];
            }
        }

        try {
            DB::transaction(function () use ($theme, $schema, $values, $actor): void {
                $version = $this->content->saveDraft($theme->name, $schema, null, 0, $values, $actor);
                $this->content->publish($theme->name, $schema, $version->id, $version->revision, $actor);
            });
        } catch (StaleThemeVersionException) {
            // 写している間に、店の人か別の写しが先にバージョンを作った（上書きしない）
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? $value : [];
    }

    /**
     * 置き場に無い写真の欄を空にする（控えのヒーローの写真は、置き場から消されていることがある）
     *
     * @param  array<string, array{hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>}>  $values
     * @return array<string, array{hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>}>
     */
    private static function withoutMissingImages(SectionSchema $schema, array $values): array
    {
        $ids = SectionValues::imageIds($schema, $values);
        $found = $ids === [] ? [] : ShopMedia::query()->whereIn('id', $ids)->pluck('id')->all();

        foreach ($values as $key => $section) {
            $spec = $schema->section($key);

            if ($spec === null) {
                continue;
            }

            $strip = static function (array $row, array $fields) use ($found): array {
                foreach ($fields as $field) {
                    if ($field->type === FieldType::Image && is_string($row[$field->key] ?? null) && ! in_array($row[$field->key], $found, true)) {
                        $row[$field->key] = null;
                    }
                }

                return $row;
            };

            $values[$key]['values'] = $strip($section['values'], $spec->fields);
            $values[$key]['items'] = array_map(static fn (array $item): array => $strip($item, $spec->itemFields), $section['items']);
        }

        return $values;
    }

    /**
     * 控えのカテゴリの並びから、今は無いカテゴリを外す（控えは外部キーで守っておらず、消したカテゴリが残りうる。
     * 区画の保存は無いカテゴリを断るので、外さないとテーマを切り替えられなくなる）
     *
     * @return list<mixed>
     */
    private static function existingCategories(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $ids = array_map('strtolower', array_filter($list, 'is_string'));
        $found = $ids === [] ? [] : Category::query()->whereIn('id', $ids)->pluck('id')->all();

        return array_values(array_filter($list, static fn (mixed $id): bool => ! is_string($id) || in_array(strtolower($id), $found, true)));
    }

    /**
     * 文の中のタブなどの制御文字（改行は残す）を空白にする
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function withoutControls(array $value): array
    {
        array_walk_recursive($value, static function (mixed &$v): void {
            if (is_string($v)) {
                $v = (string) preg_replace('/[^\P{Cc}\n]/u', ' ', str_replace(["\r\n", "\r"], "\n", $v));
            }
        });

        return $value;
    }

    /**
     * 公開中の商品の写真（大きい方）をショップの写真へ写す。写真が無い・公開中の商品のものでなければ null
     */
    private function copy(string $imageId, string $actor): ?string
    {
        $image = $this->catalog->publishedImage(strtolower($imageId));
        $disk = Storage::disk('media');

        if ($image === null) {
            return null;
        }

        $path = ListingImage::path((string) Tenant::current()->getTenantKey(), $image->listing_id, $image->id, 'large');

        if (! $disk->exists($path)) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'legacy');

        if ($temp === false) {
            throw new RuntimeException('写真を写すための一時ファイルを作れませんでした');
        }

        try {
            file_put_contents($temp, (string) $disk->get($path));

            // 特集の写真は飾り（意味は隣の文字で伝えていた）なので、代わりの文字は付けない
            return $this->media->add($temp, null, $actor)->id;
        } finally {
            @unlink($temp);
        }
    }
}
