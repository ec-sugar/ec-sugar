<?php

declare(strict_types=1);

namespace App\Core;

/**
 * ファイルの大きさの表示（写真の上限 MediaQuota と App の欄のファイル ListingFileUploads が同じ形で出す）。
 * 表示だけの小さな部品なので、どの文脈にも属さず核の一番上に置く（Version・UserFacingException と同じ居場所）
 */
final class ByteSize
{
    public const int MB = 1024 * 1024;

    public const int GB = 1024 * self::MB;

    /**
     * バイト数を「12 MB」「1.5 GB」の形にする（GB は小数 1 桁まで、末尾の 0 は落とす）
     */
    public static function human(int $bytes): string
    {
        return $bytes >= self::GB
            ? rtrim(rtrim(number_format($bytes / self::GB, 1), '0'), '.').' GB'
            : intdiv($bytes, self::MB).' MB';
    }
}
