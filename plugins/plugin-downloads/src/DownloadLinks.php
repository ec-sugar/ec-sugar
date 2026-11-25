<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use App\Storefront\Access\SecretLinks;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * ダウンロードの画面の道筋（ADR 0084）。どれも署名つきで、作るのも確かめるのも核の SecretLinks（ADR 0081。署名には店の ID が入り、
 * ほかの店の同じ道筋では通さない。ショップのドメインを Laravel が知らない処理〔メールの仕事〕でも作れるよう、署名は道筋にだけ掛ける）。
 * 行き先の経路は秘密の道筋の宣言（secret-link）を持つ（参照元を渡さない・残さない・検索に載せないは核が掛ける）。
 *
 * - order: 注文のダウンロードの画面（ご注文の確認の画面・メールから）。期限はリンクの期限まで、ただし署名つきの URL の上限（SecretLinks::MAX_DAYS 日）まで。
 *   署名だけでは開けない（照会か会員の本人。DownloadController）。上限を越えた後は、ご注文の確認の照会かマイページから入り直す
 * - file: ファイルを渡す短い期限（FILE_MINUTES 分）の道筋。押して数えた後にだけ作り、押したセッションに結びつける
 */
final class DownloadLinks
{
    /** ファイルを渡す道筋の期限（分） */
    public const int FILE_MINUTES = 5;

    public static function order(string $orderId, CarbonImmutable $until): string
    {
        // 署名つきの URL の期限は核の上限まで（店がそれより長いダウンロードの期限にしても、リンクだけが先に切れる）
        $cap = CarbonImmutable::now()->addDays(SecretLinks::MAX_DAYS);

        return SecretLinks::signed('storefront.plugin.downloads.order', ['orderId' => strtolower($orderId)], $until->lessThan($cap) ? $until : $cap);
    }

    public static function fetch(string $orderId, string $grantId, CarbonImmutable $until): string
    {
        return SecretLinks::signed('storefront.plugin.downloads.fetch', ['orderId' => strtolower($orderId), 'grantId' => strtolower($grantId)], $until);
    }

    /**
     * ファイルを渡す道筋。押したセッションに結びつける（セッションの ID の店の鍵の HMAC を署名に入れる。ほかの人・ほかのブラウザでは使えない）
     */
    public static function file(string $grantId, string $sessionId): string
    {
        return SecretLinks::signed('storefront.plugin.downloads.file', ['grantId' => strtolower($grantId), 's' => self::session($sessionId)], now()->addMinutes(self::FILE_MINUTES));
    }

    /**
     * ファイルを渡す道筋が、今のセッションで作ったものか
     */
    public static function sameSession(Request $request): bool
    {
        $mark = $request->query('s');

        return is_string($mark) && $request->hasSession() && $request->session()->getId() !== '' && hash_equals(self::session($request->session()->getId()), $mark);
    }

    /**
     * セッションの ID の印（ID そのものは道筋に出さない）
     */
    private static function session(string $sessionId): string
    {
        return substr(hash_hmac('sha256', 'downloads-file|'.$sessionId, (string) config('app.key')), 0, 32);
    }

    /**
     * 署名つきの道筋で来て、署名が合い、期限の中で、この店のものか
     */
    public static function signed(Request $request): bool
    {
        return SecretLinks::valid($request);
    }
}
