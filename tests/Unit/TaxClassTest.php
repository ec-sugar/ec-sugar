<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\TaxRounding;
use App\Core\Transaction\TaxBreakdown;
use App\Core\Transaction\Totals;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 消費税の区分と率の表、率ごとの内訳（ADR 0063）
 */
final class TaxClassTest extends TestCase
{
    public function test_the_rate_comes_from_the_class_and_shipping_is_standard(): void
    {
        $this->assertSame(1000, TaxClass::Standard->rateBp());
        $this->assertSame(800, TaxClass::Reduced->rateBp());
        $this->assertSame(TaxClass::Standard, TaxClass::SHIPPING);
        $this->assertTrue(TaxClass::isReducedRate(800));
        $this->assertFalse(TaxClass::isReducedRate(1000));
        $this->assertFalse(TaxClass::isReducedRate(0));
        $this->assertSame(['8%', '10%', '8.25%'], [TaxClass::rateLabel(800), TaxClass::rateLabel(1000), TaxClass::rateLabel(825)]);
        $this->assertSame('軽減 8%（食品・飲み物）', TaxClass::Reduced->label());
    }

    /**
     * 率を 2 か所に持たない。前の設定（storefront.tax_rate_bp）を読む所が残ると、値が無くて 0% になり気づけない
     */
    public function test_nothing_reads_the_old_single_rate_setting(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach (['app', 'plugins', 'config', 'resources/views'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$directory}", \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/vendor/')) {
                    continue;
                }

                if (preg_match("/['\"]storefront\.tax_rate_bp['\"]|['\"]tax_rate_bp['\"]\s*=>\s*\d/", (string) file_get_contents($file->getPathname())) === 1) {
                    $found[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $found, '税率は商品の区分から核が決める（App\\Core\\Tax\\TaxClass）');
    }

    /**
     * 率ごとに合計してから 1 回だけ切り捨てる。境（8% の 10,800 円・10% の 11,000 円）とその 1 円下
     */
    public function test_the_breakdown_rounds_once_per_rate_at_the_boundaries(): void
    {
        $this->assertSame([[800, 10800, 800], [1000, 11000, 1000]], self::rows(TaxBreakdown::ofAmounts([1000 => 11000, 800 => 10800])));
        $this->assertSame([[800, 10799, 799], [1000, 10999, 999]], self::rows(TaxBreakdown::ofAmounts([800 => 10799, 1000 => 10999])));
        // 148 円の 2 行は、1 行ごとなら 10 + 10 = 20 円、率ごとなら 296 円で 21 円
        $this->assertSame(21, Totals::ofAmounts([800 => 296], true, TaxRounding::Floor)->tax);
        $this->assertSame(20, Totals::ofAmounts([800 => 148], true, TaxRounding::Floor)->tax * 2);
    }

    public function test_the_breakdown_labels_and_marks_the_reduced_rate(): void
    {
        $breakdown = TaxBreakdown::ofAmounts([800 => 4660, 1000 => 1650]);

        $this->assertTrue($breakdown->hasReduced());
        $this->assertSame(495, $breakdown->tax());
        $this->assertSame(['8%', true, '¥4,660', '¥345'], [$breakdown->rows[0]['rateLabel'], $breakdown->rows[0]['reduced'], $breakdown->rows[0]['amountLabel'], $breakdown->rows[0]['taxLabel']]);
        $this->assertSame('8% 対象 ¥4,660（うち消費税 ¥345）、10% 対象 ¥1,650（うち消費税 ¥150）', $breakdown->text());
        $this->assertSame('800=4660/345,1000=1650/150', $breakdown->fingerprint());

        // 10% だけなら印は無い。額が 0 の率は出さない
        $standard = TaxBreakdown::ofAmounts([800 => 0, 1000 => 1100]);
        $this->assertFalse($standard->hasReduced());
        $this->assertSame([[1000, 1100, 100]], self::rows($standard));
        $this->assertSame([], TaxBreakdown::ofAmounts([])->rows);
        $this->assertSame('', TaxBreakdown::ofAmounts([])->text());
    }

    public function test_a_negative_rate_group_or_an_unknown_rate_is_refused(): void
    {
        foreach ([[800 => -1], [-800 => 100], [10_001 => 100]] as $grouped) {
            try {
                TaxBreakdown::ofAmounts($grouped);
                $this->fail('断るはず: '.json_encode($grouped));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function rows(TaxBreakdown $breakdown): array
    {
        return array_map(static fn (array $row): array => [$row['rateBp'], $row['amount'], $row['tax']], $breakdown->rows);
    }
}
