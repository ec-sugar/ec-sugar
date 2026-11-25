<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * LINE ログイン v2.1（OAuth 2.0 の認可コードフロー ＋ PKCE ＋ OpenID Connect。ADR 0070）。
 *
 * - 始める: state（CSRF の防ぎ）・nonce（ID トークンの使い回しの防ぎ）・code_verifier（PKCE。S256）をこのブラウザのセッションに 1 回分だけ置き、
 *   LINE の認可の画面へ送る。戻り先（redirect_uri）はショップの 1 つ目のドメインの /p/line/callback だけ（店が LINE の管理画面に登録する値と同じ。
 *   開いたリダイレクトを作らない）。求めるのは profile と openid だけ（メールは求めない。LINE のメールで会員につながないため）
 * - 戻る: セッションの値を取り出して消し（1 回きり）、10 分を過ぎた・state が違うものは断る。コードをトークンに替え（code_verifier を添える）、
 *   ID トークンを確かめて（IdToken）LINE の利用者の ID を得る。友だちかはアクセストークンで 1 回だけ聞き、アクセストークンは持たない
 */
final class LineLogin
{
    public const string AUTHORIZE = 'https://access.line.me/oauth2/v2.1/authorize';

    public const string TOKEN = 'https://api.line.me/oauth2/v2.1/token';

    public const string FRIENDSHIP = 'https://api.line.me/friendship/v1/status';

    public const string SESSION = 'line_oauth';

    public const int MINUTES = 10;

    public function __construct(private readonly LineSettings $settings) {}

    /**
     * ログインの戻り先（ショップの 1 つ目のドメイン）。店が LINE の管理画面に写す値
     */
    public static function callbackUrl(): ?string
    {
        $tenant = tenant();

        return $tenant instanceof Tenant ? StorefrontUrls::of($tenant, '/p/line/callback') : null;
    }

    /**
     * 認可の画面の URL を作り、このブラウザのセッションに 1 回分の値を置く。設定が足りなければ null
     *
     * @param  'login'|'link'  $purpose  ログイン・登録か、ログインした会員が LINE をつなぐか
     */
    public function begin(Request $request, string $purpose, ?string $accountId = null): ?string
    {
        $login = $this->settings->login();
        $callback = self::callbackUrl();

        if ($login === null || $callback === null) {
            return null;
        }

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $request->session()->put(self::SESSION, [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'at' => CarbonImmutable::now()->getTimestamp(),
            'purpose' => $purpose,
            'account' => $accountId,
        ]);

        $query = [
            'response_type' => 'code',
            'client_id' => $login['id'],
            'redirect_uri' => $callback,
            'state' => $state,
            'scope' => 'profile openid',
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        $prompt = $this->settings->botPrompt();

        if ($prompt !== null) {
            $query['bot_prompt'] = $prompt;
        }

        return self::AUTHORIZE.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * LINE から戻った要求を確かめる。だめなら理由の言葉（cancelled: お客さんがやめた・failed: それ以外）を持つ LineIdentity を返さず null と理由
     *
     * @return array{0: ?LineIdentity, 1: string}
     */
    public function finish(Request $request): array
    {
        // 1 回きり: 合っても違っても、ここで消す
        $pending = $request->session()->pull(self::SESSION);
        $login = $this->settings->login();
        $callback = self::callbackUrl();

        if (! is_array($pending) || ! is_string($pending['state'] ?? null) || ! is_string($pending['nonce'] ?? null) || ! is_string($pending['verifier'] ?? null)
            || ! is_int($pending['at'] ?? null) || CarbonImmutable::now()->getTimestamp() - $pending['at'] > self::MINUTES * 60
            || $login === null || $callback === null) {
            return [null, 'failed'];
        }

        $state = $request->query('state');

        if (! is_string($state) || strlen($state) > 128 || ! hash_equals($pending['state'], $state)) {
            return [null, 'failed'];
        }

        // お客さんが同意の画面でやめた（state は合っている）
        if ($request->query('error') !== null) {
            return [null, 'cancelled'];
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || strlen($code) > 512 || preg_match('/\A[\x21-\x7e]+\z/', $code) !== 1) {
            return [null, 'failed'];
        }

        try {
            $token = Http::asForm()->acceptJson()->timeout(10)->post(self::TOKEN, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $callback,
                'client_id' => $login['id'],
                'client_secret' => $login['secret'],
                'code_verifier' => $pending['verifier'],
            ]);
        } catch (ConnectionException) {
            return [null, 'failed'];
        }

        $idToken = $token->successful() ? $token->json('id_token') : null;
        $accessToken = $token->successful() ? $token->json('access_token') : null;
        $sub = is_string($idToken) ? IdToken::verify($idToken, $login['id'], $login['secret'], $pending['nonce'], CarbonImmutable::now()->getTimestamp()) : null;

        if ($sub === null) {
            return [null, 'failed'];
        }

        $purpose = $pending['purpose'] ?? null;
        $account = $pending['account'] ?? null;

        return [new LineIdentity(
            $sub,
            is_string($accessToken) && $this->isFriend($accessToken),
            $purpose === 'link' ? 'link' : 'login',
            is_string($account) ? $account : null,
        ), 'ok'];
    }

    /**
     * お店の公式アカウントと友だちか（LINE ログインのチャネルにリンクした公式アカウント）。聞けなければ false（Webhook の follow で直る）
     */
    private function isFriend(string $accessToken): bool
    {
        try {
            $answer = Http::withToken($accessToken)->acceptJson()->timeout(5)->get(self::FRIENDSHIP);
        } catch (ConnectionException) {
            return false;
        }

        return $answer->successful() && $answer->json('friendFlag') === true;
    }
}
