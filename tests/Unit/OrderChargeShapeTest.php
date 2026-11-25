<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Order\OrderCharges;
use App\Core\Plugin\OrderCharge\ChargeField;
use App\Core\Plugin\OrderCharge\ChargeForm;
use App\Core\Plugin\OrderCharge\ChargeQuote;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * App が注文に足す料金の形（ADR 0078）。核の行の種類を使えない・額は 0〜1,000,000 の整数・名前は制御文字の無い 1 行。
 * App が誤った・悪い行を返しても、注文の行にならない（核が断る）
 */
final class OrderChargeShapeTest extends TestCase
{
    /**
     * @return array<string, array{0: list<array{kind: string, title: string, amount: int}>}>
     */
    public static function badLines(): array
    {
        return [
            '送料の種類' => [[['kind' => 'shipping', 'title' => '送料', 'amount' => 100]]],
            '値引きの種類' => [[['kind' => 'discount', 'title' => '値引き', 'amount' => 0]]],
            'ポイントの種類' => [[['kind' => 'points', 'title' => 'ポイント', 'amount' => 0]]],
            '代引き手数料の種類' => [[['kind' => 'cod_fee', 'title' => '手数料', 'amount' => 330]]],
            'オプションの種類' => [[['kind' => 'addon', 'title' => 'オプション', 'amount' => 330]]],
            '負の額' => [[['kind' => 'wrapping', 'title' => 'ラッピング', 'amount' => -1]]],
            '上限を超える額' => [[['kind' => 'wrapping', 'title' => 'ラッピング', 'amount' => ChargeQuote::AMOUNT_MAX + 1]]],
            '形の違う種類' => [[['kind' => 'Wrap Ping', 'title' => 'ラッピング', 'amount' => 0]]],
            '重ねた種類' => [[['kind' => 'wrapping', 'title' => 'a', 'amount' => 0], ['kind' => 'wrapping', 'title' => 'b', 'amount' => 0]]],
            '改行のある名前' => [[['kind' => 'wrapping', 'title' => "ラッピング\nほか", 'amount' => 0]]],
            '空の名前' => [[['kind' => 'wrapping', 'title' => ' ', 'amount' => 0]]],
            '行が多すぎる' => [array_map(static fn (int $i): array => ['kind' => 'charge_'.$i, 'title' => 'x', 'amount' => 0], range(1, ChargeQuote::LINES_MAX + 1))],
        ];
    }

    /**
     * @param  list<array{kind: string, title: string, amount: int}>  $lines
     */
    #[DataProvider('badLines')]
    public function test_bad_lines_are_refused(array $lines): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChargeQuote($lines);
    }

    /**
     * @return array<string, array{0: array<mixed>, 1: array<mixed>}>
     */
    public static function badShapes(): array
    {
        return [
            '額が文字' => [[['kind' => 'wrapping', 'title' => 'x', 'amount' => '330']], []],
            '額が小数' => [[['kind' => 'wrapping', 'title' => 'x', 'amount' => 3.5]], []],
            '種類が数' => [[['kind' => 1, 'title' => 'x', 'amount' => 0]], []],
            '名前が配列' => [[['kind' => 'wrapping', 'title' => ['x'], 'amount' => 0]], []],
            '鍵が足りない' => [[['kind' => 'wrapping', 'amount' => 0]], []],
            '鍵が多い' => [[['kind' => 'wrapping', 'title' => 'x', 'amount' => 0, 'tax_rate_bp' => 0]], []],
            '行が配列でない' => [['wrapping'], []],
            '並びでない' => [['a' => ['kind' => 'wrapping', 'title' => 'x', 'amount' => 0]], []],
            '要約の値が数' => [[], [['name' => '名入れ', 'value' => 1]]],
            '要約の鍵が違う' => [[], [['label' => '名入れ', 'value' => 'x']]],
        ];
    }

    /**
     * App の答えの型がそろっていない（PHP の型の決まりに頼らず、核が確かめる）
     *
     * @param  array<mixed>  $lines
     * @param  array<mixed>  $summary
     */
    #[DataProvider('badShapes')]
    public function test_answers_of_the_wrong_shape_are_refused(array $lines, array $summary): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChargeQuote($lines, $summary);
    }

    /**
     * 核と公式の App が作る行の種類は、どれも 1 つの一覧（ChargeQuote::CORE_KINDS）にあり、App の料金に使えず、料金の行として出すのは代引き手数料だけ
     */
    public function test_every_line_kind_the_core_makes_is_in_the_one_list(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [...$this->phpFiles($root.'/app'), ...$this->phpFiles($root.'/plugins')];
        $found = [];

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all("/LineDraft::adjustment\\('([a-z_]+)'/", $code, $adjustments);
            preg_match_all("/new LineDraft\\(LineType::[A-Za-z]+,[^;]*?'([a-z_]+)'\\);/", $code, $constructed);
            $found = [...$found, ...$adjustments[1], ...$constructed[1], ...(str_contains($code, 'LineDraft::addon(') ? ['addon'] : [])];
        }

        $found = array_values(array_unique($found));
        sort($found);
        // 見張りが空振りしていない（知っている種類が見つかる）
        $this->assertContains('cod_fee', $found);
        $this->assertContains('gift_card', $found);

        foreach ($found as $kind) {
            $this->assertContains($kind, ChargeQuote::CORE_KINDS, "核の行の種類 {$kind} が ChargeQuote::CORE_KINDS に無い");
            $this->assertContains($kind, ChargeQuote::RESERVED_KINDS);
            $this->assertSame(in_array($kind, ChargeQuote::CHARGE_KINDS, true), OrderCharges::shownAsCharge($kind), $kind);
        }

        $this->assertTrue(OrderCharges::shownAsCharge('wrapping'));
        $this->assertFalse(OrderCharges::shownAsCharge(null));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/vendor/') && ! str_contains($file->getPathname(), '/node_modules/')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    public function test_good_lines_make_a_fingerprint_and_free_lines_read_free(): void
    {
        $quote = new ChargeQuote([['kind' => 'wrapping', 'title' => 'ラッピング（リボン）', 'amount' => 330], ['kind' => 'noshi', 'title' => 'のし（御祝・外のし）', 'amount' => 0]], [['name' => '名入れ', 'value' => '鈴木一郎']], 'v1:abc');
        $this->assertSame(330, $quote->total());
        $this->assertSame('無料', ChargeQuote::amountLabel(0));
        $this->assertNotSame('', $quote->fingerprint());
        // 名前・額・要約のどれが違っても指紋が変わる
        $this->assertNotSame($quote->fingerprint(), (new ChargeQuote([['kind' => 'wrapping', 'title' => 'ラッピング（箱）', 'amount' => 330], ['kind' => 'noshi', 'title' => 'のし（御祝・外のし）', 'amount' => 0]], [], 'v1:abc'))->fingerprint());
        $this->assertNotSame($quote->fingerprint(), (new ChargeQuote($quote->lines, [], 'v1:abd'))->fingerprint());
        $this->assertSame('', ChargeQuote::none()->fingerprint());
    }

    public function test_fields_are_only_selects_and_short_text(): void
    {
        foreach ([
            static fn () => new ChargeField('wrapping', 'checkbox', 'x', options: ['' => 'しない']),
            static fn () => new ChargeField('Wrapping', 'select', 'x', options: ['' => 'しない']),
            static fn () => new ChargeField('wrapping', 'select', 'x'),
            static fn () => new ChargeField('wrapping', 'select', 'x', options: ['<b>' => '太字']),
            static fn () => new ChargeField('name', 'text', 'x', maxLength: 0),
            static fn () => new ChargeField('name', 'text', 'x', maxLength: 201),
            static fn () => new ChargeForm('包装', [new ChargeField('a', 'text', 'x', maxLength: 5), new ChargeField('a', 'text', 'y', maxLength: 5)]),
        ] as $i => $make) {
            try {
                $make();
                $this->fail("{$i} 番目を断らなかった");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
