<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

/**
 * ボットよけ（お客さんの手間を増やさない形。画像の文字を読ませる仕組み〔CAPTCHA〕は使わない）。
 *
 * - 見えない欄（WEBSITE）: 人には見えず（hidden の囲みの中。読み上げにも出さない）、機械的にすべての欄を埋めるボットだけが書く
 * - 書き始めの時刻（STARTED）: フォームを出した時刻と、店の ID とその時刻への署名（APP_KEY から用途で分けた鍵の HMAC）。
 *   出してから MIN_SECONDS 秒より前に送られたもの（人が名前・メール・本文を書く速さでない）と、署名の合わないもの（フォームを開かずに直に送る）を落とす
 *
 * 落としたことは送った人に教えない（受付と同じ答えを返し、何も残さない・送らない）。古い時刻はかまわない（開いたまま置いた画面からも送れる）
 */
final class BotGuard
{
    public const string WEBSITE = 'website';

    public const string STARTED = 'started';

    public const int MIN_SECONDS = 3;

    /**
     * フォームに埋める書き始めの印（「時刻.署名」）
     */
    public static function stamp(?int $now = null): string
    {
        $now ??= now()->getTimestamp();

        return $now.'.'.self::sign($now);
    }

    /**
     * 人が送ったらしいか（見えない欄が空で、書き始めの印が正しく、速すぎない）
     */
    public static function human(mixed $website, mixed $started, ?int $now = null): bool
    {
        $now ??= now()->getTimestamp();

        if ($website !== null && $website !== '') {
            return false;
        }

        if (! is_string($started) || preg_match('/\A([0-9]{1,12})\.([0-9a-f]{64})\z/', $started, $m) !== 1) {
            return false;
        }

        $at = (int) $m[1];

        return hash_equals(self::sign($at), $m[2]) && $at <= $now && $now - $at >= self::MIN_SECONDS;
    }

    private static function sign(int $at): string
    {
        $secret = hash_hmac('sha256', 'storefront-contact-form', (string) config('app.key'), true);

        return hash_hmac('sha256', (string) tenant()?->getTenantKey().'|'.$at, $secret);
    }
}
