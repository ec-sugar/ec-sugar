<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

use Carbon\CarbonImmutable;

/**
 * Messaging API の Webhook（ADR 0070。LINE の資料「Verify webhook signature」のとおり）。
 *
 * - 署名: 受けた本文そのもの（読み解く前・整える前）の HMAC-SHA256 を Messaging API のチャネルシークレットで作り、Base64 にして
 *   x-line-signature と比べる。合わない・無い・鍵がまだなら何もしない
 * - 読むのは follow（友だち追加・ブロックの解除）と unfollow（ブロック）だけ。つないだ会員の「友だちか」の印を変える。
 *   つないでいない LINE の知らせは何も残さない。メッセージなどほかの知らせは読まない（返事もしない）
 * - 本文は 256KB まで・知らせは 100 件まで
 */
final class LineWebhook
{
    public const int MAX_BYTES = 262_144;

    public const int MAX_EVENTS = 100;

    public function __construct(private readonly LineLinks $links) {}

    public static function signatureMatches(string $body, ?string $signature, string $secret): bool
    {
        return is_string($signature) && $signature !== '' && hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $signature);
    }

    /**
     * 署名を確かめた後の本文を読む。読めなければ false
     */
    public function apply(string $body): bool
    {
        $data = json_decode($body, true, 16);

        if (! is_array($data) || ! is_array($data['events'] ?? null) || ! array_is_list($data['events'])) {
            return false;
        }

        foreach (array_slice($data['events'], 0, self::MAX_EVENTS) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? null;
            $user = $event['source']['userId'] ?? null;
            $time = $event['timestamp'] ?? null;

            if (! in_array($type, ['follow', 'unfollow'], true) || ($event['source']['type'] ?? null) !== 'user'
                || ! is_string($user) || preg_match('/\AU[0-9a-f]{32}\z/', $user) !== 1 || ! is_int($time) || $time <= 0) {
                continue;
            }

            // 知らせの時刻（ミリ秒）で、前後して届いた古い知らせで戻さない
            $this->links->setFriend($user, $type === 'follow', CarbonImmutable::createFromTimestampMs($time));
        }

        return true;
    }
}
