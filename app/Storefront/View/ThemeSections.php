<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Core\Section\SectionSchema;
use App\Core\Section\SectionSpec;
use App\Core\Section\SectionValues;
use Closure;
use LogicException;

/**
 * テーマの区画の中身（テーマ API 2 の $sections。ADR 0028）。すべての画面に渡す。
 * 区画は使うときに初めて組み立てる（カートの画面などで、使わない商品の並びを読まない）。
 *
 * 中身はトップ（home）の公開した並び（ADR 0039）。get は、その種類の最初の区画を返す:
 * 店の人が隠した区画・並びから外した種類なら null、まだ何も入れていない区画なら空の SectionView（テーマは見本の文で埋めてよい）。
 * 宣言に無い区画の名前はテーマの書き間違いなので LogicException。
 * page はトップか自由なページの並びそのもの（PageView。並びの順に、部品で描く区画だけ）。
 *
 * @phpstan-import-type Placed from SectionValues
 */
final class ThemeSections
{
    /** @var array<string, SectionView> ページの名前と区画の ID => 組み立てたもの */
    private array $resolved = [];

    /** @var array<string, PageView> */
    private array $pages = [];

    /**
     * @param  list<Placed>  $home  トップの公開した並び（宣言で読み直したもの）
     * @param  Closure(SectionSpec, Placed): SectionView  $resolve
     * @param  Closure(string, string): ?string  $partial  種類とパターンから、部品の名前（無ければ null）
     * @param  (Closure(string): list<Placed>)|null  $load  トップ以外のページ（自由なページ）の並びを読む
     */
    public function __construct(
        private readonly SectionSchema $schema,
        private readonly array $home,
        private readonly Closure $resolve,
        private readonly Closure $partial,
        private readonly ?Closure $load = null,
    ) {}

    public function get(string $key): ?SectionView
    {
        $spec = $this->schema->section($key) ?? throw new LogicException("セクション {$key} はありません（theme.json の宣言を確認する）");

        foreach ($this->home as $section) {
            if ($section['type'] === $key) {
                // まだ何も入れていない区画も同じ組み立てを通す（商品の並びは []、カテゴリの並びは一番上の段になる）
                return $section['hidden'] ? null : $this->build('home', $spec, $section);
            }
        }

        return null;
    }

    /**
     * ページの並び（トップと、自由なページ page-{ID}。ADR 0039 区切り 3）
     */
    public function page(string $name = 'home'): PageView
    {
        if (isset($this->pages[$name])) {
            return $this->pages[$name];
        }

        if ($name !== 'home' && ($this->load === null || $this->schema->page($name) === null)) {
            throw new LogicException("ページ {$name} はありません");
        }

        $placed = $name === 'home' || $this->load === null ? $this->home : ($this->load)($name);
        $sections = [];

        foreach ($placed as $section) {
            $spec = $this->schema->section($section['type']);
            $view = $spec === null || $section['hidden'] ? null : ($this->partial)($section['type'], $section['pattern']);

            if ($spec === null || $view === null) {
                continue;
            }

            $built = $this->build($name, $spec, $section)->withView($view);

            // App の区画（ADR 0040）は、ショップでは設定がそろって埋め込むものがあるときだけ出す（空の箱を出さない）
            if ($built->app === null || $built->app->visible()) {
                $sections[] = $built;
            }
        }

        return $this->pages[$name] = new PageView($name, $sections);
    }

    /**
     * @param  Placed  $section
     */
    private function build(string $page, SectionSpec $spec, array $section): SectionView
    {
        // 区画の ID はページの中でだけ重ならないので、ページの名前と組にして覚える
        return $this->resolved["{$page}:{$section['id']}"] ??= ($this->resolve)($spec, $section);
    }
}
