<?php

declare(strict_types=1);

namespace App\Core\Theme\Services;

use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Sections\AppSection;
use App\Core\Section\Models\ShopPage;
use App\Core\Section\PageSpec;
use App\Core\Section\SectionSchema;
use App\Core\Section\SectionValues;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;

/**
 * テーマの区画の値でカテゴリを使っている所。カテゴリを消す前に、消すのと同じロックの中で数える（CategoryService::delete）。
 * 区画の値は外部キーで守れないので、写真の置き場（ShopMediaService::usageMap）と同じく、使っている物は消させない形にする。
 *
 * - 見るのは、この店のすべてのテーマ・すべてのバージョンの下書きと公開の、すべてのページ（トップ・自由なページ。ゴミ箱のページも）
 * - ID の場所は欄の種類で決める（SectionValues::categoryUses）。宣言はテーマの theme.json と核の区画と、入っている App の区画すべて
 *   （店で止めた App の区画も、値はしまってあり、また使えば出るので数える）。外したテーマは固有の区画の宣言が無いので、核と App の区画だけ見る
 * - 完全に消した自由なページ（店のページの行が無い）の値は、どこにも出ないので数えない（ページの ID は使い回さない）
 *
 * 書かない。ロックは呼ぶ側が持つ（区画を書く側も EventStore::lock() を先に取るので、数えた後に足されることはない）
 */
final readonly class ThemeCategoryUsage
{
    public function __construct(
        private ThemeRegistry $themes,
        private PluginRegistry $plugins,
    ) {}

    /**
     * そのカテゴリを使っている所。店の人に見せる形（『トップ』の『カテゴリ』（テーマ「kinari」・公開中だけ））で、今のテーマを先に
     *
     * @return list<array{place: string, published_only: bool}>
     */
    public function places(string $categoryId): array
    {
        $shopTheme = $this->themes->forTenant(Tenant::current())->name;
        $apps = array_map(static fn (AppSection $s) => $s->spec, $this->plugins->sections());
        $titles = ShopPage::withTrashed()->get(['id', 'title', 'deleted_at'])
            ->mapWithKeys(static fn (ShopPage $page): array => [$page->pageName() => [$page->title, $page->trashed()]])->all();
        $versions = ThemeVersion::query()->orderBy('theme')->orderByDesc('current')->orderBy('name')->get(['id', 'theme', 'name', 'current', 'draft', 'published'])
            ->sortBy(static fn (ThemeVersion $v): int => $v->theme === $shopTheme ? 0 : 1);
        $out = [];

        foreach ($versions as $version) {
            $schema = ($this->themes->has($version->theme) ? $this->themes->get($version->theme)->schema() : new SectionSchema([]))->withApps($apps);
            $draft = self::pages($version->draft);
            $published = self::pages($version->published);

            foreach (array_keys($draft + $published) as $name) {
                $name = (string) $name;
                $free = PageSpec::freeId($name) !== null;

                if ($free && ! isset($titles[$name])) {
                    continue;
                }

                $inDraft = SectionValues::categoryUses($schema, $draft[$name] ?? null, $categoryId);
                $inPublished = SectionValues::categoryUses($schema, $published[$name] ?? null, $categoryId);

                foreach (array_unique([...$inDraft, ...$inPublished]) as $section) {
                    $publishedOnly = ! in_array($section, $inDraft, true);
                    $notes = [
                        ...($free && $titles[$name][1] ? ['ゴミ箱の中のページ'] : []),
                        ...$this->themeNote($version, $shopTheme),
                        ...($publishedOnly ? ['公開中だけ'] : []),
                    ];
                    $page = $free ? $titles[$name][0] : ($schema->page($name)?->label() ?? $name);
                    $place = "『{$page}』の『{$section}』".($notes === [] ? '' : '（'.implode('・', $notes).'）');

                    if (! in_array($place, array_column($out, 'place'), true)) {
                        $out[] = ['place' => $place, 'published_only' => $publishedOnly];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * 今の店のテーマの使っているバージョンでなければ、どのテーマ（とバージョン）か
     *
     * @return list<string>
     */
    private function themeNote(ThemeVersion $version, string $shopTheme): array
    {
        if ($version->theme === $shopTheme && $version->current) {
            return [];
        }

        $label = $this->themes->has($version->theme) ? $this->themes->get($version->theme)->label : $version->theme;

        return ["テーマ「{$label}」".($version->current ? '' : "のバージョン「{$version->name}」")];
    }

    /**
     * 値の中のページ（名前 => しまってある並び）
     *
     * @param  array<string, mixed>|null  $document
     * @return array<array-key, mixed>
     */
    private static function pages(?array $document): array
    {
        return is_array($document['pages'] ?? null) ? $document['pages'] : [];
    }
}
