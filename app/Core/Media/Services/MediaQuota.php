<?php

declare(strict_types=1);

namespace App\Core\Media\Services;

use App\Core\ByteSize;
use App\Core\Listing\Models\ListingImage;
use App\Core\Media\MediaQuotaExceeded;
use App\Core\Media\MediaRateLimited;
use App\Core\Media\Models\ShopMedia;
use App\Core\RateLimit\Throttle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

/**
 * 写真の置き場の上限（config/media.php）。商品の写真とショップの写真の両方で使う。
 *
 * - admit(): 作り直し（重い）の前に。店ごとの 1 分の回数（先に足してから比べる）と、その日の量がもう尽きていないか
 * - reserve(): 書き込みの錠（EventStore::lock()）の中で。店の合計（DB の bytes の和）と、その日の量に足しても超えないか。超えなければその日の量に数える
 *   （錠の中なので、同時に上げても上限を超えない。その日の量はキャッシュで数え、消しても戻さない）
 * - 数える鍵には店の ID を入れる（キャッシュの置き場によらず店ごと）
 * - 断るときは、1 分の回数は MediaRateLimited（待てば空く）、量は MediaQuotaExceeded（今日は空かない）。どちらも InvalidImageException の仲間
 */
final class MediaQuota
{
    public const int MB = ByteSize::MB;

    /** 運営の様子で「早めに」を出す割合（%） */
    public const int WARN_PERCENT = 90;

    public function admit(): void
    {
        $key = self::minuteKey();
        // 先に数えてから比べる（足し算の戻り値で。同時に上げても 1 分の上限を越えない）
        if (! Throttle::allow($key, self::perMinute(), 60)) {
            throw new MediaRateLimited('写真を続けてアップロードしています。1 分ほど待ってからアップロードしてください。');
        }

        if (self::today() >= self::dailyBytes()) {
            throw new MediaQuotaExceeded(self::dailyMessage());
        }
    }

    /**
     * 続けて $count 枚を作り直せるまでの秒数（0 ならすぐ上げられる）。サンプルのように続けて上げる処理が、1 分の回数に触れる前に待つため。
     * 書き込みの錠の外で呼ぶ（待つ間に店の書き込みを止めない）
     */
    public function secondsUntil(int $count): int
    {
        $key = self::minuteKey();
        // 上限より多くは 1 分に上げられないので、上限まで空くのを待つ（その先は admit が断る）
        $count = min($count, self::perMinute());

        if (RateLimiter::attempts($key) + $count <= self::perMinute()) {
            return 0;
        }

        // 残りの秒は切り捨てで返るので 1 秒足す（窓が開くより先に起きて断られないように）
        return RateLimiter::availableIn($key) + 1;
    }

    public function reserve(int $bytes): void
    {
        if (self::used() + $bytes > self::shopBytes()) {
            throw new MediaQuotaExceeded('写真の保存容量がいっぱいです（上限 '.ByteSize::human(self::shopBytes()).'）。使っていない写真を削除してからアップロードしてください。');
        }

        if (self::today() + $bytes > self::dailyBytes()) {
            throw new MediaQuotaExceeded(self::dailyMessage());
        }

        $key = self::dayKey();
        Cache::add($key, 0, 2 * 86_400);
        Cache::increment($key, $bytes);
    }

    /**
     * 店の写真の合計（バイト）。店の文脈の中で
     */
    public static function used(): int
    {
        return (int) ListingImage::query()->sum('bytes') + (int) ShopMedia::query()->sum('bytes');
    }

    /**
     * 合計が上限の何 % か（運営の様子）
     */
    public static function percent(int $used): int
    {
        return (int) floor($used * 100 / max(1, self::shopBytes()));
    }

    public static function shopBytes(): int
    {
        return max(1, (int) config('media.shop_quota_mb')) * self::MB;
    }

    private static function dailyBytes(): int
    {
        return max(1, (int) config('media.daily_upload_mb')) * self::MB;
    }

    private static function perMinute(): int
    {
        return max(1, (int) config('media.uploads_per_minute'));
    }

    private static function minuteKey(): string
    {
        return 'media-uploads|'.self::shop().'|minute';
    }

    private static function today(): int
    {
        return (int) Cache::get(self::dayKey(), 0);
    }

    private static function dayKey(): string
    {
        return 'media-upload-bytes|'.self::shop().'|'.CarbonImmutable::now(self::timezone())->toDateString();
    }

    /**
     * 日を区切る時刻（表示の時刻。壊れていても日本の時刻）
     */
    private static function timezone(): string
    {
        $timezone = (string) config('app.display_timezone', 'Asia/Tokyo');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Tokyo';
    }

    private static function shop(): string
    {
        $shop = tenant()?->getTenantKey();

        if (! is_string($shop) || $shop === '') {
            throw new LogicException('写真の上限は店の文脈の中でだけ数える');
        }

        return $shop;
    }

    private static function dailyMessage(): string
    {
        return '今日アップロードできる写真の量（'.ByteSize::human(self::dailyBytes()).'）を超えます。明日またアップロードしてください。';
    }
}
