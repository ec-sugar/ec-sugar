<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * テーマの決まり（docs/design/theme-api.md）。テーマはテーマ API のバージョンを宣言し、DB に触らず、渡されたデータを表示するだけ。
 */
final class ThemeTest extends TestCase
{
    public function test_the_default_theme_is_installed_and_active(): void
    {
        $themes = app(ThemeRegistry::class);

        $this->assertContains('default', $themes->names());
        $this->assertSame('theme-default', $themes->active()->views);
        $this->assertSame(ThemeRegistry::API_VERSION, $themes->active()->themeApi);
    }

    public function test_themes_for_another_api_version_or_with_bad_names_are_refused(): void
    {
        $themes = new ThemeRegistry;

        foreach ([
            new ThemeDefinition('future', '先のバージョン', ThemeRegistry::API_VERSION + 1, 'future', 'x.css'),
            new ThemeDefinition('Bad Name', '名前が不正', ThemeRegistry::API_VERSION, 'bad', 'x.css'),
            new ThemeDefinition('../evil', '道筋', ThemeRegistry::API_VERSION, 'evil', 'x.css'),
        ] as $theme) {
            try {
                $themes->register($theme);
                $this->fail("{$theme->name} が登録できてしまった");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $manifest = base_path('tests/Support/Themes/sample/theme.json');
        $themes->register(new ThemeDefinition('ok', '良い', ThemeRegistry::API_VERSION, 'ok', 'x.css', manifest: $manifest));
        $this->expectException(InvalidArgumentException::class);
        $themes->register(new ThemeDefinition('ok', '二重', ThemeRegistry::API_VERSION, 'ok', 'x.css', manifest: $manifest));
    }

    /**
     * 自社のテーマのバージョン（theme_api）は、composer.json・composer.lock（CI と本番のイメージはこれから入れる）・登録の 3 か所でそろう。
     * テーマを移したのに composer update を忘れると、プラグインの一覧が古いバージョンを見せる（ADR 0028 区切り 2c で気づいた）
     */
    public function test_each_theme_declares_the_same_api_in_its_package_the_lock_and_the_registry(): void
    {
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
        $locked = array_column(array_filter($lock['packages'] ?? [], static fn (array $p): bool => ($p['extra']['ec-sugar']['type'] ?? null) === 'theme'), null, 'name');
        $themes = app(ThemeRegistry::class);
        $checked = 0;

        foreach (glob(base_path('plugins/theme-*/composer.json')) ?: [] as $file) {
            $package = json_decode((string) file_get_contents($file), true);
            $manifest = $package['extra']['ec-sugar'];
            $this->assertSame($manifest['theme_api'], $locked[$package['name']]['extra']['ec-sugar']['theme_api'] ?? null, "{$package['name']}: composer update {$package['name']} を走らせる");
            $this->assertSame($manifest['theme_api'], $themes->get($manifest['name'])->themeApi, $package['name']);
            $checked++;
        }

        $this->assertGreaterThanOrEqual(3, $checked);
    }

    /**
     * App の差し込み口（ADR 0034）: どのテーマも外枠に核の部品を 1 行ずつ置く（スクリプトと同意の帯・フッターのリンク）。
     * 核の部品もエスケープしない出力を使わない（HTML の文字を渡す口を作らない）
     */
    public function test_every_theme_layout_places_the_app_slots(): void
    {
        foreach (glob(base_path('plugins/theme-*/resources/views/layout.blade.php')) ?: [] as $layout) {
            $source = (string) file_get_contents($layout);
            $this->assertSame(1, substr_count($source, '<x-storefront.apps :apps="$apps" />'), $layout);
            $this->assertSame(1, substr_count($source, '<x-storefront.app-links :apps="$apps"'), $layout);
            // 検索と共有の見え方（ADR 0061）は核の部品が <head> に出す。テーマは自分で <title> を書かない
            $this->assertSame(1, substr_count($source, '<x-storefront::seo :seo="$seo">'), $layout);
            $this->assertStringNotContainsString('<title>', $source, $layout);
        }

        foreach ([...glob(base_path('resources/views/components/storefront/*.blade.php')) ?: [], ...glob(base_path('resources/views/storefront/*.blade.php')) ?: []] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\{!!|@php|<script>/', (string) file_get_contents($file), $file);
        }
    }

    /**
     * テーマの画面は表示だけ。DB やアプリの内部に触る書き方、エスケープしない出力、インラインの script・style、外部の読み込みを使わない。
     */
    public function test_theme_views_only_display_what_the_storefront_passes(): void
    {
        $forbidden = [
            '/\{!!/' => 'エスケープしない出力（{!! !!}）',
            '/@php|<\?php|<\?=/' => 'PHP のコード（テーマは表示だけ）',
            '/@inject|@service/' => 'サービスの取り出し',
            '/\bDB::|::query\(|App\\\\|\\\\Models\\\\|app\(|resolve\(/' => 'DB やアプリの内部への参照',
            '/<script\b/i' => 'script（CSP で止まる。JS は別ファイルで）',
            '/\sstyle=|<style\b/i' => 'インラインの style（CSP で止まる）',
            '/https?:\/\//' => '外部の URL（店頭の CSP は自分のオリジンだけ）',
        ];

        $files = 0;

        foreach (glob(base_path('plugins/*/resources/views')) ?: [] as $directory) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $source = (string) file_get_contents($file->getPathname());
                $files++;

                foreach ($forbidden as $pattern => $what) {
                    $this->assertSame(0, preg_match($pattern, $source, $match), "{$file->getPathname()}: {$what}: ".($match[0] ?? ''));
                }
            }
        }

        $this->assertGreaterThanOrEqual(4, $files);
    }

    /**
     * 店頭の核の JavaScript（resources/storefront。カート・同意の帯・種類を選ぶ部品）は、CSP と Trusted Types の下で動く書き方だけ。
     * HTML の文字列を差し込まず、文字列をコードとして実行せず、style 属性を文字で書かない（動きは CSSOM＝要素の style の項目 と Web Animations で付ける）。
     * 読み込むライブラリは決めたものだけ（引き出しの動きの Motion の小さい版。ADR 0037）で、動きは「視差効果を減らす」の設定を見る
     */
    public function test_core_storefront_scripts_stay_within_csp_and_trusted_types(): void
    {
        $forbidden = [
            '/\binnerHTML\b|\bouterHTML\b|insertAdjacentHTML|document\.write/' => 'DOM への HTML の書き込み',
            // 文字から HTML を組む口（区画の試し見の断片は、同じオリジンの署名つきの道筋を XMLHttpRequest で文書として読む）
            '/DOMParser|createContextualFragment|\bsrcdoc\b|setHTMLUnsafe|parseHTMLUnsafe/' => '文字から HTML を組む',
            '/\beval\s*\(|new\s+Function\s*\(|setTimeout\s*\(\s*[\'"`]|setInterval\s*\(\s*[\'"`]/' => '文字列をコードとして実行',
            '/\.cssText\b|setAttribute\(\s*[\'"]style[\'"]/' => 'style 属性を文字で書く（CSP の style-src で止まる）',
        ];
        // 自分のファイル（./）のほかに読んでよいもの
        $packages = ['motion/mini'];

        $files = glob(resource_path('storefront/*.js')) ?: [];
        $this->assertContains(resource_path('storefront/cart.js'), $files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($forbidden as $pattern => $what) {
                $this->assertSame(0, preg_match($pattern, $source, $match), "{$file}: {$what}: ".($match[0] ?? ''));
            }

            // 読んだ HTML を文書に組む口（XMLHttpRequest の responseType = 'document'）は、区画の試し見の editor.js だけ
            if (basename($file) !== 'editor.js') {
                $this->assertSame(0, preg_match('/responseType\s*=\s*[\'"]document[\'"]/', $source), "{$file}: 読んだ HTML を文書に組む");
            }

            preg_match_all('/^\s*import\b[^;]*?[\'"]([^\'"]+)[\'"]/m', $source, $imports);

            foreach ($imports[1] as $import) {
                $this->assertTrue(str_starts_with($import, './') || in_array($import, $packages, true), "{$file}: 決めていない読み込み: {$import}");
            }
        }

        // 動きは核の部品（motion.js）にまとめ、カート・種類・オプションの部品はそこから使う。動きを減らす設定はそこで見る
        $motion = (string) file_get_contents(resource_path('storefront/motion.js'));
        $this->assertStringContainsString("from 'motion/mini'", $motion);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $motion);

        foreach (['cart.js', 'variants.js', 'addons.js'] as $part) {
            $this->assertStringContainsString("from './motion.js'", (string) file_get_contents(resource_path("storefront/{$part}")), $part);
        }
    }

    /**
     * ショップの動き: どのテーマも外枠に強さ（theme.json の motion）を出し、核の CSS はそれを読む。
     * 動きの規則は「動きを減らす」を選んでいない時（no-preference）の中だけに置き、選んだ人には念を押して止める。
     * 中身を隠すのは JS が付けた印（data-motion-ready）がある時だけ。支払い・注文の入力と確認の画面には動きの印を置かない
     */
    public function test_every_theme_declares_its_motion_and_the_core_css_stops_for_reduced_motion(): void
    {
        foreach (glob(base_path('plugins/theme-*/resources/views/layout.blade.php')) ?: [] as $layout) {
            // 後ろに店の見た目の字と角の名前（ADR 0097）が続く
            $this->assertStringContainsString('<html lang="ja" data-motion="{{ $theme->motion }}" data-sugar-font=', (string) file_get_contents($layout), $layout);
        }

        $themes = app(ThemeRegistry::class);
        $this->assertSame(['default' => 'subtle', 'kinari' => 'subtle', 'fashion' => 'rich', 'botanica' => 'rich'], array_map(
            static fn (string $name): string => $themes->get($name)->motion,
            ['default' => 'default', 'kinari' => 'kinari', 'fashion' => 'fashion', 'botanica' => 'botanica'],
        ));

        foreach (glob(base_path('plugins/theme-*/resources/views/checkout*.blade.php')) ?: [] as $page) {
            $this->assertStringNotContainsString('data-motion-', (string) file_get_contents($page), $page);
        }

        $css = (string) file_get_contents(resource_path('storefront/motion.css'));
        $this->assertStringContainsString("import './motion.css';", (string) file_get_contents(resource_path('storefront/motion.js')));
        $blocks = $this->topLevelBlocks((string) preg_replace('#/\*.*?\*/#s', '', $css));
        $reduce = array_values(array_filter($blocks, static fn (array $block): bool => str_contains($block[0], 'prefers-reduced-motion: reduce')));
        $this->assertCount(1, $reduce);
        $this->assertStringContainsString('animation: none !important', $reduce[0][1]);
        $this->assertStringContainsString('transition: none !important', $reduce[0][1]);

        foreach ($blocks as [$prelude, $body]) {
            $moves = preg_match('/(?<![-\w])(animation|transition)\s*:\s*+(?!none\b)/', $body) === 1;
            $this->assertTrue(! $moves || str_contains($prelude, 'prefers-reduced-motion: no-preference'), "動きを減らす設定の外で動かしている: {$prelude}");

            // 隠す（opacity: 0）のは、JS の印のある時の規則だけ（@keyframes の中の出だしは除く）
            if (! str_starts_with($prelude, '@keyframes')) {
                preg_match_all('/([^{}]+)\{[^{}]*opacity:\s*0;/', $body, $hidden);

                foreach ($hidden[1] as $selector) {
                    $this->assertStringContainsString('[data-motion-ready]', $selector, '印なしで隠している');
                }
            }
        }
    }

    /**
     * CSS の一番外の塊（前置きと中身）。@media の中もそのまま中身に入る
     *
     * @return list<array{0: string, 1: string}>
     */
    private function topLevelBlocks(string $css): array
    {
        $blocks = [];
        $depth = 0;
        $start = 0;
        $open = 0;

        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            if ($css[$i] === '{') {
                if ($depth === 0) {
                    $open = $i;
                }

                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    $blocks[] = [trim(substr($css, $start, $open - $start)), substr($css, $open + 1, $i - $open - 1)];
                    $start = $i + 1;
                }
            }
        }

        $this->assertSame(0, $depth, 'motion.css の括弧が閉じていない');

        return $blocks;
    }

    /**
     * 店頭の核の部品（<x-storefront::…>。商品の説明のブロックなど。ADR 0032）も、テーマと同じ決まりで描く。
     * 店の人が書いた文を出す部品なので、エスケープしない出力・PHP のコード・インラインの script と style を使わない
     */
    public function test_core_storefront_parts_escape_everything_and_every_theme_uses_them_for_the_description(): void
    {
        $forbidden = [
            '/\{!!/' => 'エスケープしない出力（{!! !!}）',
            '/@php|<\?php|<\?=/' => 'PHP のコード（部品は表示だけ）',
            '/<script\b/i' => 'script（CSP で止まる）',
            '/\sstyle=|<style\b/i' => 'インラインの style（CSP で止まる）',
            '/https?:\/\//' => '外部の URL',
            '/@html|v-html|innerHTML/i' => 'HTML のまま出す書き方',
        ];

        $parts = glob(resource_path('views/storefront/components/*.blade.php')) ?: [];
        $this->assertContains(resource_path('views/storefront/components/description-blocks.blade.php'), $parts);

        foreach ($parts as $file) {
            $source = (string) file_get_contents($file);

            foreach ($forbidden as $pattern => $what) {
                $this->assertSame(0, preg_match($pattern, $source, $match), "{$file}: {$what}: ".($match[0] ?? ''));
            }
        }

        // 公式テーマの商品の詳細は、説明と仕様の表を核の部品で描き、種類とオプションも核の部品で選ばせる（テーマごとに写しを持たない。ADR 0036）
        foreach (glob(base_path('plugins/theme-*/resources/views/product.blade.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringContainsString('<x-storefront::description-blocks :blocks="$product->blocks"', $source, $file);
            $this->assertStringContainsString('<x-storefront::product-specs :specs="$product->specs"', $source, $file);
            $this->assertStringContainsString('<x-storefront::variant-picker :product="$product"', $source, $file);
            $this->assertStringContainsString('<x-storefront::addon-fields :product="$product"', $source, $file);
        }

        // 一覧の札は、札の外枠（.sugar-card）の中、リンクの外に App の札のボタン（お気に入りのハートなど）を核の部品で置く（ADR 0071）
        foreach (glob(base_path('plugins/theme-*/resources/views/partials/product-card.blade.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringContainsString('<div class="sugar-card">', $source, $file);
            $this->assertStringContainsString('<x-storefront::card-options :listing="$listing->id"', $source, $file);
            $this->assertGreaterThan(strrpos($source, '</a>'), strpos($source, '<x-storefront::card-options'), "{$file}: 札のボタンが札のリンクの中にある");
        }

        // 確かめの画面とご注文の確認は、税率ごとの内訳を核の部品で出す（ADR 0063）
        foreach (glob(base_path('plugins/theme-*/resources/views/checkout-confirm.blade.php')) ?: [] as $file) {
            $this->assertStringContainsString('<x-storefront::tax-breakdown :taxes="$order->cart->taxBreakdown()"', (string) file_get_contents($file), $file);
        }

        foreach (glob(base_path('plugins/theme-*/resources/views/order.blade.php')) ?: [] as $file) {
            $this->assertStringContainsString('<x-storefront::tax-breakdown :taxes="$order->taxes"', (string) file_get_contents($file), $file);
        }

        // ポイント（ADR 0068）: 使う欄・使った行・付く一言・付く予定を核の部品で（テーマで計算しない）
        foreach ([
            'checkout' => ['<x-storefront::points-fields :form="$form"'],
            'checkout-confirm' => ['<x-storefront::points-used :points="$order->cart->points"', '<x-storefront::points-note :points="$order->cart->points"'],
            'cart' => ['<x-storefront::points-note :points="$cart->points"'],
            'product' => ['<x-storefront::product-points :points="$productPoints ?? null"'],
            'order' => ['<x-storefront::order-points :points="$order->points"'],
        ] as $view => $needles) {
            foreach (glob(base_path("plugins/theme-*/resources/views/{$view}.blade.php")) ?: [] as $file) {
                foreach ($needles as $needle) {
                    $this->assertStringContainsString($needle, (string) file_get_contents($file), $file);
                }
            }
        }

        // ギフトカード（ADR 0076）: 使う欄・お支払いの行・注文の一言を核の部品で（テーマで計算しない。合計は変えず、お支払いの額は核が出す）
        foreach ([
            'checkout' => ['<x-storefront::gift-card-fields :form="$form"'],
            'checkout-confirm' => ['<x-storefront::gift-card-used :cart="$order->cart"'],
            'order' => ['<x-storefront::order-gift-card :gift-card="$order->giftCard"'],
        ] as $view => $needles) {
            foreach (glob(base_path("plugins/theme-*/resources/views/{$view}.blade.php")) ?: [] as $file) {
                foreach ($needles as $needle) {
                    $this->assertStringContainsString($needle, (string) file_get_contents($file), $file);
                }
            }
        }

        // App の料金（ラッピング・のしなど。ADR 0078）: 欄・料金の行・選んだ内容を核の部品で（テーマで額を組み立てない）
        foreach ([
            'checkout' => ['<x-storefront::charge-fields :form="$form"'],
            'checkout-confirm' => ['<x-storefront::charges-used :cart="$order->cart"', '<x-storefront::charges-summary :items="$order->charges"'],
        ] as $view => $needles) {
            foreach (glob(base_path("plugins/theme-*/resources/views/{$view}.blade.php")) ?: [] as $file) {
                foreach ($needles as $needle) {
                    $this->assertStringContainsString($needle, (string) file_get_contents($file), $file);
                }
            }
        }
    }
}
