<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Admin\Models\AgentOAuthClient;
use App\Admin\Services\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * OAuth の動的なクライアント登録（RFC 7591。ADR 0101）。登録はここだけ。
 *
 * - 受けるのは公開のクライアント（秘密を持たない。token_endpoint_auth_method は none）だけ
 * - 行き先（redirect_uris）は https か、手元の loopback（http://localhost・127.0.0.1・[::1]）だけ。1〜5 個・1 つ 512 文字まで。
 *   フラグメント・利用者の名前・空白や見えない文字・\ ・% で書いたホストは断る（行き先の見え方を偽らせない）
 * - 名前は名乗っただけで信じない（許可の画面に「確かめていない」と出す）。見えない印と改行を除いて 100 文字まで
 * - 登録は 24 時間で全体 GLOBAL_PER_DAY 件まで（接続元ごとの上限は受け口で数える）。使われないまま 7 日のものは定期の掃除で消す
 */
final class AgentOAuthClients
{
    public const int MAX_REDIRECT_URIS = 5;

    public const int MAX_URI_LENGTH = 512;

    public const int GLOBAL_PER_DAY = 200;

    /** 頼みも系統も無い登録を消すまでの分（1 時間ごとの agent:prune-oauth。全体の枠を空ける） */
    public const int UNUSED_MINUTES = 60;

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array<mixed>  $metadata
     */
    public function register(array $metadata, ?string $ip = null): AgentOAuthClient
    {
        $uris = $metadata['redirect_uris'] ?? null;

        if (! is_array($uris) || ! array_is_list($uris) || $uris === [] || count($uris) > self::MAX_REDIRECT_URIS) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must be an array of 1 to '.self::MAX_REDIRECT_URIS.' URLs.');
        }

        foreach ($uris as $uri) {
            if (! is_string($uri) || ! self::redirectUriAllowed($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'Only https URLs or loopback http URLs (localhost, 127.0.0.1, [::1]) without fragments or user info, up to '.self::MAX_URI_LENGTH.' characters, are allowed.');
            }
        }

        $method = $metadata['token_endpoint_auth_method'] ?? 'none';

        if ($method !== 'none') {
            throw new OAuthError('invalid_client_metadata', 'Only public clients (token_endpoint_auth_method none) are supported.');
        }

        $grants = $metadata['grant_types'] ?? ['authorization_code'];

        if (! is_array($grants) || ! in_array('authorization_code', $grants, true) || array_diff($grants, ['authorization_code', 'refresh_token']) !== []) {
            throw new OAuthError('invalid_client_metadata', 'grant_types must include authorization_code and may include refresh_token only.');
        }

        $responses = $metadata['response_types'] ?? ['code'];

        if (! is_array($responses) || $responses !== ['code']) {
            throw new OAuthError('invalid_client_metadata', 'response_types must be [code].');
        }

        // 全体の枠に数えるのは、頼みも系統も無い登録だけ（使われた登録で枠を減らさない）。使われない登録は 1 時間ごとの掃除で消えて枠が空く
        // （PR 186 の見直しの中 4）
        if (self::unused()->where('created_at', '>', CarbonImmutable::now()->subDay())->count() >= self::GLOBAL_PER_DAY) {
            throw new OAuthError('temporarily_unavailable', 'Too many registrations. Try again later.', 429);
        }

        $client = AgentOAuthClient::create([
            'name' => AgentOAuth::displayName($metadata['client_name'] ?? null, 100, '名前の無いアプリ'),
            'redirect_uris' => array_values(array_unique($uris)),
        ]);

        // 登録は誰でもできるので、運営の行は無い。どの接続元から・どの行き先のホストかだけを残す（名前は名乗っただけなので残さない）
        $hosts = array_values(array_unique(array_map(static fn (string $uri): string => (string) parse_url($uri, PHP_URL_HOST), $client->redirect_uris)));
        $this->audit->record('operator.agent_oauth_client_registered', null, ['client' => $client->id, 'hosts' => $hosts], ip: $ip);

        return $client;
    }

    /**
     * 頼みも系統も無い登録（全体の枠に数え、UNUSED_MINUTES を過ぎたら掃除で消す）
     *
     * @return Builder<AgentOAuthClient>
     */
    public static function unused(): Builder
    {
        return AgentOAuthClient::query()
            ->whereNotExists(static fn ($q) => $q->select(DB::raw(1))->from('agent_oauth_requests')->whereColumn('agent_oauth_requests.client_id', 'agent_oauth_clients.id'))
            ->whereNotExists(static fn ($q) => $q->select(DB::raw(1))->from('agent_oauth_grants')->whereColumn('agent_oauth_grants.client_id', 'agent_oauth_clients.id'));
    }

    /**
     * 登録してよい行き先か（https か、手元の loopback の http だけ）
     */
    public static function redirectUriAllowed(string $uri): bool
    {
        // 空白・見えない文字・ASCII の外・\ ・フラグメント・% は断る（解釈の違いでホストを偽らせない）
        if ($uri === '' || strlen($uri) > self::MAX_URI_LENGTH || preg_match('/[^\x21-\x7e]|[\\\\#%]/', $uri) === 1) {
            return false;
        }

        if (! str_starts_with($uri, 'https://') && ! str_starts_with($uri, 'http://')) {
            return false;
        }

        $parts = parse_url($uri);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        // 権限の部分（// の後ろから / ? までの間）に @ があれば断る（parse_url と相手の読み方が違っても、別のホストにさせない）
        $authority = (string) preg_replace('#\A[a-z]+://([^/?]*).*\z#s', '$1', $uri);

        if (str_contains($authority, '@')) {
            return false;
        }

        $host = strtolower($parts['host']);

        if ($parts['scheme'] === 'http') {
            return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        }

        if (preg_match('/\A[a-z0-9]([a-z0-9-]{0,62})(\.[a-z0-9]([a-z0-9-]{0,62}))*\z/', $host) !== 1) {
            return false;
        }

        // 最後の段が数字か 16 進だけのホスト（0x7f000001・1.1・127.1 など）は、ブラウザが IPv4 として読み替えるので断る。
        // 4 つの数字の IPv4 は見たままなので受ける（PR 186 の見直しの低 8）
        $last = substr((string) strrchr('.'.$host, '.'), 1);

        return preg_match('/\A(0x[0-9a-f]*|[0-9]+)\z/', $last) !== 1 || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * 行き先のホストの「登録できる名前」（許可の画面で大きく出す。例 api.claude.ai → claude.ai、x.co.jp → x.co.jp）。
     * 公開の接尾辞の一覧は持たないので、よくある 2 段の接尾辞（co.jp・com.au など）だけを見分ける近い形。IP と localhost はそのまま
     */
    public static function siteOf(string $host): string
    {
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.')) {
            return $host;
        }

        $labels = explode('.', $host);
        $suffix2 = implode('.', array_slice($labels, -2));
        $take = count($labels) >= 3 && preg_match('/\A(co|ne|or|ac|go|ed|gr|lg|ad|com|net|org|gov|edu)\.[a-z]{2}\z/', $suffix2) === 1 ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }
}
