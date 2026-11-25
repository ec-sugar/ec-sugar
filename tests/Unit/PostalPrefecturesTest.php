<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storefront\Postal\PostalPrefectures;
use App\Storefront\Postal\Prefectures;
use PHPUnit\Framework\TestCase;

/**
 * 郵便番号の上 3 桁から都道府県を引く手元の表（ADR 0064 の作って決めたこと）。表の形と、引けない番号を通すこと
 */
final class PostalPrefecturesTest extends TestCase
{
    public function test_the_table_covers_the_47_prefectures_in_order_with_well_formed_ranges(): void
    {
        $this->assertSame(Prefectures::ALL, array_keys(PostalPrefectures::RANGES));

        foreach (PostalPrefectures::RANGES as $prefecture => $ranges) {
            foreach (explode(',', $ranges) as $range) {
                $this->assertMatchesRegularExpression('/\A\d{3}(-\d{3})?\z/', $range, $prefecture);
                [$from, $to] = array_pad(explode('-', $range), 2, null);
                $this->assertLessThanOrEqual((int) ($to ?? $from), (int) $from, $prefecture);
            }
        }
    }

    public function test_candidates_and_matches(): void
    {
        $this->assertSame(['東京都'], PostalPrefectures::candidates('150-0001'));
        $this->assertSame(['北海道'], PostalPrefectures::candidates('0600001'));
        $this->assertSame(['沖縄県'], PostalPrefectures::candidates('9000001'));
        // 県境の町（愛知県弥富市と三重県木曽岬町）は、どちらも
        $this->assertSame(['愛知県', '三重県'], PostalPrefectures::candidates('4980801'));

        $this->assertTrue(PostalPrefectures::matches('1500001', '東京都'));
        $this->assertFalse(PostalPrefectures::matches('1500001', '大阪府'));
        $this->assertTrue(PostalPrefectures::matches('4980801', '三重県'));

        // 照らせない番号（まだ使われていない上 3 桁・形の違う番号）は通す
        $this->assertNull(PostalPrefectures::candidates('0089999'));
        $this->assertNull(PostalPrefectures::candidates('123'));
        $this->assertTrue(PostalPrefectures::matches('0089999', '沖縄県'));
    }
}
