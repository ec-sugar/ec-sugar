<?php

declare(strict_types=1);

namespace App\Core\Theme\Services;

use App\Core\Catalog\Models\Category;
use App\Core\Event\EventStore;
use App\Core\Media\Models\ShopMedia;
use App\Core\Section\CoreSections;
use App\Core\Section\PageSpec;
use App\Core\Section\SectionSchema;
use App\Core\Section\SectionValues;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\ThemeStyle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * テーマの区画の値を読む・変える（ADR 0028 区切り 2・ADR 0039）。変えるのはここだけ。
 *
 * - 値は店 × テーマ × バージョンで持ち、各バージョンに下書きと公開がある。編集は下書きに入り、公開するまでお客さんには出ない
 * - 中身はテーマの宣言（SectionSchema）どおりに確かめてから書く。宣言は店頭の仕組み（ThemeRegistry）が theme.json から作って渡す
 * - 保存する形は { pages: { ページ: [ 区画, … ] } }（ページごとの区画の並び。ADR 0039。あとで見た目の値を同じバージョンに足す）。
 *   区画は { id, type, pattern, hidden, values, items, blocks }。まだ保存していないページは、テーマの最初の並び（空の値）として読む
 * - 今の書き方（種類 => 値）の読み書きも残す（今の値を写すコマンド・入れたときのサンプル・見本の店の seeder。管理画面の口は で外した）。
 *   トップの並びに写して読み書きする
 * - 書き込みは EventStore::lock() → バージョンの行のロックの順（ADR 0012）。読んだ改訂と違えば保存しない（同時の編集で上書きしない）
 * - 自由なページ（ADR 0039 区切り 3）の値も同じバージョンの中に page-{ID} の名前で持つ（テーマごと。公開もバージョンごとにまとめて）
 * - 写真があるかは、写真を消す側（ShopMediaService::remove）と同じロックの中で確かめる。使っている写真は消せない（usageMap）
 * - カテゴリも、この店にあるかを同じロックの中で確かめ、無ければ断る（消す側は区画で使っているカテゴリを消させない）
 * - イベントには文言を入れず、ページの名前・区画の種類の並び・改訂だけ
 * - 見た目の値（ADR 0097）も同じバージョンの下書き・公開に style で持つ（{ palette, accent, font, radius }。saveStyle）。公開で区画といっしょに出る
 * - App の区画（ADR 0040）: 店で止めた・外した App の区画は今の宣言で置けないので、読むときは出さないが、消さない。
 *   保存と公開のとき、しまってある並びの中の同じ位置に戻す（keepAppSections。App をまた使えば、元の位置に元の値で出る）
 *
 * @phpstan-import-type Placed from SectionValues
 * @phpstan-import-type Section from SectionValues
 */
final class ThemeContentService
{
    public const string DEFAULT_NAME = '標準';

    /** 1 つのバージョンの値の大きさの上限（JSON にしたときのバイト数。DB はこの倍で止める） */
    public const int MAX_BYTES = 262144;

    public function __construct(private readonly EventStore $events) {}

    /**
     * このテーマで使うバージョン（まだ無ければ null。読むだけで作らない）
     */
    public function current(string $theme): ?ThemeVersion
    {
        return ThemeVersion::query()->where('theme', $theme)->where('current', true)->first();
    }

    /**
     * ショップに出す値を今の形（種類 => 値。トップの種類ごとの最初の区画）で
     *
     * @return array<string, Section>
     */
    public function published(string $theme, SectionSchema $schema): array
    {
        return SectionValues::firstOfEachType($this->publishedPage($theme, $schema, 'home'));
    }

    /**
     * ショップに出すページの並び（使うバージョンの公開した値を、今の宣言で読み直したもの。まだ公開していなければテーマの最初の並び）
     *
     * @return list<Placed>
     */
    public function publishedPage(string $theme, SectionSchema $schema, string $page): array
    {
        return self::pageOf($this->current($theme)?->published, $schema, self::pageSpec($schema, $page));
    }

    /**
     * 編集中のページの並び（今の宣言で読み直したもの。バージョンが無ければテーマの最初の並び）
     *
     * @return list<Placed>
     */
    public function draftPage(?ThemeVersion $version, SectionSchema $schema, string $page): array
    {
        return self::pageOf($version?->draft, $schema, self::pageSpec($schema, $page));
    }

    /**
     * 編集中の値を今の形（種類 => 値。トップの種類ごとの最初の区画）で（saveDraft と対。サンプルの片付けの確かめなど）
     *
     * @return array<string, Section>
     */
    public function draft(ThemeVersion $version, SectionSchema $schema): array
    {
        return SectionValues::firstOfEachType($this->draftPage($version, $schema, 'home'));
    }

    /**
     * 今の形（種類 => 値）で下書きを置き換える。トップの並びに写す（SectionValues::fromKeyed）。
     * $versionId が null なら、まだバージョンが無いテーマに最初のバージョン（標準）を作る
     *
     * @param  mixed  $sections  画面から受け取った区画の値
     *
     * @throws ValidationException 宣言と違う・無い写真・無いカテゴリを指している・大きすぎる
     * @throws StaleThemeVersionException 読んだあとに変わっていた
     */
    public function saveDraft(string $theme, SectionSchema $schema, ?string $versionId, int $revision, mixed $sections, ?string $actor = null): ThemeVersion
    {
        // 誤りの道筋は今までどおり sections.hero.values.heading の形
        $keyed = SectionValues::validate($schema, $sections);
        $page = self::pageSpec($schema, 'home');

        return $this->write($theme, $schema, $page, $versionId, $revision, $actor,
            fn (ThemeVersion $version): array => SectionValues::validatePage($schema, $page,
                SectionValues::fromKeyed($schema, $page, $keyed, self::pageOf($version->draft, $schema, $page))));
    }

    /**
     * ページの区画の並びを置き換える（足す・消す・並べ替え・パターンの選び直し・値の変更をまとめて）。
     * ID の無い区画は新しく足した区画として ID を付ける。$versionId が null なら最初のバージョン（標準）を作る
     *
     * @param  mixed  $sections  画面から受け取った区画の並び
     *
     * @throws ValidationException 宣言と違う・置けない区画・上限を越えた・無い写真・無いカテゴリを指している・大きすぎる
     * @throws StaleThemeVersionException 読んだあとに変わっていた
     */
    public function savePage(string $theme, SectionSchema $schema, string $page, ?string $versionId, int $revision, mixed $sections, ?string $actor = null): ThemeVersion
    {
        $spec = self::pageSpec($schema, $page);
        $placed = SectionValues::validatePage($schema, $spec, $sections);

        return $this->write($theme, $schema, $spec, $versionId, $revision, $actor, static fn (): array => $placed);
    }

    /**
     * 下書きを公開する（ショップに出す）。すべてのページを今の宣言で読み直してから写す（バージョンごとに公開する）
     *
     * @throws StaleThemeVersionException
     */
    public function publish(string $theme, SectionSchema $schema, string $versionId, int $revision, ?string $actor = null): ThemeVersion
    {
        return DB::transaction(function () use ($theme, $schema, $versionId, $revision, $actor): ThemeVersion {
            $this->events->lock();
            $version = $this->lockVersion($theme, $versionId, $revision, $actor);

            // 宣言が変わっていても、今の宣言で読み直したものを出す（使っている写真は消せないので、写真は置き場にある）。
            // 下書きは書き換えない（今の宣言に合わない値も残し、テーマを戻せば取り戻せる）
            // 止めた App の区画は、公開の値にも元の位置のまま残す（ADR 0040。読むときに出さないだけ）
            $pages = array_map(static fn (PageSpec $page): array => self::keepAppSections(self::pageOf($version->draft, $schema, $page), self::storedPage($version->draft, $page->name), $page),
                self::pagesToPublish($version->draft, $schema));
            // 空なら {}（[] にすると形の検査に掛かる）。見た目の値（ADR 0097）は下書きのまま写す（描くときに今の宣言で読み直す）
            $version->published = ['pages' => $pages === [] ? (object) [] : $pages]
                + (is_array($version->draft['style'] ?? null) ? ['style' => $version->draft['style']] : []);
            $version->published_at = CarbonImmutable::now();
            $version->revision++;
            $version->save();
            $this->events->append('theme.published', 'theme_version', $version->id, ['theme' => $theme, 'revision' => $version->revision], $actor);

            return $version;
        });
    }

    /**
     * 見た目の値（色の組・主な色・字の組・角の丸み。ADR 0097）を下書きに保存する。区画の並びと同じバージョンの下書きに style で入り、
     * 公開でいっしょにショップに出る。$versionId が null なら最初のバージョン（標準）を作る
     *
     * @param  mixed  $input  画面から受け取った値（{ palette, accent, font, radius }）
     *
     * @throws ValidationException 宣言に無い名前・形の違う色・主な色を許さないテーマの主な色
     * @throws StaleThemeVersionException 読んだあとに変わっていた
     */
    public function saveStyle(string $theme, ThemeStyle $style, ?string $versionId, int $revision, mixed $input, ?string $actor = null): ThemeVersion
    {
        // 確かめてから書く（ロックの前に。何も保存しない）
        $values = $style->validate($input);

        return DB::transaction(function () use ($theme, $versionId, $revision, $actor, $values): ThemeVersion {
            $this->events->lock();
            $version = $this->lockVersion($theme, $versionId, $revision, $actor);
            $document = $version->draft;
            // まだどのページも保存していなければ {}（読み戻すと [] になり、[] では形の検査に掛かる）
            $document['pages'] = is_array($document['pages'] ?? null) && $document['pages'] !== [] ? $document['pages'] : (object) [];
            $document['style'] = $values;
            $version->draft = $document;
            $version->revision++;
            $version->save();
            // 記録には選んだ名前と、主な色を自分で決めたかだけ（色の値は残さない）
            $this->events->append('theme.style_saved', 'theme_version', $version->id, [
                'theme' => $theme, 'revision' => $version->revision,
                'palette' => $values['palette'], 'font' => $values['font'], 'radius' => $values['radius'], 'custom_accent' => $values['accent'] !== null,
            ], $actor);

            return $version;
        });
    }

    /**
     * ショップに出す見た目の値（使うバージョンの公開した値を、今の宣言で読み直したもの。まだ公開していなければ既定）
     *
     * @return array{palette: string, accent: string|null, font: string|null, radius: string|null}
     */
    public function publishedStyle(string $theme, ThemeStyle $style): array
    {
        return $style->resolve($this->current($theme)?->published['style'] ?? null);
    }

    /**
     * 編集中の見た目の値（今の宣言で読み直したもの。バージョンが無ければ既定）
     *
     * @return array{palette: string, accent: string|null, font: string|null, radius: string|null}
     */
    public function draftStyle(?ThemeVersion $version, ThemeStyle $style): array
    {
        return $style->resolve($version?->draft['style'] ?? null);
    }

    /**
     * テーマを替えるときに、自由なページの中身のうち写せるもの（ADR 0039 区切り 3 の続き）。
     * 写すのは、前のテーマの使っているバージョンの下書きに保存してあり、新しいテーマの使っているバージョンの下書きにはまだ無いページだけ
     * （新しいテーマに中身があれば触らない。前のテーマへ戻したときも、前のテーマの値はそのまま残っているので元に戻る）。
     * 写せる区画は、核の汎用の区画（basic_*）で新しいテーマの雛形に置けるものだけ。テーマ固有の区画は写せない（dropped に数える）
     *
     * @param  list<string>  $pages  自由なページの名前（page-{ID}）
     * @return array<string, array{sections: list<Placed>, dropped: int}> 写すページ => 写す区画と、写せない区画の数
     */
    public function planFreePageCopy(string $from, SectionSchema $fromSchema, string $to, SectionSchema $toSchema, array $pages): array
    {
        return self::plan($this->current($from), $fromSchema, $this->current($to), $toSchema, $pages);
    }

    /**
     * テーマを替えたあと、自由なページの基本の区画を新しいテーマの下書きへ写す（planFreePageCopy の中身）。公開はしない（今までどおり公開するまで出ない）。
     * 写したページの名前の並びを返す（何も写さなければ空。バージョンも作らない）
     *
     * @param  list<string>  $pages
     * @return list<string>
     */
    public function copyFreePages(string $from, SectionSchema $fromSchema, string $to, SectionSchema $toSchema, array $pages, ?string $actor = null): array
    {
        return DB::transaction(function () use ($from, $fromSchema, $to, $toSchema, $pages, $actor): array {
            $this->events->lock();
            $target = ThemeVersion::query()->where('theme', $to)->where('current', true)->lockForUpdate()->first();
            // 写す区画のあるページだけ（写せる区画が無いページは、新しいテーマの最初の並びのまま）
            $plan = array_filter(self::plan($this->current($from), $fromSchema, $target, $toSchema, $pages), static fn (array $copy): bool => $copy['sections'] !== []);

            if ($plan === []) {
                return [];
            }

            $target ??= $this->lockVersion($to, null, 0, $actor);
            $document = $target->draft;
            $saved = is_array($document['pages'] ?? null) ? $document['pages'] : [];
            $imageIds = [];

            foreach ($plan as $name => $copy) {
                $spec = self::pageSpec($toSchema, $name);
                // 新しいテーマの宣言で確かめ直してから書く（ID・パターン・欄はそのまま）
                $saved[$name] = SectionValues::validatePage($toSchema, $spec, $copy['sections']);
                $imageIds = [...$imageIds, ...SectionValues::pageImageIds($toSchema, $saved[$name]), ...array_merge([], ...array_column(SectionValues::pageHtmlImageIds($toSchema, $saved[$name]), 'ids'))];
            }

            $document['pages'] = $saved;

            if (strlen((string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_BYTES) {
                throw ValidationException::withMessages(['sections' => '内容が多すぎるため、新しいテーマへコピーできません。']);
            }

            // 写真は消されないよう押さえる（消す側も EventStore::lock() を先に取る）
            if ($imageIds !== []) {
                ShopMedia::query()->whereIn('id', array_values(array_unique($imageIds)))->lockForUpdate()->pluck('id');
            }

            $target->draft = $document;
            $target->revision++;
            $target->save();
            $this->events->append('theme.free_pages_copied', 'theme_version', $target->id, [
                'from' => $from, 'to' => $to, 'revision' => $target->revision, 'pages' => array_keys($plan),
            ], $actor);

            return array_keys($plan);
        });
    }

    /**
     * 自由なページの中身を、すべてのテーマ・バージョンの下書きと公開の値から消す（ページを完全に消すとき。ShopPageService::purge の中で呼ぶ）
     */
    public function forgetPage(string $page): void
    {
        if (PageSpec::freeId($page) === null) {
            throw new InvalidArgumentException("自由なページの名前ではありません: {$page}");
        }

        DB::transaction(function () use ($page): void {
            $this->events->lock();

            foreach (ThemeVersion::query()->orderBy('id')->lockForUpdate()->get() as $version) {
                $draft = self::without($version->draft, $page);
                $published = $version->published === null ? null : self::without($version->published, $page);

                if ($draft === null && $published === null) {
                    continue;
                }

                $version->draft = $draft ?? $version->draft;
                $version->published = $published ?? $version->published;
                $version->revision++;
                $version->save();
                $this->events->append('theme.page_forgotten', 'theme_version', $version->id, ['theme' => $version->theme, 'revision' => $version->revision, 'page' => $page]);
            }
        });
    }

    /**
     * 値からページを外したもの（そのページが無ければ null）
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    private static function without(array $document, string $page): ?array
    {
        if (! is_array($document['pages'] ?? null) || ! array_key_exists($page, $document['pages'])) {
            return null;
        }

        unset($document['pages'][$page]);
        // 空なら {}（[] にすると形の検査に掛かる）
        $document['pages'] = $document['pages'] === [] ? (object) [] : $document['pages'];

        return $document;
    }

    /**
     * @param  list<string>  $pages
     * @return array<string, array{sections: list<Placed>, dropped: int}>
     */
    private static function plan(?ThemeVersion $source, SectionSchema $fromSchema, ?ThemeVersion $target, SectionSchema $toSchema, array $pages): array
    {
        $sourcePages = is_array($source?->draft['pages'] ?? null) ? $source->draft['pages'] : [];
        $targetPages = is_array($target?->draft['pages'] ?? null) ? $target->draft['pages'] : [];
        $plan = [];

        foreach ($pages as $name) {
            $fromSpec = $fromSchema->page($name);
            $toSpec = $toSchema->page($name);

            if ($fromSpec === null || $toSpec === null || ! array_key_exists($name, $sourcePages) || array_key_exists($name, $targetPages)) {
                continue;
            }

            $sections = self::pageOf($source?->draft, $fromSchema, $fromSpec);
            $copied = array_values(array_filter($sections, static fn (array $s): bool => str_starts_with($s['type'], CoreSections::PREFIX) && $toSpec->allows($s['type'])));

            // 写せる区画が 1 つも無ければ写さない（新しいテーマの最初の並びのまま）
            if ($copied !== []) {
                $plan[$name] = ['sections' => array_slice($copied, 0, PageSpec::MAX_SECTIONS), 'dropped' => count($sections) - count($copied)];
            } elseif ($sections !== []) {
                $plan[$name] = ['sections' => [], 'dropped' => count($sections)];
            }
        }

        // 写す区画の無いページも、知らせ（dropped）のために入れる。書くのは区画のあるページだけ（copyFreePages）
        return $plan;
    }

    /**
     * 公開するページ: 下書きに値を保存したページ（テーマが宣言したページと自由なページ。ADR 0039 区切り 3）を、下書きの順に。
     * まだ保存していないページは公開の値にも入れない（読むときにテーマの最初の並びになるので、ショップの見た目は同じ）。
     * こうすると、変えていなければ公開と下書きが同じ形になり、「公開していない変更」の印が正しく消える。
     * 今の宣言に無いページ（テーマの更新で無くなったページ）は出さない。
     * ゴミ箱のページの値も残して出す（ショップは公開中のページだけを描くので出ない。戻したときに元の中身で出る）
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, PageSpec>
     */
    private static function pagesToPublish(array $draft, SectionSchema $schema): array
    {
        $pages = [];

        foreach (is_array($draft['pages'] ?? null) ? array_keys($draft['pages']) : [] as $name) {
            $spec = is_string($name) ? $schema->page($name) : null;

            if ($spec !== null) {
                $pages[$name] = $spec;
            }
        }

        return $pages;
    }

    /**
     * 宣言の中のページ。テーマに無いページは呼ぶ側の誤り
     */
    public static function pageSpec(SectionSchema $schema, string $page): PageSpec
    {
        return $schema->page($page) ?? throw new InvalidArgumentException("テーマに無いページです: {$page}");
    }

    /**
     * しまってある値（下書きか公開）の中のページの並び
     *
     * @param  array<string, mixed>|null  $document
     * @return list<Placed>
     */
    private static function pageOf(?array $document, SectionSchema $schema, PageSpec $page): array
    {
        $pages = is_array($document['pages'] ?? null) ? $document['pages'] : [];

        return SectionValues::sanitizePage($schema, $page, $pages[$page->name] ?? null);
    }

    /**
     * しまってある値（下書きか公開）の中の、ページの並びそのもの（読み直さない）
     *
     * @param  array<string, mixed>|null  $document
     */
    private static function storedPage(?array $document, string $page): mixed
    {
        return is_array($document['pages'] ?? null) ? ($document['pages'][$page] ?? null) : null;
    }

    /**
     * 今の宣言で置けない App の区画（店で止めた・外した App。ADR 0040）を、しまってある並びの同じ位置に戻す。
     * 値はしまってあったまま（前の保存で確かめ済み。DB の検査も通る）。ID が新しい並びと重なるもの・形の崩れたものは戻さない。
     * 決まった並びのページには App の区画を置けないので戻さない。戻した後の数が 1 ページの上限を越えるなら保存しない
     *
     * @param  list<Placed>  $placed
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function keepAppSections(array $placed, mixed $stored, PageSpec $page): array
    {
        if ($page->fixed || ! is_array($stored) || ! array_is_list($stored)) {
            return $placed;
        }

        $out = $placed;
        $ids = array_flip(array_column($placed, 'id'));

        foreach ($stored as $i => $entry) {
            $type = is_array($entry) ? ($entry['type'] ?? null) : null;
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;

            if (! is_string($type) || ! str_starts_with($type, SectionSchema::APP_PREFIX) || $page->allows($type)
                || ! is_string($id) || preg_match(SectionValues::SECTION_ID, $id) !== 1 || isset($ids[$id])) {
                continue;
            }

            array_splice($out, min($i, count($out)), 0, [$entry]);
            $ids[$id] = true;
        }

        if (count($out) > PageSpec::MAX_SECTIONS) {
            throw ValidationException::withMessages(['sections' => '使っていない App のセクションを含めて、1 つのページに置けるセクションは '.PageSpec::MAX_SECTIONS.' 個までです。']);
        }

        return $out;
    }

    /**
     * ページの並びを下書きに書く。$build はバージョンの行を押さえた後に、書く並びを返す（今の並びを見て写すため）
     *
     * @param  callable(ThemeVersion): list<Placed>  $build
     */
    private function write(string $theme, SectionSchema $schema, PageSpec $page, ?string $versionId, int $revision, ?string $actor, callable $build): ThemeVersion
    {
        return DB::transaction(function () use ($theme, $schema, $page, $versionId, $revision, $actor, $build): ThemeVersion {
            $this->events->lock();
            $version = $this->lockVersion($theme, $versionId, $revision, $actor);
            $placed = $build($version);
            // 止めた App の区画は画面に出ないので、送られてこない。消さずに元の位置へ戻す（ADR 0040）
            $kept = self::keepAppSections($placed, self::storedPage($version->draft, $page->name), $page);

            $document = $version->draft;
            $pages = is_array($document['pages'] ?? null) ? $document['pages'] : [];
            $pages[$page->name] = $kept;
            $document['pages'] = $pages;

            // DB と同じく UTF-8 のバイトで数える（日本語を \uXXXX で数えると上限の半分で断ってしまう）。ほかのページも含めたバージョンの全体で
            if (strlen((string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_BYTES) {
                throw ValidationException::withMessages(['sections' => '内容が多すぎます。文を短くするか、セクションを減らしてください。']);
            }

            // 写真は消されないよう押さえてから指す（消す側も EventStore::lock() を先に取るので順番になる）。
            // HTML の欄の中の写真（<img src="/media/shop/{ID}/…">）も同じロックの中で確かめる。無い写真（ほかの店の ID・消した写真）は
            // 黙って外さずに、その欄の道筋で断る（外すと、保存した後にショップで写真が消えたことに気づけない）
            $imageIds = SectionValues::pageImageIds($schema, $placed);
            $htmlImages = SectionValues::pageHtmlImageIds($schema, $placed);
            $wanted = array_values(array_unique([...$imageIds, ...array_merge([], ...array_column($htmlImages, 'ids'))]));
            $found = $wanted === [] ? [] : ShopMedia::query()->whereIn('id', $wanted)->lockForUpdate()->pluck('id')->all();
            $errors = array_diff($imageIds, $found) === [] ? [] : ['sections' => '選んだ写真の中に、「写真」の一覧に無いものがあります。写真を選び直してください。'];
            foreach ($htmlImages as $path => $html) {
                if (array_diff($html['ids'], $found) !== []) {
                    $errors[$path] = "「{$html['label']}」の中の写真に、「写真」の一覧に無いものがあります。写真を選び直すか、その写真（<img>）を削除してください。";
                }
            }

            // カテゴリも同じ。無いカテゴリ（消した後の古い画面から送った ID・ほかの店の ID）は、黙ってしまわずにその欄の道筋で断る
            // （しまうと、ショップでは黙って抜ける）。消す側（CategoryService::delete）も EventStore::lock() を先に取って区画の値を数えるので、
            // 確かめた後に消されることはない
            $categories = SectionValues::pageCategoryIds($schema, $placed);
            $wantedCategories = array_values(array_unique(array_merge([], ...array_column($categories, 'ids'))));
            $foundCategories = $wantedCategories === [] ? [] : Category::query()->whereIn('id', $wantedCategories)->pluck('id')->all();
            foreach ($categories as $path => $field) {
                if (array_diff($field['ids'], $foundCategories) !== []) {
                    $errors[$path] = "「{$field['label']}」で選んだカテゴリの中に、ショップに無いものがあります（削除したカテゴリかもしれません）。カテゴリを選び直してください。";
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $version->draft = $document;
            $version->revision++;
            $version->save();
            $this->events->append('theme.draft_saved', 'theme_version', $version->id, [
                'theme' => $theme, 'revision' => $version->revision, 'page' => $page->name, 'sections' => array_column($placed, 'type'),
            ], $actor);

            return $version;
        });
    }

    /**
     * バージョンの行を押さえる。無いバージョンを指した・改訂が違うなら StaleThemeVersionException。最初のバージョンはここで作る
     */
    private function lockVersion(string $theme, ?string $versionId, int $revision, ?string $actor): ThemeVersion
    {
        if ($versionId === null) {
            $current = ThemeVersion::query()->where('theme', $theme)->where('current', true)->lockForUpdate()->first();

            if ($current !== null) {
                throw new StaleThemeVersionException($current->id, $current->revision);
            }

            $version = ThemeVersion::create(['theme' => $theme, 'name' => self::DEFAULT_NAME, 'current' => true, 'draft' => ['pages' => (object) []], 'revision' => 0]);
            $this->events->append('theme.version_created', 'theme_version', $version->id, ['theme' => $theme], $actor); // バージョンの名前は店の人の自由記述になるので記録に入れない

            return $version;
        }

        $version = ThemeVersion::query()->whereKey(strtolower($versionId))->where('theme', $theme)->lockForUpdate()->first()
            ?? throw new StaleThemeVersionException(null, 0);

        if ($version->revision !== $revision) {
            throw new StaleThemeVersionException($version->id, $version->revision);
        }

        return $version;
    }
}
