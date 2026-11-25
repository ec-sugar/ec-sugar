<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Plugin\AgeCheck\AgeAnswer;
use App\Core\Plugin\AgeCheck\AgeRule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 年齢の確かめの決まり（ADR 0077）: 満年齢は日本の暦の今日で数え、誕生日の当日にその年齢。2 月 29 日生まれは、うるう年でない年は 3 月 1 日から。
 * 生年月日の入力は、形・暦にある日・未来の日・あり得ない年を断る
 */
final class AgeRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function ages(): iterable
    {
        yield '誕生日の当日はその年齢' => ['2006-11-05', '2026-11-05', 20];
        yield '誕生日の前の日はまだ' => ['2006-11-05', '2026-11-04', 19];
        yield '月が来ていない' => ['2006-12-01', '2026-11-05', 19];
        yield '2 月 29 日生まれ・うるう年の当日' => ['2008-02-29', '2028-02-29', 20];
        yield '2 月 29 日生まれ・うるう年の前の日' => ['2008-02-29', '2028-02-28', 19];
        yield '2 月 29 日生まれ・平年の 2 月 28 日はまだ' => ['2008-02-29', '2026-02-28', 17];
        yield '2 月 29 日生まれ・平年の 3 月 1 日から' => ['2008-02-29', '2026-03-01', 18];
        yield '大みそか生まれ' => ['2006-12-31', '2026-12-31', 20];
    }

    #[DataProvider('ages')]
    public function test_full_years_on_the_japanese_calendar(string $birthdate, string $today, int $years): void
    {
        $this->assertSame($years, AgeRule::yearsOn($birthdate, new CarbonImmutable($today, 'Asia/Tokyo')));
    }

    public function test_today_is_the_japanese_day_not_the_server_day(): void
    {
        // 日本の 11 月 5 日の 0 時 10 分（UTC ではまだ 11 月 4 日）に、11 月 5 日生まれは誕生日
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-11-04 15:10:00', 'UTC'));

        try {
            $this->assertSame('2026-11-05', AgeRule::today()->format('Y-m-d'));
            $this->assertSame(20, AgeRule::yearsOn('2006-11-05', AgeRule::today()));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_the_level_is_the_highest_age_the_person_reaches(): void
    {
        $this->assertSame([0, 15, 15, 18, 18, 20, 20], array_map(AgeRule::levelFor(...), [14, 15, 17, 18, 19, 20, 64]));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badBirthdates(): iterable
    {
        yield '空' => ['', '生年月日を入力してください。'];
        yield '文字でない' => [['2000-01-01'], '生年月日を入力してください。'];
        yield '形が違う' => ['2000/01/01', '「2000-04-01」の形'];
        yield '和暦' => ['平成12年1月1日', '「2000-04-01」の形'];
        yield '暦に無い日' => ['2001-02-29', '「2000-04-01」の形'];
        yield '13 月' => ['2000-13-01', '「2000-04-01」の形'];
        yield '未来の日' => ['2026-11-06', '未来の日付'];
        yield 'あり得ない年' => ['1899-12-31', '年を確認してください'];
    }

    #[DataProvider('badBirthdates')]
    public function test_bad_birthdates_are_refused(mixed $value, string $message): void
    {
        $problem = AgeRule::birthdateProblem($value, new CarbonImmutable('2026-11-05', 'Asia/Tokyo'));
        $this->assertNotNull($problem);
        $this->assertStringContainsString($message, $problem);
    }

    public function test_good_birthdates_pass(): void
    {
        $today = new CarbonImmutable('2026-11-05', 'Asia/Tokyo');
        $this->assertNull(AgeRule::birthdateProblem('2026-11-05', $today));
        $this->assertNull(AgeRule::birthdateProblem('1906-01-01', $today));
        $this->assertNull(AgeRule::birthdateProblem(' 2000-02-29 ', $today));
    }

    public function test_an_answer_counts_only_for_its_age_version_and_recipient(): void
    {
        $answer = new AgeAnswer(18, 18, true, false);
        $this->assertTrue($answer->satisfies(18, 0, false));
        // 18 歳で聞いた答えでは 20 歳の品は売らない（同意の文も年齢も足りない）
        $this->assertFalse($answer->satisfies(20, 20, false));
        $this->assertFalse((new AgeAnswer(20, 18, true, false))->satisfies(20, 0, false));
        // 会員が前に 20 歳以上を確かめていれば、生年月日なしの答えで通る
        $this->assertTrue((new AgeAnswer(20, 0, true, false))->satisfies(20, 20, false));
        // 贈り物は受け取る方の同意が要る
        $this->assertFalse((new AgeAnswer(20, 20, true, false))->satisfies(20, 0, true));
        $this->assertTrue((new AgeAnswer(20, 20, true, true))->satisfies(20, 0, true));
        // 同意していない・前の版の文に同意した答えは数えない
        $this->assertFalse((new AgeAnswer(20, 20, false, false))->satisfies(20, 0, false));
        $this->assertFalse((new AgeAnswer(20, 20, true, false, '2020-01-01'))->satisfies(20, 0, false));
        // セッションから戻す時に形の違う値は捨てる（知らない年齢・生年月日の入った形）
        $this->assertNull(AgeAnswer::fromArray(['age' => 21, 'level' => 20, 'consent' => true, 'recipient' => false, 'version' => AgeRule::CONSENT_VERSION]));
        $this->assertNull(AgeAnswer::fromArray(['age' => 20, 'level' => '2006-11-05', 'consent' => true, 'recipient' => false, 'version' => AgeRule::CONSENT_VERSION]));
        $this->assertEquals($answer, AgeAnswer::fromArray($answer->toArray()));
    }
}
