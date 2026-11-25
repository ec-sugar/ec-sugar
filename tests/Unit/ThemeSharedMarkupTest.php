<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 公式テーマの共通の画面は、見た目（class）を除くと 4 テーマで同じ markup である。
 *
 * 購入まわりと商品の画面は、どのテーマでも同じ段取り・同じ部品・同じ data-test で動く。実際に 2026-11-19 の時点で
 * 下の 9 画面は class を潰すと 1 バイト違わず同一で、3.5 週間のあいだに約 100 回「同じ編集を 4 テーマへ」入れている。
 * 写しが 4 枚あること自体は ADR 0028 の区切り 3（購入まわりを核へ）まで残るが、**黙って食い違うのは別の問題**で、
 * 既定テーマが定義していない色（bg-subtle）を使っていた不具合はこの食い違いから出た。
 *
 * ここで止めるのは「片方だけ直した」状態。テーマごとの個性は class と layout・home・page・sections/・partials/・CSS に残る。
 *
 * 画面を本当にテーマごとに分けたくなったら、SHARED から外して理由をここに書く（黙って外さない）。
 */
final class ThemeSharedMarkupTest extends TestCase
{
    /**
     * 見た目を除くと 4 テーマで同じであるべき画面。
     *
     * @var list<string>
     */
    private const array SHARED = [
        'cart', 'checkout', 'checkout-confirm', 'checkout-complete',
        'error', 'legal', 'order-lookup', 'order', 'product',
    ];

    /**
     * テーマごとに違ってよい画面と、その理由（SHARED に入れない覚え書き）。
     *
     * @var array<string, string>
     */
    private const array THEME_OWN = [
        'layout' => 'テーマの骨組み（頭と足、枠の保存先所）',
        'home' => 'トップはセクションの並べ方そのものがテーマの個性',
        'page' => '自由なページの地組み',
        'password' => '準備中の見せ方（既定はロゴの積み木、fashion は COMING SOON）',
        'products' => '一覧の検索の出し方が既定だけ文字（ほかはアイコン）',
    ];

    public function test_the_shared_pages_are_the_same_markup_in_every_official_theme(): void
    {
        $themes = $this->themes();
        $this->assertGreaterThanOrEqual(2, count($themes), '公式テーマが 2 つ以上見つからない（探し方が壊れている）');

        foreach (self::SHARED as $page) {
            $normalised = [];

            foreach ($themes as $theme) {
                $file = $this->root()."/plugins/{$theme}/resources/views/{$page}.blade.php";
                // 画面が無いのに黙って飛ばさない（名前を変えたら気づけるように）
                $this->assertFileExists($file, "{$theme} に {$page} の画面が無い（SHARED から外すなら THEME_OWN に理由を書く）");
                $normalised[$theme] = $this->withoutLooks((string) file_get_contents($file), $theme);
            }

            $first = array_key_first($normalised);

            foreach ($normalised as $theme => $markup) {
                $this->assertSame(
                    $normalised[$first],
                    $markup,
                    "{$page} の markup が {$first} と {$theme} で違う。見た目（class）以外を変えたなら 4 テーマとも同じに直す"
                );
            }
        }
    }

    public function test_every_official_theme_is_covered_and_the_lists_do_not_overlap(): void
    {
        // テーマが増えたら自動で対象になる（一覧を手で書かない）
        $this->assertContains('theme-default', $this->themes());

        $both = array_intersect(self::SHARED, array_keys(self::THEME_OWN));
        $this->assertSame([], $both, '同じ画面が SHARED と THEME_OWN の両方にある');

        foreach (self::THEME_OWN as $page => $why) {
            $this->assertNotSame('', $why, "{$page} を SHARED から外した理由が空");
        }
    }

    /**
     * 見た目（class の中身）と空白の違いを消す。テーマの名前空間（theme-kinari:: など）も揃える
     */
    private function withoutLooks(string $blade, string $theme): string
    {
        $blade = (string) preg_replace('/class="[^"]*"/', 'class=""', $blade);
        $blade = (string) preg_replace('/\s+/', ' ', $blade);

        return trim(str_replace($theme.'::', 'THEME::', $blade));
    }

    /**
     * @return list<string>
     */
    private function themes(): array
    {
        $found = [];

        foreach (glob($this->root().'/plugins/theme-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $found[] = basename($dir);
        }

        sort($found);

        return $found;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
