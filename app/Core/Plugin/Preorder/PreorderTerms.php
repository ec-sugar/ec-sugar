<?php

declare(strict_types=1);

namespace App\Core\Plugin\Preorder;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * 発売日の前の予約の商品の決まり（ADR 0073）。予約販売の App（PreorderHandler）が商品ごとに返す。
 * 日付は日本の暦（Asia/Tokyo）の日。発売日（releaseDate）は発送を始める日で、その日から予約でなくなる（ふつうの在庫の販売に戻る）。
 * remaining は予約をあと何点受けられるか（上限の無い予約は null）。受付を終えた（open が false）・上限に届いた予約は 0
 */
final readonly class PreorderTerms
{
    public const string ZONE = 'Asia/Tokyo';

    public function __construct(
        public string $listingId,
        public string $releaseDate,
        public ?int $remaining,
        public bool $open = true,
    ) {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $releaseDate) !== 1) {
            throw new InvalidArgumentException('発売日は Y-m-d の形です');
        }

        if ($remaining !== null && $remaining < 0) {
            throw new InvalidArgumentException('予約の残りは 0 以上です');
        }
    }

    /**
     * 今受けられる数（上限の無い予約は null。受付を終えていれば 0）
     */
    public function acceptable(): ?int
    {
        return $this->open ? $this->remaining : 0;
    }

    /**
     * 日本の暦の今日（Y-m-d）
     */
    public static function today(): string
    {
        return CarbonImmutable::now(self::ZONE)->format('Y-m-d');
    }

    /**
     * 「11月20日」（今年でなければ「2027年1月20日」）
     */
    public static function dateLabel(string $date): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::ZONE);

        if ($day === null) {
            return $date;
        }

        return ($day->year === CarbonImmutable::now(self::ZONE)->year ? '' : $day->year.'年').$day->month.'月'.$day->day.'日';
    }

    /**
     * 商品の画面とカートの行に出す一言（「予約商品（11月20日ごろ発送）」）
     */
    public static function label(string $releaseDate): string
    {
        return '予約商品（'.self::dateLabel($releaseDate).'ごろ発送）';
    }
}
