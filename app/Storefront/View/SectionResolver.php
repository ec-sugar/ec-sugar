<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Core\Media\Models\ShopMedia;
use App\Core\Plugin\Sections\AppSection;
use App\Core\Plugin\Sections\AppSectionContent;
use App\Core\Plugin\Sections\Embed;
use App\Core\Plugin\Sections\ShopAppSections;
use App\Core\Plugin\Services\AppSectionCatalog;
use App\Core\Section\CoreSections;
use App\Core\Section\FieldSpec;
use App\Core\Section\FieldType;
use App\Core\Section\PageSpec;
use App\Core\Section\SectionHtml;
use App\Core\Section\SectionSchema;
use App\Core\Section\SectionSpec;
use App\Core\Section\SectionValues;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Storefront\Apps\ConsentCookie;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Catalog\ListingCard;
use Closure;
use Throwable;

/**
 * テーマの区画の公開した値を、ショップに出してよい形に組み立てる（テーマ API 2。ADR 0028・0039）。
 * 写真は置き場にあるものだけ、商品は公開中のものだけ（Catalog）。リンクは保存のときに形を確かめた href をそのまま渡す。
 * HTML の欄（html・richtext）は、保存のときに整えた値を、描く前にもう 1 度同じ決まりで整えた HtmlString にする（SectionHtml::render）。
 * 試し見（trial）と下書きのプレビューも同じ道を通る
 *
 * ページの区画の部品は、テーマの views の sections/{種類}/{パターン}.blade.php、無ければ sections/{種類}.blade.php。
 * どちらも無ければ核の部品（storefront.sections.…。核の汎用の区画 basic_* のため）。
 * 種類とパターンの名前は宣言で確かめた英小文字・数字・_ だけなので、部品の名前に使っても別の画面を指せない
 *
 * App の区画（ADR 0040）: 店で使う App の区画をテーマの宣言に足して読む（AppSectionCatalog）。部品はテーマの部品を先に、無ければ App の Blade
 * （app-{App の名前}::sections.…）。描く前に、App の係に埋め込む URL を作らせ、宣言の宛先に合うかを確かめ、
 * お客さんの同意で「開いた時から読み込む」か「押すまで待つ」かを決めて $section->app に入れる（AppSectionView）
 *
 * @phpstan-import-type Placed from SectionValues
 */
final readonly class SectionResolver
{
    /** 試し見の区画の ID（区画の ID の形に合う決まった名前。差し込むとき、編集の部品が区画の印〔data-section〕ごと外す） */
    public const string TRIAL_ID = 'sugar-trial';

    public function __construct(
        private ThemeContentService $content,
        private Catalog $catalog,
        private AppSectionCatalog $apps,
        private ShopSettingsService $shop,
    ) {}

    /**
     * @param  bool  $draft  公開した値でなく、使うバージョンの下書きで組み立てる（編集の画面のプレビューだけ。ADR 0039 区切り 2）
     * @param  bool  $live  App の区画が外から埋め込んでよい（ショップの画面。プレビューでは偽で、外へは何も読み込まない。ADR 0040）
     */
    public function forTheme(ThemeDefinition $theme, bool $draft = false, bool $live = true): ThemeSections
    {
        // 店で使う App の区画は、この描画の中で 1 度だけ読む（覚えておかない。次の要求では読み直す）
        $apps = $this->apps->now();
        $schema = $apps->schema($theme->schema());
        $preview = $draft || ! $live;
        $read = fn (string $page): array => $draft
            ? $this->content->draftPage($this->content->current($theme->name), $schema, $page)
            : $this->content->publishedPage($theme->name, $schema, $page);

        return new ThemeSections($schema, $read('home'),
            fn (SectionSpec $spec, array $value): SectionView => $this->resolve($apps, $schema, $spec, $value, preview: $preview),
            static fn (string $type, string $pattern): ?string => self::partial($apps, $theme, $type, $pattern),
            $read);
    }

    /**
     * 区画の試し見: そのページに置ける種類 × パターンの区画 1 つを、テーマの見本の値（example・example_blocks）で組み立てる。
     * 写真の欄は無地の仮の写真（見本には写真を書けないので、写真が無いと何も描かない区画も形が分かるように）。
     * 繰り返しの組の見本が無い区画は、空の組を 3 つまで入れる（写真の並びの形を見せる）。DB には何も書かない（店のデータは商品とカテゴリを読むだけ）。
     * 置けない種類・無いパターンは null。部品の無い種類（外枠が描くお知らせの帯・フッターなど）は、view の無い SectionView（描く物は無い）
     */
    public function trial(ThemeDefinition $theme, PageSpec $page, string $type, string $pattern): ?SectionView
    {
        $apps = $this->apps->now();
        $schema = $apps->schema($theme->schema());
        $spec = $page->allows($type) ? $schema->section($type) : null;

        if ($spec === null || ! $spec->hasPattern($pattern)) {
            return null;
        }

        $view = self::partial($apps, $theme, $type, $pattern);
        if ($view === null) {
            return new SectionView($type, [], [], self::TRIAL_ID, $pattern);
        }

        $items = $spec->example['items'] ?? [];
        if ($items === [] && $spec->hasItems()) {
            $items = array_fill(0, min(3, $spec->maxItems), []);
        }

        $placed = SectionValues::sanitizePage($schema, $page, [[
            'id' => self::TRIAL_ID, 'type' => $type, 'pattern' => $pattern, 'hidden' => false,
            'values' => $spec->example['values'] ?? [], 'items' => $items, 'blocks' => $spec->exampleBlocks,
        ]])[0] ?? null;

        return $placed === null ? null : $this->resolve($apps, $schema, $spec, $placed, placeholders: true, preview: true)->withView($view);
    }

    /**
     * 種類 × パターンの区画をページに描く部品があるかを答える係。部品の無い種類（お知らせの帯・フッターなど）は外枠が
     * $sections->get で読む。ページの雛形で作り直すとき、こうした区画を今の並びから残すのに使う（店で使う App は 1 度だけ読む）
     *
     * @return Closure(string, string): bool
     */
    public function drawsInPage(ThemeDefinition $theme): Closure
    {
        $apps = $this->apps->now();

        return static fn (string $type, string $pattern): bool => self::partial($apps, $theme, $type, $pattern) !== null;
    }

    /**
     * テーマの部品を先に、無ければ核の汎用の区画の部品（resources/views/storefront/sections。ADR 0039 区切り 3）。
     * App の区画（ADR 0040）は、テーマの部品が無ければ App の Blade（app-{App の名前}::sections.…）
     */
    private static function partial(ShopAppSections $apps, ThemeDefinition $theme, string $type, string $pattern): ?string
    {
        $app = $apps->section($type);
        $names = $app === null
            ? ["storefront.sections.{$type}.{$pattern}", "storefront.sections.{$type}"]
            : ["{$app->viewNamespace()}::sections.{$type}.{$pattern}", "{$app->viewNamespace()}::sections.{$type}"];

        foreach ([$theme->view("sections.{$type}.{$pattern}"), $theme->view("sections.{$type}"), ...$names] as $name) {
            if (view()->exists($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * App の区画の埋め込みの扱い（ADR 0040）。App の係が作った URL は、宣言の frame-src・links の宛先に合うものだけを使う
     * （合わない枠の URL なら埋め込まない・合わないリンクは出さない）。係が失敗してもページは止めない（その区画を出さないだけ）
     *
     * @param  array<string, mixed>  $values  保存した欄の値
     */
    private function app(ShopAppSections $apps, AppSection $section, array $values, bool $preview): AppSectionView
    {
        $settings = $apps->settings($section->plugin);
        $rules = $section->embed;
        $embed = null;

        if ($rules !== null && $section->handler !== null && $settings !== null) {
            try {
                $embed = $section->handler->embed($section->type(), $values, $settings);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($embed !== null && ! $rules->allowsFrame($embed->src)) {
            $embed = null;
        }

        if ($embed !== null && $embed->link !== null && ! $rules->allowsLink($embed->link)) {
            $embed = new Embed($embed->src, $embed->title, null, $embed->allow);
        }

        // 枠の allow は宣言の中から。係が絞ったなら（自動で再生するときだけ autoplay など）、宣言と重なる言葉だけ
        $allow = $rules->allow ?? [];

        if ($embed?->allow !== null) {
            $allow = array_values(array_intersect($allow, $embed->allow));
        }

        // ショップで出すのは、区画の同意の種類にお客さんが全部同意しているとき（種類が無ければ誰にでも。動画・地図は誰にでも最初から枠を描く）。
        // 同意の帯を出さない店（既定）では、種類があっても誰にでも出す（同意の Cookie を読まない）
        $consent = $preview || $section->consent === [] || ! $this->shop->showsConsentBanner() ? [] : $section->consent;
        $decisions = $consent === [] ? [] : ConsentCookie::read(request());
        $live = ! $preview && array_filter($consent, static fn ($kind): bool => ($decisions[$kind->value] ?? false) !== true) === [];

        // 店のデータを並べる区画（ADR 0072）: App の係が、出してよいものだけを返す（公開した記事など。プレビューでも同じもの）
        $content = null;

        if ($section->handler instanceof AppSectionContent && $settings !== null) {
            try {
                $content = $section->handler->content($section->type(), $values);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return new AppSectionView($section->plugin, $section->title, $embed, $live, $preview, $settings !== null,
            // 埋め込まない区画（ADR 0071）は出してよければ出す。並べる係を持つ区画（ADR 0072）は並べるものがある時だけなので、埋め込む区画と同じ扱いに
            $rules->frames ?? [], $rules->sandbox ?? [], $allow, embeds: $rules !== null || $section->handler instanceof AppSectionContent, content: $content);
    }

    /**
     * @param  Placed  $value
     * @param  bool  $placeholders  写真の欄をすべて無地の仮の写真にする（試し見だけ。置き場は読まない）
     * @param  bool  $preview  編集の画面のプレビュー・試し見（App の区画は外へ何も読み込まない）
     */
    private function resolve(ShopAppSections $apps, SectionSchema $schema, SectionSpec $spec, array $value, bool $placeholders = false, bool $preview = false): SectionView
    {
        $imageIds = $placeholders ? [] : SectionValues::pageImageIds($schema, [$value]);
        $photos = $imageIds === [] ? [] : ShopMedia::query()->whereIn('id', $imageIds)->get()
            ->mapWithKeys(static fn (ShopMedia $m): array => [$m->id => new ShopPhoto($m->id, $m->width, $m->height, $m->alt)])
            ->all();

        $row = fn (array $fields, array $values): array => array_map(
            fn (FieldSpec $field): mixed => $placeholders && $field->type === FieldType::Image
                ? ShopPhoto::placeholder()
                : $this->field($field, $values[$field->key] ?? null, $photos), $fields);

        $blocks = [];
        foreach ($value['blocks'] as $block) {
            $blockSpec = $spec->blocks[$block['type']] ?? null;

            if ($blockSpec !== null) {
                $blocks[] = new SectionView($blockSpec->key, $row($blockSpec->fields, $block['values']), []);
            }
        }

        $values = $row($spec->fields, $value['values']);

        // 核の共通の区画「1 つの商品を大きく」: 選んだ商品を、商品の画面と同じ詳細（写真・種類・オプション・入れられる数）に組み立てる。
        // 公開中の商品だけ（Catalog::product）。選んでいない・公開でなくなった・消した商品は null（部品は何も描かない）。試し見では選ばせないので null
        // テーマは basic_ で始まる種類を宣言できない（SectionSchema::fromManifest）ので、この名前はいつも核の区画
        // 選んだ ID（保存のときに ULID の形を確かめ済み）を、公開中だけを読む Catalog::product に直接渡す（ここが公開の関所）
        if ($spec->key === CoreSections::PRODUCT) {
            $id = $placeholders ? null : ($value['values']['product']['ids'][0] ?? null);
            $values['product'] = is_string($id) ? $this->catalog->product($id) : null;
        }

        $view = new SectionView($spec->key, $values,
            array_map(static fn (array $item): array => $row($spec->itemFields, $item), $value['items']),
            $value['id'], $value['pattern'], $blocks);

        // App の区画（ADR 0040）。宣言は店で使う App の分だけ足してあるので、ここに来るのは使っている App の区画だけ
        $app = $schema->isApp($spec->key) ? $apps->section($spec->key) : null;

        return $app === null ? $view : $view->withApp($this->app($apps, $app, $value['values'], $preview || $placeholders));
    }

    /**
     * @param  array<string, ShopPhoto>  $photos
     */
    private function field(FieldSpec $field, mixed $value, array $photos): mixed
    {
        return match ($field->type) {
            FieldType::Image => is_string($value) ? ($photos[$value] ?? null) : null,
            FieldType::Products => is_array($value) ? $this->products($field, $value) : [],
            FieldType::Categories => $this->catalog->categoryTiles(is_array($value) ? array_values(array_filter($value, 'is_string')) : [], (int) $field->max),
            // 描くときの整え（保存のときに整えてあっても、決まりを後で狭めたとき・DB を直に書き換えられたときに備えて、もう 1 度通す）
            FieldType::Html, FieldType::Richtext => SectionHtml::render($value),
            default => $value,
        };
    }

    /**
     * @param  array<mixed>  $value  {source, category, ids}
     * @return list<ListingCard>
     */
    private function products(FieldSpec $field, array $value): array
    {
        $max = (int) $field->max;

        return match ($value['source'] ?? null) {
            'new' => $this->catalog->featured($max),
            'category' => is_string($value['category'] ?? null) ? $this->catalog->inCategory($value['category'], $max) : [],
            'pick' => array_slice($this->catalog->picked(array_values(array_filter((array) ($value['ids'] ?? []), 'is_string'))), 0, $max),
            default => [],
        };
    }
}
