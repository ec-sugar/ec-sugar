<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Money\Money;
use App\Storefront\Catalog\ProductView;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * お客さんに見せる金額の書き方（2026-10-30）。ショップ（テーマと核の部品）とメールの金額は、どこでも「¥5,400」の形。
 * 「5,400 円」と書かない。書き方は PHP の Money::format と JavaScript の resources/storefront/money.js の 1 か所ずつにまとめ、
 * テーマ・核の画面・メール・JavaScript は金額を手で整えない（「¥」や「円」を値の前後に手で書かない）。
 * 描いた画面に「円」が残らないことは ProductAddonsTest（商品の画面とカート、全テーマ）が見る
 */
final class PriceFormatTest extends TestCase
{
    /**
     * 金額を手で整える書き方（見つけたら落とす）。店の人が書く文（見本の値・theme.json の例など）は見ない
     *
     * @var array<string, string>
     */
    private const array FORBIDDEN = [
        '/¥\s*\{\{/u' => 'Blade で「¥」を手で書いている（核が渡す …Label を出す）',
        '/\}\}\s*円/u' => 'Blade の出力の後ろに「円」を書いている',
        '/\}\s*円/u' => 'JavaScript の埋め込みの後ろに「円」を書いている',
        '/[\'"`]\s*円/u' => '「円」を文字として足している',
        '/[\'"`]¥/u' => '「¥」を文字として足している（Money::format か money.js を使う）',
    ];

    public function test_shop_views_mail_and_scripts_do_not_format_money_by_hand(): void
    {
        $files = [];

        foreach ([base_path('plugins'), resource_path('views'), resource_path('storefront'), app_path('Storefront')] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $path = $file->getPathname();

                // テーマ・App の Blade と JavaScript、核の画面・メール・ショップの PHP。書き方そのもの（money.js）は除く
                if (preg_match('#/(node_modules|vendor)/#', $path) || $path === resource_path('storefront/money.js')) {
                    continue;
                }

                if (str_ends_with($path, '.blade.php') || str_ends_with($path, '.js') || (str_starts_with($path, app_path('Storefront')) && str_ends_with($path, '.php'))) {
                    $files[] = $path;
                }
            }
        }

        // 見張る範囲が空になっていない（場所が変わったら気づく）
        $this->assertContains(base_path('plugins/theme-default/resources/views/cart.blade.php'), $files);
        $this->assertContains(resource_path('views/mail/order.blade.php'), $files);
        $this->assertContains(resource_path('storefront/addons.js'), $files);
        $this->assertContains(app_path('Storefront/Catalog/ProductAddon.php'), $files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::FORBIDDEN as $pattern => $what) {
                $this->assertSame(0, preg_match($pattern, $source, $match), "{$file}: {$what}: ".($match[0] ?? ''));
            }
        }
    }

    public function test_server_side_amount_labels_use_the_yen_sign(): void
    {
        $this->assertSame('2 点で ¥5,400', ProductView::quantityTotalLabel(5400, 2, 'JPY'));
        $this->assertSame('1 点で ¥0', ProductView::quantityTotalLabel(0, 1, 'jpy'));
        // 円でない通貨に ¥ を付けない
        $this->assertSame('2 点で 54 USD', ProductView::quantityTotalLabel(54, 2, 'USD'));
    }

    /**
     * 商品の画面の合計を出す JavaScript（money.js）は、サーバーと同じ文字を作る。Node.js（ビルドの道具）で読み込んで比べる
     */
    public function test_the_shop_script_formats_amounts_exactly_like_the_server(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js が無い（ビルドの道具。CI のブラウザのテストの側で入る）');
        }

        $cases = [[0, 1, 'JPY'], [5400, 2, 'JPY'], [1234567, 3, 'jpy'], [-550, 1, 'JPY'], [1234, 2, 'USD']];
        $script = 'import { formatMoney, formatQuantityTotal } from '.json_encode('file://'.resource_path('storefront/money.js')).';'
            .'const cases = JSON.parse(process.argv[1]);'
            .'console.log(JSON.stringify(cases.map(([a, q, c]) => [formatMoney(a, c), formatQuantityTotal(a, q, c)])));';
        $result = Process::path(base_path())->run([$node, '--input-type=module', '-e', $script, json_encode($cases)]);
        $this->assertTrue($result->successful(), $result->errorOutput());

        $expected = array_map(static fn (array $c): array => [Money::format($c[0], $c[2]), ProductView::quantityTotalLabel($c[0], $c[1], $c[2])], $cases);
        $this->assertSame($expected, json_decode(trim($result->output()), true));
        $this->assertSame(['¥5,400', '2 点で ¥5,400'], $expected[1]);
    }
}
