<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * タグの App（ADR 0034、docs/design/tag-apps.md）の assets の JavaScript で、
 * 同じであるべき部品がバイトで同じかを見張る。
 *
 * 10 個の App は 1 つのタグに 1 つの App（ADR 0034）で、別々の composer パッケージなので、
 * 購入の値を読む部品の JavaScript を分け合う道が今の仕組みに無い（AppManifest はそのパッケージの assets の中だけを許す）。
 * 畳むには核に新しい口が要り、1.0 までは足さない（ADR 0098）ので、
 * **同じ形で書き写したものが黙って食い違わないこと**を、ここで機械で止める。
 * 片方だけ直すとこのテストが落ちるので、落ちたら「両方を同じに直す」が正しい直し方（畳むのは 1.0 の後）。
 *
 * 行番号では切らない。関数の頭（`function 名(`）と、同じ深さの閉じ括弧を探して抜き出し、
 * 抜き出せなければ例外を投げる（「黙って何も比べていない」状態を作らないため）。
 */
final class TagAppScriptsTest extends TestCase
{
    /** Google の 2 つの App で、この印からファイルの終わりまでが同じ（gtag.js の分け合い ＋ 購入の値） */
    private const string GOOGLE_SHARED_MARKER = '// ---- ここから下は Google のタグの App で同じ部品。';

    private const string GA4 = 'plugins/plugin-ga4/assets/ga4.js';

    private const string GOOGLE_ADS = 'plugins/plugin-google-ads/assets/google-ads.js';

    /** `purchase(values, sentPrefix)` が 1 文字も違わない 3 つ */
    private const array SAME_PURCHASE = [
        'plugins/plugin-tiktok-pixel/assets/tiktok-pixel.js',
        'plugins/plugin-pinterest-tag/assets/pinterest-tag.js',
        'plugins/plugin-microsoft-uet/assets/microsoft-uet.js',
    ];

    /** 上の 3 つと、戻り値から注文の番号を落とした 1 行だけが違う App（ピクセルに番号を渡す口が無い） */
    private const string PURCHASE_WITHOUT_NUMBER = 'plugins/plugin-smartnews-ads/assets/smartnews-ads.js';

    /** 購入の金額と品を読む App 全部（負の額と 0 個以下の品を送らないことを確かめる） */
    private const array PURCHASE_SCRIPTS = [
        self::GA4,
        self::GOOGLE_ADS,
        self::PURCHASE_WITHOUT_NUMBER,
        'plugins/plugin-tiktok-pixel/assets/tiktok-pixel.js',
        'plugins/plugin-pinterest-tag/assets/pinterest-tag.js',
        'plugins/plugin-microsoft-uet/assets/microsoft-uet.js',
        'plugins/plugin-meta-pixel/assets/meta-pixel.js',
        'plugins/plugin-x-pixel/assets/x-pixel.js',
        'plugins/plugin-lineyahoo-ads/assets/lineyahoo-ads.js',
    ];

    public function test_the_two_google_tag_apps_share_the_same_block(): void
    {
        $ga4 = self::sharedGoogleBlock(self::GA4);

        // 抜き出せているか（印からファイルの終わりまでに 3 つの関数が入っている）
        $this->assertStringContainsString('function googleTag(', $ga4);
        $this->assertStringContainsString('function startGoogleTag(', $ga4);
        $this->assertStringContainsString('function purchase(', $ga4);
        $this->assertGreaterThan(90, substr_count($ga4, "\n"), '同じ部品の切り出しが短すぎる（印の行を直したら、このテストの印も直す）');

        $this->assertSame(
            $ga4,
            self::sharedGoogleBlock(self::GOOGLE_ADS),
            'ga4.js と google-ads.js の「同じ部品」が食い違っている。片方だけ直さず、両方を同じに直す（docs/design/tag-apps.md）',
        );
    }

    public function test_the_three_pixel_apps_share_the_same_purchase(): void
    {
        $reference = self::purchaseOf(self::SAME_PURCHASE[0]);

        // 抜き出せているか（守りと二重送信の印が入った、45 行ほどの関数）
        $this->assertStringContainsString('minor < 0', $reference);
        $this->assertStringContainsString('item.quantity > 0', $reference);
        $this->assertStringContainsString('window.sessionStorage', $reference);
        $this->assertGreaterThan(35, substr_count($reference, "\n"), 'purchase() の切り出しが短すぎる');

        foreach (array_slice(self::SAME_PURCHASE, 1) as $relative) {
            $this->assertSame(
                $reference,
                self::purchaseOf($relative),
                $relative.' の purchase() が '.self::SAME_PURCHASE[0].' と食い違っている。両方を同じに直す',
            );
        }
    }

    public function test_the_smartnews_purchase_differs_only_in_the_returned_order_number(): void
    {
        $reference = self::purchaseOf(self::SAME_PURCHASE[0]);
        $withoutNumber = self::purchaseOf(self::PURCHASE_WITHOUT_NUMBER);

        // 許す違いは「戻り値から注文の番号を落とす」1 行だけ
        $expected = str_replace(
            'return { number: values.orderNumber, value:',
            'return { value:',
            $reference,
        );

        $this->assertNotSame($reference, $expected, '戻り値の行の形が変わった。このテストの許す違いも直す');
        $this->assertSame(
            $expected,
            $withoutNumber,
            self::PURCHASE_WITHOUT_NUMBER.' の purchase() は、ほかの 3 つと「戻り値の注文の番号」1 行だけが違う形に保つ',
        );

        // 違いが 1 行だけであることも数で確かめる（上の置き換えが空振りしていないこと）
        $differences = array_diff(
            explode("\n", $reference),
            explode("\n", $withoutNumber),
        );
        $this->assertCount(1, $differences);
    }

    public function test_every_tag_app_refuses_negative_totals_and_empty_quantities(): void
    {
        $missing = [];

        foreach (self::PURCHASE_SCRIPTS as $relative) {
            $code = self::source($relative);

            // 負の額を送らない（返金で負になった値や、印の作り替えが来ても送らない）
            if (preg_match('/\b(?:minor|total) (?:< 0|>= 0)\b/', $code) !== 1) {
                $missing[] = $relative.': 負の額の確認';
            }

            // 0 個以下の品を送らない
            if (! str_contains($code, 'item.quantity > 0')) {
                $missing[] = $relative.': 0 個以下の品の除き';
            }
        }

        $this->assertSame([], $missing, 'タグの App は負の額と 0 個以下の品を各社へ送らない（docs/design/tag-apps.md の「共通の作り」）');
    }

    public function test_the_extraction_notices_a_single_changed_character(): void
    {
        // わざと 1 文字だけ変えた写しを通して、切り出しと比べが本当に反応することを確かめる
        $broken = str_replace('|| minor < 0)', '|| minor < 1)', self::source(self::SAME_PURCHASE[0]));

        $this->assertNotSame(self::source(self::SAME_PURCHASE[0]), $broken);
        $this->assertNotSame(
            self::purchaseOf(self::SAME_PURCHASE[0]),
            self::extractFunction($broken, 'purchase', '1 文字変えた写し'),
        );
    }

    public function test_the_extraction_fails_loudly_when_the_function_is_moved_or_renamed(): void
    {
        $this->expectException(RuntimeException::class);

        self::extractFunction(self::source(self::GA4), 'purchaseTotals', 'ga4.js');
    }

    /** 「ここから下は同じ部品」の印から、ファイルの終わりまで */
    private static function sharedGoogleBlock(string $relative): string
    {
        $code = self::source($relative);
        $at = strpos($code, self::GOOGLE_SHARED_MARKER);

        if ($at === false) {
            throw new RuntimeException($relative.' に「同じ部品」の印が無い: '.self::GOOGLE_SHARED_MARKER);
        }

        return substr($code, $at);
    }

    private static function purchaseOf(string $relative): string
    {
        return self::extractFunction(self::source($relative), 'purchase', $relative);
    }

    /**
     * 関数を 1 つ抜き出す。`function 名(` の行から、同じ深さの閉じ括弧の行まで。
     * 行番号で切ると直したときに黙ってずれるので、頭と終わりを探す。
     * 見つからない・2 つある・括弧が釣り合わないときは例外（比べないまま通す状態を作らない）
     */
    private static function extractFunction(string $code, string $name, string $label): string
    {
        $lines = explode("\n", $code);
        $start = null;
        $indent = '';

        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)function '.preg_quote($name, '/').'\(/', $line, $found) !== 1) {
                continue;
            }

            if ($start !== null) {
                throw new RuntimeException($label.' に function '.$name.' が 2 つある');
            }

            $start = $index;
            $indent = $found[1];
        }

        if ($start === null) {
            throw new RuntimeException($label.' に function '.$name.' が無い');
        }

        $end = null;

        for ($index = $start + 1; $index < count($lines); $index++) {
            if ($lines[$index] === $indent.'}') {
                $end = $index;
                break;
            }
        }

        if ($end === null) {
            throw new RuntimeException($label.' の function '.$name.' の終わりが見つからない');
        }

        $block = implode("\n", array_slice($lines, $start, $end - $start + 1));

        if (substr_count($block, '{') !== substr_count($block, '}')) {
            throw new RuntimeException($label.' の function '.$name.' の切り出しで括弧が釣り合わない');
        }

        return $block;
    }

    private static function source(string $relative): string
    {
        $path = dirname(__DIR__, 2).'/'.$relative;
        $code = is_file($path) ? file_get_contents($path) : false;

        if ($code === false) {
            throw new RuntimeException($relative.' が読めない');
        }

        return $code;
    }
}
