<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Plugin\Gift\GiftNote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 贈り物のメッセージと注文の項目の文で断る文字（GiftNote::UNSAFE）。
 * 見た目を偽れる文字は断り、絵文字をつなぐ U+200D（家族・肌の色の組み合わせ）は通す
 */
final class GiftNoteTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function unsafe(): array
    {
        return [
            '幅の無い空白' => ["よろ\u{200B}しく"],
            '幅の無い非接合子' => ["よろ\u{200C}しく"],
            'BOM' => ["\u{FEFF}よろしく"],
            '行の区切り' => ["1 行目\u{2028}2 行目"],
            '段落の区切り' => ["1 段目\u{2029}2 段目"],
            '向きの上書き' => ["abc\u{202E}def"],
            '向きの印' => ["abc\u{200F}def"],
            '制御文字' => ["abc\u{0007}"],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_characters_that_can_disguise_the_text_are_refused(string $text): void
    {
        $this->assertNotNull(GiftNote::problem($text, false));
    }

    public function test_emoji_joined_with_zero_width_joiner_and_newlines_are_allowed_in_a_message(): void
    {
        $this->assertNull(GiftNote::problem("おめでとう 👨\u{200D}👩\u{200D}👧\n家族より 👍\u{1F3FD}", false));
    }
}
