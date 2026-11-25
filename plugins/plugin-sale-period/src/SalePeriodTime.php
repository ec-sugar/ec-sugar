<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * 日時の読み書き。店の人は日本時間（Asia/Tokyo）の「2026-12-01 10:00」の形で入れ、置くのは UTC。
 * 画面に出すときも日本時間に直す（ほかの画面と同じ「2026/12/01 10:00」の形）
 */
final class SalePeriodTime
{
    public const string ZONE = 'Asia/Tokyo';

    /** 店の人が入れる形（欄の案内と形の決まりに使う） */
    public const string FORMAT = 'Y-m-d H:i';

    /** 欄の形の決まり（核が \A(?:…)\z で全体に当てる。日・時の中身は parse が確かめる） */
    public const string PATTERN = '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}';

    /** 入れられるいちばん古い日時（打ち間違いの桁の取り違えを断る） */
    public const string FLOOR = '2020-01-01 00:00';

    /** 入れられるいちばん先の年数（今から） */
    public const int MAX_YEARS_AHEAD = 5;

    /**
     * 日本時間の「2026-12-01 10:00」を UTC の日時にする。形が違う・ありえない日時なら null
     */
    public static function parse(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/\A'.self::PATTERN.'\z/', $value) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!'.self::FORMAT, $value, self::ZONE);
        } catch (InvalidFormatException) {
            return null;
        }

        // ありえない日時（13 月・32 日・25 時）は、読めても桁を揃えた形に戻らない（繰り上がる）
        if ($parsed === null || $parsed->format(self::FORMAT) !== $value) {
            return null;
        }

        return $parsed->utc();
    }

    /**
     * 店の人に見せる形（日本時間）。無ければ null
     */
    public static function label(?CarbonImmutable $at): ?string
    {
        return $at?->timezone(self::ZONE)->format('Y/m/d H:i');
    }

    /**
     * 欄に入れ直す形（日本時間。保存した値を同じ形で出す）。無ければ null
     */
    public static function field(?CarbonImmutable $at): ?string
    {
        return $at?->timezone(self::ZONE)->format(self::FORMAT);
    }
}
