<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * 金額の見せ方（ショップ・メール・管理画面で同じ形）
 */
final class MoneyTest extends TestCase
{
    public function test_yen_is_half_width_with_commas_and_the_sign_in_front(): void
    {
        $this->assertSame('¥0', Money::format(0));
        $this->assertSame('¥1,234,567', Money::format(1234567));
        $this->assertSame('-¥550', Money::format(-550));
        $this->assertSame('¥550', Money::format(550, 'jpy'));
        // 円でない通貨に ¥ を付けない
        $this->assertSame('1,234 USD', Money::format(1234, 'usd'));
    }
}
