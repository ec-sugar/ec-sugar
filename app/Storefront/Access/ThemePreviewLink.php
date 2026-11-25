<?php

declare(strict_types=1);

namespace App\Storefront\Access;

use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Support\Carbon;

/**
 * テーマの下書きのプレビューのリンク（ADR 0039 区切り 2）。編集の画面の真ん中の枠（iframe）に、ショップと同じ描き方で下書きを開く。
 * 管理画面と店頭はホストが違い、運営のログインは店頭に届かないので、商品のプレビュー（ListingPreviewLink）と同じく署名つきの期限つきのリンクで開く。
 * 中身は「店の ID・テーマ・ページ・期限」とその署名（APP_KEY から用途で分けた鍵の HMAC）。照らすのは次の 3 つ:
 *   - 署名が合う（定数時間で比べる。店の ID・テーマ・ページ・期限のどれを書き換えても合わない）
 *   - 店の ID が今の店（ほかの店で作ったリンクでは開かない）・テーマとページが経路のもの（ほかのテーマ・ページへ広げない）
 *   - 期限が過ぎていない（30 分。編集の画面は切れる前に作り直す）
 * 開けるのはそのテーマのそのページの下書きの画面だけ（ほかの画面・ほかの下書きの商品へは広げない）。
 * 同じ範囲で、区画の試し見の断片の署名も作る（鍵は用途で分ける。下書きのプレビューの画面に道筋を置き、編集の部品が読む）
 */
final class ThemePreviewLink
{
    public const int MINUTES = 30;

    /** 署名の長さ（sha256 の 16 進で 64 文字。違う長さの値は比べる前に断る） */
    private const int TOKEN_LENGTH = 64;

    /** 署名の鍵の用途（画面のプレビューと、区画の試し見の断片） */
    private const string PAGE = 'storefront-theme-preview';

    private const string TRIAL = 'storefront-theme-section-trial';

    /** 見た目の下書きの CSS（ADR 0097）の鍵の用途と、署名に入れるページの代わりの値（ページの名前の形〔英小文字で始まる〕と重ならない） */
    private const string STYLE = 'storefront-theme-style-preview';

    private const string STYLE_PAGE = '#style';

    /**
     * その店のショップの、そのテーマ・ページの下書きのプレビューの URL（店のドメインが無ければ null）
     *
     * @return array{url: string, expires_at: Carbon}|null
     */
    public static function issue(Tenant $tenant, string $theme, string $page): ?array
    {
        $expiresAt = Carbon::now()->addMinutes(self::MINUTES);
        $query = self::query((string) $tenant->getTenantKey(), $theme, $page, $expiresAt->getTimestamp());
        $url = StorefrontUrls::of($tenant, SecretLinks::path('storefront.preview.theme', ['theme' => $theme, 'page' => $page] + $query));

        return $url === null ? null : ['url' => $url, 'expires_at' => $expiresAt];
    }

    /**
     * リンクに付ける問い合わせの値
     *
     * @return array{expires: string, token: string}
     */
    public static function query(string $tenantId, string $theme, string $page, int $expires): array
    {
        return ['expires' => (string) $expires, 'token' => self::sign($tenantId, $theme, $page, (string) $expires, self::PAGE)];
    }

    /**
     * 今の店の、そのテーマ・ページの、期限の内の正しい署名か
     */
    public static function grants(string $tenantId, string $theme, string $page, mixed $expires, mixed $token): bool
    {
        return self::check($tenantId, $theme, $page, $expires, $token, self::PAGE);
    }

    private static function check(string $tenantId, string $theme, string $page, mixed $expires, mixed $token, string $purpose): bool
    {
        if ($tenantId === '' || ! is_string($expires) || ! is_string($token) || strlen($token) !== self::TOKEN_LENGTH || strlen($expires) > 12 || ! ctype_digit($expires)) {
            return false;
        }

        return hash_equals(self::sign($tenantId, $theme, $page, $expires, $purpose), $token)
            && (int) $expires > Carbon::now()->getTimestamp();
    }

    /**
     * 区画の試し見の断片の道筋（種類とパターンは付けない。編集の部品 editor.js が足す）。下書きのプレビューの画面に置く。
     * 範囲は画面のリンクと同じ（店・テーマ・ページ・期限）だが、鍵は用途で分ける（画面のリンクの署名では断片を開けず、その逆も開けない）
     */
    public static function trialPath(string $tenantId, string $theme, string $page, int $expires): string
    {
        return SecretLinks::path('storefront.preview.theme.section', ['theme' => $theme, 'page' => $page,
            'expires' => (string) $expires, 'token' => self::sign($tenantId, $theme, $page, (string) $expires, self::TRIAL)]);
    }

    /**
     * 今の店の、そのテーマ・ページの、期限の内の正しい試し見の署名か
     */
    public static function grantsTrial(string $tenantId, string $theme, string $page, mixed $expires, mixed $token): bool
    {
        return self::check($tenantId, $theme, $page, $expires, $token, self::TRIAL);
    }

    /**
     * 見た目の下書きの CSS（ADR 0097）の道筋。下書きのプレビューの画面のテーマの CSS の後に置き、編集の画面が色を変えるたびに読み直させる。
     * 範囲は店・テーマ・期限（ページは問わない。見た目はテーマのバージョンごと）、鍵は用途で分ける（画面・試し見の署名では開けず、その逆も開けない）
     */
    public static function stylePath(string $tenantId, string $theme, int $expires): string
    {
        return SecretLinks::path('storefront.preview.theme.style', ['theme' => $theme,
            'expires' => (string) $expires, 'token' => self::sign($tenantId, $theme, self::STYLE_PAGE, (string) $expires, self::STYLE)]);
    }

    /**
     * 今の店の、そのテーマの、期限の内の正しい見た目の下書きの署名か
     */
    public static function grantsStyle(string $tenantId, string $theme, mixed $expires, mixed $token): bool
    {
        return self::check($tenantId, $theme, self::STYLE_PAGE, $expires, $token, self::STYLE);
    }

    /**
     * プレビューを枠に入れてよい親（管理画面のオリジン。ADMIN_URL の「方式://ホスト:番号」）。形が崩れていれば null（どこにも入れさせない）。
     * ブラウザが比べるオリジンと同じ形にそろえる（小文字・既定の番号〔https の 443・http の 80〕は書かない）。本番では https だけ
     */
    public static function editorOrigin(): ?string
    {
        $url = (string) config('admin.url');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $allowed = app()->environment('production') ? ['https'] : ['http', 'https'];

        if (! in_array($scheme, $allowed, true) || ! is_string($host) || preg_match('/\A[a-z0-9.-]{1,253}\z/i', $host) !== 1) {
            return null;
        }

        $default = ['https' => 443, 'http' => 80][$scheme];

        return "{$scheme}://".strtolower($host).(is_int($port) && $port !== $default ? ":{$port}" : '');
    }

    private static function sign(string $tenantId, string $theme, string $page, string $expires, string $purpose): string
    {
        // 鍵は APP_KEY から用途ごとに分けて作る（商品のプレビュー・準備中のショップの Cookie・ご注文の確認のリンクとは別の鍵。
        // 画面のプレビューと区画の試し見の断片も別の鍵）
        $secret = hash_hmac('sha256', $purpose, (string) config('app.key'), true);

        return hash_hmac('sha256', "{$tenantId}|{$theme}|{$page}|{$expires}", $secret);
    }
}
