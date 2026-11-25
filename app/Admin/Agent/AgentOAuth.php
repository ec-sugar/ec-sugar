<?php

declare(strict_types=1);

namespace App\Admin\Agent;

/**
 * AI エージェントの OAuth 2.1（ADR 0101）の決まった値と小さな部品。
 *
 * - 認可サーバー（issuer）は管理画面のオリジン（config admin.url）。守る資源は同じホストの /mcp だけ
 * - 鍵の種類（scope）は今の AI エージェントの鍵と同じ read・write
 * - 期限: 許可の頼みと認可コードは 10 分、アクセストークンは 1 時間、リフレッシュトークンは 30 日（使うたびに入れ替え）
 */
final class AgentOAuth
{
    public const array SCOPES = [AgentTokens::READ, AgentTokens::WRITE];

    public const int REQUEST_MINUTES = 10;

    public const int CODE_MINUTES = 10;

    public const int ACCESS_MINUTES = 60;

    public const int REFRESH_DAYS = 30;

    public const string REFRESH_PREFIX = 'ecs_refresh_';

    /** 許可の頼みの合い言葉・認可コード（乱数 32 バイトの base64url） */
    public const string HANDLE_SHAPE = '/\A[A-Za-z0-9_-]{43}\z/';

    public const string REFRESH_SHAPE = '/\Aecs_refresh_[A-Za-z0-9_-]{43}\z/';

    public static function issuer(): string
    {
        return rtrim((string) config('admin.url'), '/');
    }

    /** 守る資源（RFC 8707・9728 の resource）。MCP の入口の URL */
    public static function resource(): string
    {
        return self::issuer().'/mcp';
    }

    /** 保護された資源のメタデータの URL（RFC 9728 の 3.1。資源の道筋を .well-known の後ろに足す形） */
    public static function resourceMetadataUrl(): string
    {
        return self::issuer().'/.well-known/oauth-protected-resource/mcp';
    }

    /**
     * 資源の指定（RFC 8707）を受けてよいか。無い（null）か、/mcp の URL かこのホストのオリジン（ルートのメタデータを読んだクライアントが送る形）だけ
     */
    public static function acceptsResource(mixed $resource): bool
    {
        if ($resource === null) {
            return true;
        }

        if (! is_string($resource)) {
            return false;
        }

        return in_array(rtrim($resource, '/'), [self::resource(), self::issuer()], true);
    }

    /**
     * 頼まれた鍵の種類（空白区切り）から、許可の画面の既定の種類を決める。write を含めば write、ほかは read（知らない名前は捨てる）
     */
    public static function requestedScope(mixed $scope): string
    {
        $names = is_string($scope) ? preg_split('/ +/', trim($scope)) : [];

        return in_array(AgentTokens::WRITE, $names ?: [], true) ? AgentTokens::WRITE : AgentTokens::READ;
    }

    /** 答えに出す鍵の種類（書ける鍵は読むこともできる） */
    public static function scopeString(string $scope): string
    {
        return $scope === AgentTokens::WRITE ? 'read write' : 'read';
    }

    public static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * 行き先の URL（登録したものそのまま）に、問いの値を足す。登録の時にフラグメントを断っているので、後ろに足すだけ
     *
     * @param  array<string, string|null>  $parameters
     */
    public static function redirect(string $redirectUri, array $parameters): string
    {
        $query = http_build_query(array_filter($parameters, static fn (?string $value): bool => $value !== null), '', '&', PHP_QUERY_RFC3986);

        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query;
    }

    /**
     * 登録された名前を、画面と鍵の名前に使える 1 行にする（見えない印と改行を除き、長さを切る）。空なら代わりの名前
     */
    public static function displayName(mixed $name, int $length, string $fallback): string
    {
        // 改行と空白は 1 つの空白に、残りの見えない印（文字の向きを変える印を含む）は消す
        $name = is_string($name) && mb_check_encoding($name, 'UTF-8') ? (preg_replace('/[\s\x{2028}\x{2029}]+/u', ' ', $name) ?? '') : '';
        $name = trim(mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name) ?? ''), 0, $length));

        return $name === '' ? $fallback : $name;
    }
}
