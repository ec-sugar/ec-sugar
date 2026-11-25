<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Carbon\CarbonImmutable;
use EcSugar\PluginLine\LineLogin;
use EcSugar\PluginLine\LineNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * App「LINE 連携」のテストの部品（ADR 0070）。外の LINE には本当につながず、Http::fake() の偽の LINE が答える。
 * 偽の LINE は、トークンの窓口に来た code_verifier を覚え、ID トークンを本物と同じ形（HS256・チャネルシークレット）で作って返す
 */
trait LineHelpers
{
    private const string CHANNEL = '1234567890';

    // 見本の鍵（秘密の検知に当たらないよう、短く分けて書く）
    private const string SECRET = '01234567'.'89abcdef'.'01234567'.'89abcdef';

    private const string BOT_SECRET = 'fedcba98'.'76543210'.'fedcba98'.'76543210';

    // 本物の形の短い見本（秘密の検知に当たらないよう、見本と分かる文字だけ）
    private const string ACCESS_TOKEN = 'test-line-access-token-0000';

    private const string SUB = 'U0123456789abcdef0123456789abcdef';

    private const string OTHER_SUB = 'Uffffffffffffffffffffffffffffffff';

    private ?string $member = null;

    /**
     * 偽の LINE が次に返す ID トークンの中身（null の値は入れない）と、署名の鍵・頭
     *
     * @var array<string, mixed>
     */
    private array $claims = [];

    private string $signWith = self::SECRET;

    /** @var array<string, string> */
    private array $header = ['typ' => 'JWT', 'alg' => 'HS256'];

    private bool $friendFlag = true;

    /** 偽の LINE の push の答え（ok・error・down） */
    private string $pushReply = 'ok';

    private function fakeLine(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();

            if ($url === LineLogin::TOKEN) {
                return Http::response(['access_token' => 'line-at', 'expires_in' => 2592000, 'id_token' => $this->idToken(), 'token_type' => 'Bearer', 'scope' => 'profile openid']);
            }

            if ($url === LineLogin::FRIENDSHIP) {
                return Http::response(['friendFlag' => $this->friendFlag]);
            }

            if ($url === LineNotifier::PUSH) {
                return match ($this->pushReply) {
                    'ok' => Http::response([]),
                    'down' => Http::failedConnection(),
                    default => Http::response(['message' => 'error'], 500),
                };
            }

            return Http::response('', 404);
        });
    }

    private function configureLine(string $shop, bool $messaging = true): void
    {
        $this->inside($shop, function () use ($messaging): void {
            app(PluginStates::class)->set('line', true, 'test');
            // 秘密でない項目を一緒に送る（管理画面の保存と同じ）
            app(PluginSettings::class)->update('line', array_filter([
                'login_channel_id' => self::CHANNEL,
                'login_channel_secret' => self::SECRET,
                'messaging_access_token' => $messaging ? self::ACCESS_TOKEN : null,
                'messaging_channel_secret' => $messaging ? self::BOT_SECRET : null,
                'on_paid' => true,
            ], static fn (mixed $v): bool => $v !== null), 'test');
        });
    }

    /**
     * 「LINE でログイン」を押して、LINE の認可の画面の URL のクエリを返す
     *
     * @return array<string, string>
     */
    private function startLine(string $shop = 'lna', string $path = '/p/line/login'): array
    {
        $response = $this->shop('GET', $path, shop: $shop);
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(LineLogin::AUTHORIZE.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /**
     * LINE から戻る（偽の LINE は、始めた時の nonce を入れた ID トークンを返す。$claims で上書き）
     *
     * @param  array<string, string>  $query  startLine の答え
     * @param  array<string, mixed>  $claims
     * @return TestResponse<Response>
     */
    private function returnFromLine(array $query, array $claims = [], string $sub = self::SUB, string $shop = 'lna', ?string $state = null): TestResponse
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $this->claims = array_replace(['iss' => 'https://access.line.me', 'sub' => $sub, 'aud' => self::CHANNEL, 'exp' => $now + 3600, 'iat' => $now, 'nonce' => $query['nonce'] ?? '', 'amr' => ['lineautologin'], 'name' => 'はなこ'], $claims);

        return $this->shop('GET', '/p/line/callback?'.http_build_query(['code' => 'auth-code-1', 'state' => $state ?? ($query['state'] ?? ''), 'friendship_status_changed' => 'false']), shop: $shop);
    }

    private function idToken(): string
    {
        $claims = array_filter($this->claims, static fn (mixed $v): bool => $v !== null);
        $payload = self::b64((string) json_encode($this->header)).'.'.self::b64((string) json_encode($claims));

        return $payload.'.'.self::b64(hash_hmac('sha256', $payload, $this->signWith, true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function login(string $email, string $shop = 'lna'): void
    {
        $this->shop('POST', '/account/login', ['email' => $email], shop: $shop);
        $this->shop('POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code], shop: $shop);
    }

    private function lastCode(): string
    {
        return Mail::sent(LoginCodeMail::class)->last()->code;
    }

    /**
     * Webhook の本文に、Messaging API のチャネルシークレットで署名して送る
     *
     * @param  list<mixed>  $events  知らせの並び（壊れた知らせも混ぜて送れる）
     * @return TestResponse<Response>
     */
    private function webhook(array $events, ?string $secret = self::BOT_SECRET, string $shop = 'lna'): TestResponse
    {
        $body = (string) json_encode(['destination' => 'Uxxxxxxxx', 'events' => $events]);
        $headers = $secret === null ? [] : ['X-Line-Signature' => base64_encode(hash_hmac('sha256', $body, $secret, true))];
        $this->defaultCookies = [];
        $response = $this->call('POST', "http://{$shop}.localhost/p/line/webhook", [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']), $body);
        tenancy()->end();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function friendEvent(string $type, string $sub, int $ms): array
    {
        return ['type' => $type, 'mode' => 'active', 'timestamp' => $ms, 'source' => ['type' => 'user', 'userId' => $sub], 'webhookEventId' => '01HMQGW40RZJPJM3RAJP7BHC2Q', 'deliveryContext' => ['isRedelivery' => false]];
    }

    /**
     * ショップへ送る（会員の印を持ち回る。送るたびに 7 秒進める〔人の速さ。1 分の回数の上限にテスト自身が当たらないように〕）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = [], ?string $ip = null, string $shop = 'lna'): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        $this->travel(7)->seconds();
        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $this->serverVariables = $ip === null ? [] : ['REMOTE_ADDR' => $ip];
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);

        tenancy()->end();
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }
}
