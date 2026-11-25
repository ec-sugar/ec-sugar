<?php

declare(strict_types=1);

namespace App\Core\Plugin\AgeCheck;

use Carbon\CarbonImmutable;

/**
 * 年齢の確かめ（ADR 0077）の核の決まり。選べる年齢・同意の文とその版・生年月日から満年齢を数える計算・生年月日の入力の確かめ。
 *
 * - 満年齢は日本の暦（Asia/Tokyo）の今日で数える。年齢は誕生日の前の日が終わった時に 1 つ上がる（年齢計算ニ関スル法律と民法 143 条）ので、
 *   誕生日の当日にはその年齢。2 月 29 日に生まれた人は、うるう年でない年は 3 月 1 日からその年齢（2 月 28 日はまだ）。
 *   「月と日を比べて、今年の誕生日がまだ来ていなければ 1 を引く」で、どちらも同じ答えになる
 * - 生年月日は計算に使ったら捨てる。この組の外へ出すのは「どの年齢以上か」（AGES のうち満たすいちばん上）だけ
 */
final class AgeRule
{
    /** 店が商品ごとに選べる年齢（20 歳はお酒・たばこ。18 歳・15 歳は店が選ぶ） */
    public const array AGES = [20, 18, 15];

    /** 既定の年齢 */
    public const int DEFAULT_AGE = 20;

    /** 同意の文の版（文を変えたら上げる。注文の記録に残し、確かめの後に版が変わった注文は確定しない） */
    public const string CONSENT_VERSION = '2026-11-05';

    /** 生年月日として受ける、いちばん古い年（今日から何年前まで） */
    public const int MAX_YEARS = 120;

    public static function isAge(int $age): bool
    {
        return in_array($age, self::AGES, true);
    }

    /**
     * 注文の入力の同意の文（「20 歳以上です」）
     */
    public static function consentText(int $age): string
    {
        return "私は {$age} 歳以上です（年齢を偽ったご注文はお受けできません）。";
    }

    /**
     * 贈り物の注文の、受け取る人の同意の文
     */
    public static function recipientText(int $age): string
    {
        return "贈り物を受け取る方も {$age} 歳以上です。";
    }

    /**
     * 商品の画面の一言
     */
    public static function noticeText(int $age): string
    {
        return "{$age} 歳未満の方はお求めいただけません。";
    }

    /**
     * 注文・納品書・管理画面の札（「年齢確認済み（20 歳以上）」）
     */
    public static function verifiedLabel(int $age): string
    {
        return "年齢確認済み（{$age} 歳以上）";
    }

    /**
     * 日本の暦の今日
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Tokyo')->startOfDay();
    }

    /**
     * 生年月日（Y-m-d。確かめた後の値）の人の、$today の満年齢
     */
    public static function yearsOn(string $birthdate, CarbonImmutable $today): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', $birthdate));
        $years = $today->year - $y;

        // 今年の誕生日がまだ来ていない（月と日で比べる。2 月 29 日生まれは、うるう年でない年は 3 月 1 日に来る）
        if ([$today->month, $today->day] < [$m, $d]) {
            $years--;
        }

        return $years;
    }

    /**
     * 満年齢で満たす、選べる年齢のいちばん上（15 歳未満は 0）
     */
    public static function levelFor(int $years): int
    {
        foreach (self::AGES as $age) {
            if ($years >= $age) {
                return $age;
            }
        }

        return 0;
    }

    /**
     * 入れた生年月日の誤り（無ければ null）。形（2000-04-01）・暦にある日・今日より後でない・120 年より前でない
     */
    public static function birthdateProblem(mixed $value, CarbonImmutable $today): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return '生年月日を入力してください。';
        }

        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', trim($value), $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '生年月日は「2000-04-01」の形式で、正しい日付を入力してください。';
        }

        $date = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);

        if ($date > $today->format('Y-m-d')) {
            return '生年月日に、未来の日付は入力できません。';
        }

        if ((int) $m[1] < $today->year - self::MAX_YEARS) {
            return '生年月日の年を確認してください。';
        }

        return null;
    }
}
