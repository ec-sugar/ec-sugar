<?php

declare(strict_types=1);

namespace App\Core\Theme;

use LogicException;

/**
 * 店の見た目の値から作る小さな CSS（ADR 0097）。ショップはテーマの CSS の後にこれを読む（<x-storefront::theme-style />）。
 *
 * - 中身は `:root{--color-<名前>:#rrggbb;…}` だけ。名前はテーマの宣言の色の名前（ThemeStyle::NAME の形に限る）、値は確かめた #rrggbb と
 *   核が計算した色（StyleColors）だけ。店の人の文字をそのまま CSS に入れない（CSS の注入を作らない）。書く前にもう一度形を確かめ、外れたら止める
 * - 組の色を宣言の順に書き、主な色（店の色か組の色）と、そこから計算したボタンの文字の色・押したときの色を最後に書く
 * - 層（@layer）の外に書くので、テーマの @theme（層の中）より強い
 * - 字の組と角の丸みは CSS に書かない（<html data-sugar-font・data-sugar-radius> で渡し、テーマの CSS が受ける）
 * - 印（mark）は中身の sha256 の頭 16 文字。中身が変われば URL が変わるので、ショップは長くキャッシュできる
 */
final readonly class ThemeStyleSheet
{
    private const string VALUE = '/\A#[0-9a-f]{6}\z/';

    /**
     * @param  array{palette: string, accent: string|null, font: string|null, radius: string|null}  $values  今の宣言で読み直した値（ThemeStyle::resolve）
     */
    public function __construct(
        public ThemeStyle $style,
        public array $values,
    ) {}

    /**
     * テーマの CSS のままでよいか（最初の組で、主な色も組のまま）。それなら ショップは CSS を読まない
     */
    public function isDefault(): bool
    {
        return $this->values['palette'] === array_key_first($this->style->palettes) && $this->values['accent'] === null;
    }

    /**
     * 主な色（店の色か、組の色）
     */
    public function accent(): string
    {
        return $this->values['accent'] ?? $this->colors()['accent'];
    }

    /**
     * 組の地の色
     */
    public function canvas(): string
    {
        return $this->colors()['canvas'];
    }

    /**
     * 読みにくいかもしれない組み合わせの知らせ（止めない）
     *
     * @return list<array{code: string, ratio: float, message: string}>
     */
    public function warnings(): array
    {
        return StyleColors::warnings($this->accent(), $this->canvas());
    }

    public function css(): string
    {
        $accent = $this->accent();
        // 組の色を先に、主な色と計算した色を後に
        $ordered = array_diff_key($this->colors(), ['accent' => true])
            + ['accent' => $accent, 'on-accent' => StyleColors::onAccent($accent), 'accent-hover' => StyleColors::hover($accent)];
        $out = '';

        foreach ($ordered as $name => $value) {
            if (preg_match(ThemeStyle::NAME, $name) !== 1 || preg_match(self::VALUE, $value) !== 1) {
                throw new LogicException('見た目の CSS に、確認していない名前か値が入りかけました');
            }

            $out .= "--color-{$name}:{$value};";
        }

        return ":root{{$out}}\n";
    }

    public function mark(): string
    {
        return substr(hash('sha256', $this->css()), 0, 16);
    }

    /**
     * @return array<string, string>
     */
    private function colors(): array
    {
        return $this->style->palettes[$this->values['palette']]['colors'];
    }
}
