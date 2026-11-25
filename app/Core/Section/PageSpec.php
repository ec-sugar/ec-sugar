<?php

declare(strict_types=1);

namespace App\Core\Section;

/**
 * ページの宣言（ADR 0039）: そのページに置ける区画の種類と、最初の並び（既定の並び）。
 *
 * - 店の人は、置ける種類の区画を何度でも足し・消し・並べ替え・パターンを選び直せる（種類ごとの limit まで）
 * - fixed（決まった並び）は、theme.json に pages を書かない今の書き方のテーマ。宣言した区画を宣言の順に 1 つずつ置いたものとして読み、
 *   足す・消す・並べ替えはできない（テーマの画面が区画を名前で読むので、並びを変えても見た目に出ない）。値と隠す・出すだけ変えられる
 * - 核が知っているページは PAGES（トップ）と、自由なページ（ADR 0039 区切り 3。私たちについて など、店の人が作るページ）。
 *   自由なページはテーマが pages.page で 1 つの雛形（置ける種類と最初の並び）を宣言し、店の人が作ったページはどれもその雛形を使う。
 *   宣言しないテーマでは、核の汎用の区画（CoreSections）を置ける。
 *   自由なページの名前は「page-」と店のページ（shop_pages）の ID（小文字の ULID）。値の置き場の名前も同じ
 */
final readonly class PageSpec
{
    /** 核が知っているページ（名前 => 管理画面に出す名前） */
    public const array PAGES = ['home' => 'トップ'];

    /** theme.json の pages に書く、自由なページの雛形の名前 */
    public const string FREE = 'page';

    /** 自由なページの名前の頭 */
    public const string FREE_PREFIX = 'page-';

    private const string FREE_NAME = '/\Apage-([0-9a-hjkmnp-tv-z]{26})\z/';

    /** 1 つのページに置ける区画の数（核の上限） */
    public const int MAX_SECTIONS = 40;

    /**
     * @param  list<string>  $types  置ける区画の種類
     * @param  list<array{type: string, pattern: string}>  $default  最初の並び
     * @param  bool  $free  自由なページ（店の人が作ったページ）か
     * @param  string|null  $title  画面に出す名前（自由なページの題）
     */
    public function __construct(
        public string $name,
        public array $types,
        public array $default,
        public bool $fixed = false,
        public bool $free = false,
        public ?string $title = null,
    ) {}

    /**
     * 自由なページの名前（店のページの ID から）
     */
    public static function freeName(string $id): string
    {
        return self::FREE_PREFIX.strtolower($id);
    }

    /**
     * 自由なページの名前なら、店のページの ID（小文字の ULID）。違えば null
     */
    public static function freeId(string $name): ?string
    {
        return preg_match(self::FREE_NAME, $name, $match) === 1 ? $match[1] : null;
    }

    /**
     * 雛形（テーマの pages.page か核の汎用の並び）から、店のページの宣言を作る
     */
    public function forFreePage(string $name, ?string $title = null): self
    {
        return new self($name, $this->types, $this->default, free: true, title: $title);
    }

    /**
     * 置ける種類を替えた宣言（店で使う App の区画を足すとき。ADR 0040）
     *
     * @param  list<string>  $types
     */
    public function withTypes(array $types): self
    {
        return new self($this->name, $types, $this->default, $this->fixed, $this->free, $this->title);
    }

    public function withTitle(?string $title): self
    {
        return new self($this->name, $this->types, $this->default, $this->fixed, $this->free, $title);
    }

    public function label(): string
    {
        return $this->title ?? self::PAGES[$this->name] ?? $this->name;
    }

    public function allows(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /**
     * 管理画面に渡す形
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label(),
            'fixed' => $this->fixed,
            'free' => $this->free,
            'types' => $this->types,
            'default' => $this->default,
            'max_sections' => self::MAX_SECTIONS,
        ];
    }
}
