<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Agent\AgentFailures;
use App\Admin\Agent\AgentOAuthClients;
use App\Admin\Agent\AgentOAuthGrants;
use App\Admin\Agent\OAuthError;
use App\Admin\Http\Controllers\OAuthController;
use App\Admin\Http\Middleware\AuthenticateAgent;
use App\Admin\Mail\OperatorSecurityMail;
use App\Admin\Mcp\Tools\AgentWriteTool;
use App\Admin\Models\AgentOAuthGrant;
use App\Admin\Models\AgentOAuthRefreshToken;
use App\Admin\Models\AgentOAuthRequest;
use App\Admin\Models\AgentToken;
use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Shop\Services\AgentSettings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\WithTenants;
use Tests\TestCase;
use Throwable;

/**
 * AI エージェントの OAuth 2.1（ADR 0101）。claude.ai などの「コネクタを追加」から、運営が許可の画面で許可してつなぐ。
 * 登録（公開のクライアントだけ・行き先の形）・PKCE（S256 だけ）・コードは 1 回だけ／10 分／作ったクライアントの・行き先の完全一致・
 * 許可の画面の段階と再確認・拒否・出した鍵で /mcp に入れて段階の中だけ・期限・リフレッシュの入れ替えと使い回しの検知・
 * 取り消し（全部ログアウト・権限を下げる・一覧・RFC 7009）・接続元の制限を外した経路と外していない許可の画面
 */
final class AgentOAuthTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string SHOP = 'oaua';

    private const string OTHER = 'oaub';

    private const string REDIRECT = 'https://client.example/api/mcp/auth_callback';

    private int $auditFrom = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->auditFrom = (int) AuditEvent::query()->max('sequence');
        $this->tenant(self::SHOP, '菓子の店');
        $this->tenant(self::OTHER, 'となりの店');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_metadata_points_to_the_endpoints_and_the_mcp_401_points_to_the_metadata(): void
    {
        $issuer = $this->adminHost;

        // resource はメタデータの URL から .well-known 以下を除いたもの（ルートの形はオリジン、/mcp を足した形は /mcp。RFC 9728 の 3.3）
        foreach (['/.well-known/oauth-protected-resource' => $issuer, '/.well-known/oauth-protected-resource/mcp' => $issuer.'/mcp'] as $path => $expected) {
            $resource = $this->machine('GET', $path)->assertOk();
            $this->assertSame($expected, $resource->json('resource'));
            $this->assertSame([$issuer], $resource->json('authorization_servers'));
            $this->assertSame(['header'], $resource->json('bearer_methods_supported'));
        }

        $server = $this->machine('GET', '/.well-known/oauth-authorization-server')->assertOk();
        $this->assertSame($issuer, $server->json('issuer'));
        $this->assertSame($issuer.'/oauth/authorize', $server->json('authorization_endpoint'));
        $this->assertSame($issuer.'/oauth/token', $server->json('token_endpoint'));
        $this->assertSame($issuer.'/oauth/register', $server->json('registration_endpoint'));
        $this->assertSame($issuer.'/oauth/revoke', $server->json('revocation_endpoint'));
        $this->assertSame(['S256'], $server->json('code_challenge_methods_supported'));
        $this->assertSame(['none'], $server->json('token_endpoint_auth_methods_supported'));
        $this->assertSame(['authorization_code', 'refresh_token'], $server->json('grant_types_supported'));

        // 鍵の無い /mcp の 401 は、保護された資源のメタデータの場所を教える（RFC 9728 の 5.1）
        $this->assertSame('Bearer resource_metadata="'.$issuer.'/.well-known/oauth-protected-resource/mcp"', $this->mcp(null, 'tools/list')->assertUnauthorized()->headers->get('WWW-Authenticate'));

        // ショップのホストには無い
        $this->get('http://'.self::SHOP.'.localhost/.well-known/oauth-authorization-server')->assertNotFound();
    }

    public function test_registration_accepts_only_public_clients_with_https_or_loopback_redirects(): void
    {
        $ok = $this->register(['redirect_uris' => [self::REDIRECT, 'http://localhost:33418/callback', 'http://127.0.0.1:8976/cb', 'http://[::1]:7777/cb'], 'client_name' => "Claude\u{202E}\nコネクタ"])->assertCreated();
        $this->assertSame('none', $ok->json('token_endpoint_auth_method'));
        $this->assertMatchesRegularExpression('/\A[0-9a-z]{26}\z/', (string) $ok->json('client_id'));
        // 見えない印と改行は除く（名前は名乗っただけなので、画面で取り違えさせない）
        $this->assertSame('Claude コネクタ', $ok->json('client_name'));
        $this->assertStringContainsString('no-store', (string) $ok->headers->get('Cache-Control'));

        foreach ([
            'http://client.example/cb',
            'javascript:alert(1)',
            'cursor://anysphere/cb',
            'https://client.example/cb#frag',
            'https://user@client.example/cb',
            'https://client.example\\@evil.example/cb',
            'https://client.example/c b',
            'https://client.example/%2e%2e/cb',
            'https://クライアント.example/cb',
            'http://localhost.evil.example/cb',
            'http://127.0.0.2/cb',
            // 最後の段が数字か 16 進だけ（ブラウザが IPv4 に読み替える）
            'https://0x7f000001/cb',
            'https://1.1/cb',
            'https://127.1/cb',
            'https://shop.123/cb',
            'https://example.0x10/cb',
            'https://client.example/'.str_repeat('a', 520),
            '',
        ] as $bad) {
            $this->register(['redirect_uris' => [$bad]])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }

        // 断った登録も接続元ごとの 1 時間の数に入るので、ここで数えを空にする
        Cache::store()->flush();
        $this->register(['redirect_uris' => []])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->register(['redirect_uris' => array_map(static fn (int $i): string => "https://c{$i}.example/cb", range(1, 6))])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->register(['redirect_uris' => self::REDIRECT])->assertStatus(400);
        $this->register(['redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic'])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
        $this->register(['redirect_uris' => [self::REDIRECT], 'grant_types' => ['implicit']])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
        $this->register(['redirect_uris' => [self::REDIRECT], 'response_types' => ['token']])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
        $this->machine('POST', '/oauth/register', [], 'not json', ['CONTENT_TYPE' => 'application/json'])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');

        $this->assertSame(1, DB::table('agent_oauth_clients')->count());

        // 4 つの数字の IPv4 は見たままなので受ける
        $this->register(['redirect_uris' => ['https://203.0.113.5/cb']])->assertCreated();

        // 許可の画面で大きく出す「登録できる名前」
        foreach (['api.claude.ai' => 'claude.ai', 'chatgpt.com' => 'chatgpt.com', 'a.b.example.co.jp' => 'example.co.jp', 'claude.ai.evil.example' => 'evil.example', '203.0.113.5' => '203.0.113.5', 'localhost' => 'localhost', '[::1]' => '[::1]'] as $host => $site) {
            $this->assertSame($site, AgentOAuthClients::siteOf($host), $host);
        }
    }

    public function test_registration_is_limited_per_address_and_overall(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->register(['redirect_uris' => [self::REDIRECT]])->assertCreated();
        }

        $this->register(['redirect_uris' => [self::REDIRECT]])->assertStatus(429);

        // 全体で 1 日の上限（接続元を変えても越えられない）
        Cache::store()->flush();
        $now = CarbonImmutable::now();
        DB::table('agent_oauth_clients')->insert(array_map(static fn (int $i): array => ['id' => strtolower((string) Str::ulid()), 'name' => 'x', 'redirect_uris' => json_encode([self::REDIRECT]), 'created_at' => $now, 'updated_at' => $now], range(1, AgentOAuthClients::GLOBAL_PER_DAY - 20)));
        $this->register(['redirect_uris' => [self::REDIRECT]], '198.51.100.7')->assertStatus(429)->assertJsonPath('error', 'temporarily_unavailable');
    }

    public function test_an_owner_connects_through_the_consent_screen_and_the_agent_reads_the_shop(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $client = $this->clientId('Claude');
        [$verifier, $challenge] = $this->pkce();

        // 頼みは許可の画面へ（行き先へはまだ送らない）
        $handle = $this->authorize($client, $challenge, ['state' => 'xyz-123', 'scope' => 'read', 'resource' => $this->adminHost.'/mcp']);
        $detail = $this->send('GET', "/api/oauth-requests/{$handle}")->assertOk();
        $this->assertSame('client.example', $detail->json('request.redirect.host'));
        $this->assertSame('Claude', $detail->json('request.client.name'));
        $this->assertSame([self::SHOP, self::OTHER], array_column((array) $detail->json('request.tenants'), 'id'));

        // 許可は直前の再確認の後
        $this->send('POST', "/api/oauth-requests/{$handle}/approve", ['tenants' => [self::SHOP], 'scope' => 'read'])->assertStatus(423);
        $this->reconfirm($owner, $authenticator);
        $redirect = (string) $this->send('POST', "/api/oauth-requests/{$handle}/approve", ['tenants' => [self::SHOP], 'scope' => 'read'])->assertOk()->json('redirect_to');
        $query = $this->redirectQuery($redirect);
        $this->assertSame(['code', 'state', 'iss'], array_keys($query));
        $this->assertSame('xyz-123', $query['state']);
        $this->assertSame($this->adminHost, $query['iss']);
        Mail::assertQueued(OperatorSecurityMail::class, static fn (OperatorSecurityMail $mail): bool => $mail->kind === OperatorSecurityMail::AGENT_TOKEN_ISSUED && $mail->subjectName === 'OAuth で client.example から');

        // コードは持たず、ハッシュだけ
        $this->assertStringNotContainsString($query['code'], json_encode(DB::table('agent_oauth_requests')->get()) ?: '');

        $tokens = $this->exchange($client, $query['code'], $verifier, ['resource' => $this->adminHost.'/mcp'])->assertOk();
        $this->assertStringContainsString('no-store', (string) $tokens->headers->get('Cache-Control'));
        $this->assertSame('Bearer', $tokens->json('token_type'));
        $this->assertSame(3600, $tokens->json('expires_in'));
        $this->assertSame('read', $tokens->json('scope'));
        $this->assertMatchesRegularExpression('/\Aecs_refresh_[A-Za-z0-9_-]{43}\z/', (string) $tokens->json('refresh_token'));
        $access = (string) $tokens->json('access_token');

        // 出した鍵は鍵の表の行（OAuth の印・クライアント・名前・1 時間）。ハッシュだけ
        $row = AgentToken::query()->sole();
        $this->assertSame([hash('sha256', $access), null, 'read', 'Claude', $client], [$row->token_hash, $row->tenant_id, $row->scope, $row->name, $row->oauth_client_id]);
        $this->assertNotNull($row->oauth_grant_id);
        // 店は鍵でなく系統が持つ（ADR 0104）
        $this->assertSame([self::SHOP], AgentOAuthGrants::shopsOf((string) $row->oauth_grant_id));
        $this->assertEqualsWithDelta(3600, $row->created_at->diffInSeconds($row->expires_at), 5);
        $this->assertSame(0, DB::table('agent_oauth_refresh_tokens')->where('token_hash', $tokens->json('refresh_token'))->count());

        // 鍵で /mcp に入れる。読む道具だけ（読むだけの許可）
        $names = array_column((array) $this->mcp($access, 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertContains('search_products', $names);
        $this->assertNotContains('create_product', $names);

        // 一覧には系統として出る（1 時間ごとの鍵は出さない）。取り消せる
        $list = $this->send('GET', '/api/agent-tokens')->assertOk();
        $this->assertSame([], $list->json('tokens'));
        $this->assertSame(['Claude', 'client.example', 'read', true], [$list->json('grants.0.client_name'), $list->json('grants.0.host'), $list->json('grants.0.scope'), $list->json('grants.0.usable')]);
        $this->assertSame([['id' => self::SHOP, 'name' => '菓子の店']], $list->json('grants.0.shops'));

        $types = AuditEvent::query()->where('sequence', '>', $this->auditFrom)->pluck('type')->all();
        $this->assertContains('operator.agent_oauth_client_registered', $types);
        $this->assertContains('operator.agent_oauth_approved', $types);
        $this->assertContains('operator.agent_oauth_token_issued', $types);
    }

    public function test_pkce_is_required_with_s256_and_a_wrong_verifier_burns_the_code(): void
    {
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();

        // 頼みの誤りは、登録した行き先へ error と state を返す（PKCE が無い・plain・形が違う・response_type・resource）
        foreach ([
            ['code_challenge' => null],
            ['code_challenge_method' => 'plain'],
            ['code_challenge_method' => null],
            ['code_challenge' => 'short'],
        ] as $bad) {
            $location = $this->authorizeResponse($client, $challenge, ['state' => 's1'] + $bad)->assertRedirect()->headers->get('Location');
            $this->assertStringStartsWith(self::REDIRECT.'?', (string) $location);
            $this->assertSame(['error' => 'invalid_request', 'state' => 's1'], array_intersect_key($this->redirectQuery((string) $location), ['error' => 1, 'state' => 1]));
        }

        $this->assertSame('unsupported_response_type', $this->redirectQuery((string) $this->authorizeResponse($client, $challenge, ['response_type' => 'token'])->headers->get('Location'))['error']);
        $this->assertSame('invalid_target', $this->redirectQuery((string) $this->authorizeResponse($client, $challenge, ['resource' => 'https://other.example/mcp'])->headers->get('Location'))['error']);
        $this->assertSame(0, AgentOAuthRequest::query()->count());

        // 違う verifier は断り、コードは燃える（正しい verifier でももう使えない）
        $code = $this->approvedCode($client, $challenge);
        $this->exchange($client, $code, str_repeat('x', 43))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->exchange($client, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // verifier が無い・短いは要求の誤り
        $code = $this->approvedCode($client, $challenge);
        $this->exchange($client, $code, null)->assertStatus(400)->assertJsonPath('error', 'invalid_request');
        $this->exchange($client, $code, 'short')->assertStatus(400)->assertJsonPath('error', 'invalid_request');
        $this->exchange($client, $code, $verifier)->assertOk();
        $this->assertSame(1, AgentToken::query()->count());
    }

    public function test_a_code_works_once_for_ten_minutes_and_only_for_its_client(): void
    {
        $this->signIn();
        $client = $this->clientId();
        $other = $this->clientId('ほかのアプリ');
        [$verifier, $challenge] = $this->pkce();

        // 2 回目は断り、1 回目で出した系統も取り消す（横取りされたかもしれない）
        $code = $this->approvedCode($client, $challenge);
        $access = (string) $this->exchange($client, $code, $verifier)->assertOk()->json('access_token');
        $this->mcp($access, 'tools/list')->assertOk();
        $this->exchange($client, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->mcp($access, 'tools/list')->assertUnauthorized();
        $this->assertSame('code_reused', AgentOAuthGrant::query()->sole()->revoked_reason);

        // 10 分で切れる
        $code = $this->approvedCode($client, $challenge);
        $this->travel(11)->minutes();
        $this->exchange($client, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->travelBack();

        // ほかのクライアントでは使えない。知らないクライアントは 401
        $code = $this->approvedCode($client, $challenge);
        $this->exchange($other, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->exchange('01aaaaaaaaaaaaaaaaaaaaaaaa', $code, $verifier)->assertStatus(401)->assertJsonPath('error', 'invalid_client');
        $this->machine('POST', '/oauth/token', ['grant_type' => 'password', 'client_id' => $client])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
    }

    public function test_the_redirect_uri_must_match_the_registration_exactly(): void
    {
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();

        // 似た URL・問いの付け足し・フラグメント・大文字・http は、行き先へ送らず許可の画面に誤りだけを出す
        foreach ([self::REDIRECT.'/', self::REDIRECT.'?x=1', self::REDIRECT.'#f', 'https://CLIENT.example/api/mcp/auth_callback', 'https://client.example.evil.example/api/mcp/auth_callback', 'http://client.example/api/mcp/auth_callback', null] as $bad) {
            $location = (string) $this->authorizeResponse($client, $challenge, ['redirect_uri' => $bad])->assertRedirect()->headers->get('Location');
            $this->assertSame($this->adminHost.'/oauth/consent?error=invalid_redirect_uri', $location, (string) $bad);
        }

        $this->assertSame($this->adminHost.'/oauth/consent?error=invalid_client', (string) $this->authorizeResponse('01aaaaaaaaaaaaaaaaaaaaaaaa', $challenge)->headers->get('Location'));
        $this->assertSame(0, AgentOAuthRequest::query()->count());

        // 鍵の受け渡しでも、頼みの時と同じ行き先だけ
        $code = $this->approvedCode($client, $challenge);
        $this->exchange($client, $code, $verifier, ['redirect_uri' => self::REDIRECT.'?x=1'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_the_consent_screen_offers_write_only_to_operate_level_and_denial_returns_an_error_with_state(): void
    {
        [$staff, $authenticator] = $this->signIn('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'read', 'test');
        $client = $this->clientId();
        [, $challenge] = $this->pkce();
        $this->reconfirm($staff, $authenticator);

        $handle = $this->authorize($client, $challenge, ['state' => 'st', 'scope' => 'read write']);
        $detail = $this->send('GET', "/api/oauth-requests/{$handle}")->assertOk();
        // 見られる店だけ・閲覧の人には書けるを出さない
        $this->assertSame([['id' => self::SHOP, 'name' => '菓子の店', 'level' => 'read', 'can_write' => false]], $detail->json('request.tenants'));
        $this->assertSame('write', $detail->json('request.requested_scope'));

        $this->send('POST', "/api/oauth-requests/{$handle}/approve", ['tenants' => [self::SHOP], 'scope' => 'write'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->send('POST', "/api/oauth-requests/{$handle}/approve", ['tenants' => [self::OTHER], 'scope' => 'read'])->assertStatus(422)->assertJsonValidationErrors('tenants');
        $this->send('POST', "/api/oauth-requests/{$handle}/approve", ['tenants' => [self::SHOP], 'scope' => 'admin'])->assertStatus(422)->assertJsonValidationErrors('scope');

        // 拒否は登録した行き先へ access_denied と state
        $redirect = (string) $this->send('POST', "/api/oauth-requests/{$handle}/deny")->assertOk()->json('redirect_to');
        $this->assertStringStartsWith(self::REDIRECT.'?', $redirect);
        $query = $this->redirectQuery($redirect);
        $this->assertSame(['access_denied', 'st'], [$query['error'], $query['state']]);

        // 決めた後は許可も拒否もできない
        $this->send('POST', "/api/oauth-requests/{$handle}/approve", ['tenants' => [self::SHOP], 'scope' => 'read'])->assertNotFound();
        $this->send('GET', "/api/oauth-requests/{$handle}")->assertNotFound();

        // 10 分で切れる
        $handle = $this->authorize($client, $challenge);
        $this->travel(11)->minutes();
        $this->send('GET', "/api/oauth-requests/{$handle}")->assertNotFound();
        $this->travelBack();

        // ログインしていない人には中身を見せない
        $handle = $this->authorize($client, $challenge);
        $this->send('POST', '/auth/logout');
        $this->send('GET', "/api/oauth-requests/{$handle}")->assertUnauthorized();
    }

    public function test_a_write_grant_gets_the_write_tools_and_follows_the_current_level(): void
    {
        [$staff, $authenticator] = $this->signIn('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'operate', 'test');
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $this->reconfirm($staff, $authenticator);

        $code = $this->approvedCode($client, $challenge, 'write');
        $tokens = $this->exchange($client, $code, $verifier)->assertOk();
        $this->assertSame('read write', $tokens->json('scope'));
        $names = array_column((array) $this->mcp((string) $tokens->json('access_token'), 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertContains('create_product', $names);
        // 段階の中だけ（運用の人には売上もお金の道具も出ない）
        $this->assertNotContains('sales_summary', $names);
        $this->assertNotContains('refund_order', $names);

        // 権限を下げると、アクセストークンもリフレッシュトークンも切れる
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'read', 'test');
        $this->mcp((string) $tokens->json('access_token'), 'tools/list')->assertUnauthorized();
        $this->refresh($client, (string) $tokens->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame('grant_lowered', AgentOAuthGrant::query()->sole()->revoked_reason);
    }

    public function test_one_grant_can_cover_several_shops_and_each_call_enters_only_the_shop_it_names(): void
    {
        $this->tenant('oauc', '三軒目');
        [$staff, $authenticator] = $this->signIn('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'money', 'test');
        app(OperatorAccess::class)->grant($staff, self::OTHER, 'read', 'test');
        app(OperatorAccess::class)->grant($staff, 'oauc', 'read', 'test');
        $this->listing(self::SHOP, '菓子の店の品');
        $theirs = $this->listing(self::OTHER, 'となりの店の品');
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $this->reconfirm($staff, $authenticator);

        // 2 店を選んで許可する（三軒目は選ばない）。系統は店の ID の順に 2 店を持つ
        $access = (string) $this->exchange($client, $this->approvedCode($client, $challenge, 'read', [self::OTHER, self::SHOP]), $verifier)->assertOk()->json('access_token');
        $this->assertSame([self::SHOP, self::OTHER], AgentOAuthGrants::shopsOf(AgentOAuthGrant::query()->sole()->id));
        $this->assertSame([[self::SHOP, self::OTHER]], AgentOAuthRequest::query()->whereNotNull('tenant_ids')->pluck('tenant_ids')->all());

        // 道具の一覧（店に入らない）: list_shops と、どれかの店で使える道具（お金の店があるので売上も出る）
        $names = array_column((array) $this->mcp($access, 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertContains('list_shops', $names);
        $this->assertContains('search_products', $names);
        $this->assertContains('sales_summary', $names);

        // list_shops: 選んだ店と、店ごとの今の段階（名前は店の人が書いた文として包む）
        $this->assertSame([
            ['id' => self::SHOP, 'name' => ['untrusted_text' => '菓子の店'], 'level' => 'money', 'can_write' => false],
            ['id' => self::OTHER, 'name' => ['untrusted_text' => 'となりの店'], 'level' => 'read', 'can_write' => false],
        ], $this->tool($access, 'list_shops')['shops']);

        // shop が無い: どの店にも入らずに断る（届く店の ID を添える）
        $missing = $this->toolRefusal($access, 'search_products');
        $this->assertStringContainsString('more than one shop', $missing);
        $this->assertStringContainsString(self::SHOP.', '.self::OTHER, $missing);

        // 系統に無い店・無い店・文字でない値: 断る（渡された値は返さない）
        foreach (['oauc', 'nope', 123, ['x']] as $bad) {
            $text = $this->toolRefusal($access, 'search_products', ['shop' => $bad]);
            $this->assertStringContainsString('cannot use that shop', $text);
            $this->assertStringNotContainsString('oauc', $text);
        }

        // 店を選ぶと、その店のデータだけ。答えにどの店かが付く
        $mine = $this->tool($access, 'search_products', ['shop' => self::SHOP]);
        $this->assertSame(['id' => self::SHOP, 'name' => ['untrusted_text' => '菓子の店']], $mine['shop']);
        $this->assertSame(['菓子の店の品'], array_column(array_column($mine['result'], 'title'), 'untrusted_text'));
        $other = $this->tool($access, 'search_products', ['shop' => self::OTHER]);
        $this->assertSame(self::OTHER, $other['shop']['id']);
        $this->assertSame(['となりの店の品'], array_column(array_column($other['result'], 'title'), 'untrusted_text'));

        // ほかの店の商品の ID は、選んだ店からは見えない（店の DB をまたがない）
        $this->assertSame('Not found.', $this->toolRefusal($access, 'get_product', ['shop' => self::SHOP, 'id' => $theirs]));
        $this->assertSame($theirs, $this->tool($access, 'get_product', ['shop' => self::OTHER, 'id' => $theirs])['result']['id']);

        // 段階は店ごと: お金の店では売上を読めるが、閲覧の店では断る
        $this->assertArrayHasKey('totals', $this->tool($access, 'sales_summary', ['shop' => self::SHOP, 'period' => 'month'])['result']);
        // （閲覧の店に入った要求では売上の道具が一覧に無いので、MCP の誤りか道具の誤りのどちらかで断る。売上は返さない）
        $refused = $this->mcp($access, 'tools/call', ['name' => 'sales_summary', 'arguments' => ['shop' => self::OTHER, 'period' => 'month']]);
        $this->assertTrue($refused->json('error') !== null || (bool) $refused->json('result.isError'), (string) $refused->getContent());
        $this->assertStringNotContainsString('totals', (string) $refused->getContent());

        // 監査ログの店は、その呼び出しで入った店（入らなかった呼び出しは空）
        $shops = AuditEvent::query()->where('sequence', '>', $this->auditFrom)->where('type', 'operator.agent_request')->pluck('tenant_id')->all();
        $this->assertContains(null, $shops);
        $this->assertContains(self::SHOP, $shops);
        $this->assertContains(self::OTHER, $shops);
        $this->assertNotContains('oauc', $shops);
    }

    public function test_approving_several_shops_checks_every_shop_and_refuses_bad_lists(): void
    {
        $this->tenant('oauc', '三軒目');
        [$staff, $authenticator] = $this->signIn('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'operate', 'test');
        app(OperatorAccess::class)->grant($staff, self::OTHER, 'read', 'test');
        $client = $this->clientId();
        [, $challenge] = $this->pkce();
        $handle = $this->authorize($client, $challenge, ['state' => 'st']);
        $this->reconfirm($staff, $authenticator);
        $approve = fn (array $body) => $this->send('POST', "/api/oauth-requests/{$handle}/approve", $body + ['scope' => 'read']);

        // 選ばない・重なり・文字でない・配列でない・前の形（tenant）・多すぎる・無い店・見られない店: どれも断る（頼みはそのまま待つ）
        $many = array_map(static fn (int $i): string => 'shop'.$i, range(1, AgentOAuthGrants::MAX_SHOPS + 1));
        foreach ([[], [self::SHOP, self::SHOP], [self::SHOP, 5], ['a' => self::SHOP], self::SHOP, $many, ['nope'], ['oauc'], [self::SHOP, 'oauc']] as $bad) {
            $approve(['tenants' => $bad])->assertStatus(422)->assertJsonValidationErrors('tenants');
        }
        $approve(['tenant' => self::SHOP])->assertStatus(422)->assertJsonValidationErrors('tenants');

        // 書けるは、選んだ店の全部で運用以上の時だけ
        $approve(['tenants' => [self::SHOP, self::OTHER], 'scope' => 'write'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->assertNull(AgentOAuthRequest::query()->sole()->decided_at);
        $approve(['tenants' => [self::SHOP], 'scope' => 'write'])->assertOk();
        $this->assertSame([[self::SHOP], 'write'], [AgentOAuthRequest::query()->sole()->tenant_ids, AgentOAuthRequest::query()->sole()->scope]);
    }

    public function test_a_write_grant_over_several_shops_always_proposes_and_numeric_shop_ids_stay_strings(): void
    {
        // 数字だけの店の ID（配列のキーでは数になる。PR 187 の見直しの低 2）
        $this->tenant('4242', '数字の店');
        [$staff, $authenticator] = $this->signIn('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'operate', 'test');
        app(OperatorAccess::class)->grant($staff, '4242', 'operate', 'test');
        // 店の設定は「すぐ反映」でも
        $this->inside(self::SHOP, static fn () => app(AgentSettings::class)->update(['write_mode' => 'immediate', 'customer_data' => false], 'test'));
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $this->reconfirm($staff, $authenticator);
        $access = (string) $this->exchange($client, $this->approvedCode($client, $challenge, 'write', [self::SHOP, '4242']), $verifier)->assertOk()->json('access_token');

        // list_shops の ID は文字。そのまま shop に渡せる
        $this->assertSame(['4242', self::SHOP], array_column($this->tool($access, 'list_shops')['shops'], 'id'));
        $this->assertSame('4242', $this->tool($access, 'search_products', ['shop' => '4242'])['shop']['id']);

        // 2 店のつなぎの書く道具は、店の設定にかかわらず案を作るだけ（ほかの店で読んだ文にそそのかされた変更を人の承認で止める。ADR 0104）
        $created = $this->tool($access, 'create_product', ['shop' => self::SHOP, 'title' => '季節のジャム', 'price' => 1200]);
        $this->assertSame('proposed', $created['result']['status']);
        $this->assertSame(0, $this->inside(self::SHOP, static fn (): int => Listing::query()->count()));
    }

    public function test_lowering_a_level_kills_an_approved_code_for_a_grant_over_several_shops(): void
    {
        [$staff, $authenticator] = $this->signIn('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'money', 'test');
        app(OperatorAccess::class)->grant($staff, self::OTHER, 'read', 'test');
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $this->reconfirm($staff, $authenticator);
        $code = $this->approvedCode($client, $challenge, 'read', [self::SHOP, self::OTHER]);

        // 交換の前に菓子の店をお金から閲覧へ下げる。読むだけの系統は段階が残っていれば交換の確かめを通るので、止めるのは許可済みのコードを店で探す道だけ
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'read', 'test');
        $this->exchange($client, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, AgentOAuthGrant::query()->count());
    }

    public function test_losing_access_to_one_of_the_shops_revokes_the_whole_grant(): void
    {
        [$staff, $authenticator] = $this->signIn('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'read', 'test');
        app(OperatorAccess::class)->grant($staff, self::OTHER, 'read', 'test');
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $this->reconfirm($staff, $authenticator);
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge, 'read', [self::SHOP, self::OTHER]), $verifier)->assertOk();
        $this->assertSame(self::SHOP, $this->tool((string) $tokens->json('access_token'), 'search_products', ['shop' => self::SHOP])['shop']['id']);

        // となりの店の権限を外すと、菓子の店の分ごと系統が切れる（店を黙って減らさない。ADR 0104）
        app(OperatorAccess::class)->grant($staff, self::OTHER, null, 'test');
        $this->assertCut($client, $tokens);
        $this->assertSame('grant_lowered', AgentOAuthGrant::query()->sole()->revoked_reason);
    }

    public function test_access_tokens_last_an_hour_and_refresh_tokens_rotate_and_a_reused_one_revokes_the_grant(): void
    {
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $first = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();

        $this->travel(61)->minutes();
        $this->mcp((string) $first->json('access_token'), 'tools/list')->assertUnauthorized();

        // 入れ替え: 新しい組が出て、新しいアクセストークンで入れる
        $second = $this->refresh($client, (string) $first->json('refresh_token'))->assertOk();
        $this->assertNotSame($first->json('refresh_token'), $second->json('refresh_token'));
        $this->mcp((string) $second->json('access_token'), 'tools/list')->assertOk();

        // 入れ替えの直後（猶予の秒の中）の同じクライアントからの再送は、断るだけで系統は取り消さない（答えを受け取り損ねた送り直し）
        $this->refresh($client, (string) $first->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->mcp((string) $second->json('access_token'), 'tools/list')->assertOk();
        $this->assertNull(AgentOAuthGrant::query()->sole()->revoked_at);
        $this->assertSame(1, AuditEvent::query()->where('sequence', '>', $this->auditFrom)->where('type', 'operator.agent_oauth_refresh_retried')->count());

        // 猶予の後の使い回し: 前のリフレッシュトークンがまた来たら、系統ごと取り消す（今のアクセストークンも、今のリフレッシュトークンも）
        $this->travel(AgentOAuthGrants::GRACE_SECONDS + 1)->seconds();
        $this->refresh($client, (string) $first->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->mcp((string) $second->json('access_token'), 'tools/list')->assertUnauthorized();
        $this->refresh($client, (string) $second->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame('refresh_reused', AgentOAuthGrant::query()->sole()->revoked_reason);
        $this->assertSame(1, AuditEvent::query()->where('sequence', '>', $this->auditFrom)->where('type', 'operator.agent_oauth_reuse_detected')->count());
        $this->travelBack();

        // 30 日で切れる
        $third = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        $this->travel(31)->days();
        $this->refresh($client, (string) $third->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->travelBack();

        // ほかのクライアントが出したリフレッシュトークンは系統ごと取り消す
        $other = $this->clientId('ほか');
        $fourth = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        $this->refresh($other, (string) $fourth->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->refresh($client, (string) $fourth->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->mcp((string) $fourth->json('access_token'), 'tools/list')->assertUnauthorized();
    }

    public function test_logging_out_everywhere_revoking_in_the_list_the_revocation_endpoint_and_disabling_cut_access_and_refresh(): void
    {
        [$owner] = $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();

        // ほかの端末を全部ログアウト
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        $this->send('POST', '/auth/sessions/others')->assertOk();
        $this->assertCut($client, $tokens);

        // 管理画面の一覧から取り消す（本人かオーナー。ほかのスタッフには 404）
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        $grant = AgentOAuthGrant::query()->whereNull('revoked_at')->sole();
        $this->send('DELETE', "/api/agent-grants/{$grant->id}")->assertNoContent();
        $this->assertCut($client, $tokens);

        // 古い一覧の口（鍵の行の取り消し）でも系統ごと
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        $row = AgentToken::query()->whereNull('revoked_at')->sole();
        $this->send('DELETE', "/api/agent-tokens/{$row->id}")->assertNoContent();
        $this->assertCut($client, $tokens);

        // クライアントからの取り消し（RFC 7009）。ほかのクライアントの取り消しは効かない
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        $other = $this->clientId('ほか');
        $this->machine('POST', '/oauth/revoke', ['token' => $tokens->json('refresh_token'), 'client_id' => $other])->assertOk();
        $this->mcp((string) $tokens->json('access_token'), 'tools/list')->assertOk();
        $this->machine('POST', '/oauth/revoke', ['token' => $tokens->json('access_token'), 'client_id' => $client])->assertOk();
        $this->assertCut($client, $tokens);
        // 知らない鍵も同じ答え
        $this->machine('POST', '/oauth/revoke', ['token' => 'ecs_refresh_'.str_repeat('A', 43), 'client_id' => $client])->assertOk();

        // 運営を止める（入れ替えの時にも引き直す）。鍵の表の取り消しの道を通らなくても、系統を取り消す
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        Operator::query()->whereKey($owner->id)->update(['disabled_at' => CarbonImmutable::now()]);
        $this->mcp((string) $tokens->json('access_token'), 'tools/list')->assertUnauthorized();
        $this->refresh($client, (string) $tokens->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame('access_lost', AgentOAuthGrant::query()->findOrFail(AgentToken::query()->where('token_hash', hash('sha256', (string) $tokens->json('access_token')))->value('oauth_grant_id'))->revoked_reason);
    }

    public function test_connecting_the_same_client_again_replaces_the_old_grant(): void
    {
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();

        $first = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        $second = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();

        $this->mcp((string) $first->json('access_token'), 'tools/list')->assertUnauthorized();
        $this->mcp((string) $second->json('access_token'), 'tools/list')->assertOk();
        $this->assertSame(['replaced', null], AgentOAuthGrant::query()->orderBy('created_at')->pluck('revoked_reason')->all());
    }

    public function test_the_consent_screen_stays_behind_the_address_fence_but_the_machine_endpoints_do_not(): void
    {
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        $handle = $this->authorize($client, $challenge);

        config(['admin.allowed_ips' => ['10.1.2.3/32']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);

        // 外の接続元からは、許可の画面（頼みの受付・画面の殻・API）とログインに入れない
        $this->authorizeResponse($client, $challenge)->assertNotFound();
        $this->get($this->adminHost.'/oauth/consent/'.$handle)->assertNotFound();
        $this->send('GET', "/api/oauth-requests/{$handle}")->assertNotFound();
        $this->send('GET', '/auth/login/options')->assertNotFound();

        // 機械の受け口と /mcp には届く
        $this->machine('GET', '/.well-known/oauth-authorization-server')->assertOk();
        $this->machine('GET', '/.well-known/oauth-protected-resource/mcp')->assertOk();
        $this->register(['redirect_uris' => [self::REDIRECT]], '192.0.2.10')->assertCreated();
        $refreshed = $this->refresh($client, (string) $tokens->json('refresh_token'))->assertOk();
        $this->mcp((string) $refreshed->json('access_token'), 'tools/list')->assertOk();

        // 本番で接続元の制限が空でも、機械の受け口は閉じない（許可の画面は閉じる）
        config(['admin.allowed_ips' => []]);
        $this->app->instance('env', 'production');
        $this->machine('GET', '/.well-known/oauth-authorization-server')->assertOk();
        $this->authorizeResponse($client, $challenge)->assertNotFound();
    }

    public function test_the_daily_prune_removes_used_up_requests_and_unused_clients_but_keeps_grants(): void
    {
        $this->signIn();
        $used = $this->clientId('つないだ');
        $unused = $this->clientId('使われない');
        [$verifier, $challenge] = $this->pkce();
        $this->exchange($used, $this->approvedCode($used, $challenge), $verifier)->assertOk();

        // 8 日では、期限の切れた OAuth の鍵の行はまだ残す（その鍵で作った案〔7 日〕の承認の確かめに要る）
        $this->travel(8)->days();
        $this->artisan('agent:prune-oauth')->assertSuccessful();
        $this->assertSame(1, AgentToken::query()->count());
        $this->travel(1)->days();
        $this->artisan('agent:prune-oauth')->assertSuccessful();

        // 使われない登録と使い終わった頼みは消え、系統と系統を持つクライアントは残る
        $this->assertSame([$used], DB::table('agent_oauth_clients')->pluck('id')->all());
        $this->assertSame(0, AgentOAuthRequest::query()->count());
        $this->assertSame(1, AgentOAuthGrant::query()->count());
        $this->assertNotSame($used, $unused);
        $this->assertSame(0, AgentToken::query()->count());
    }

    public function test_failures_are_counted_per_ipv6_network_so_changing_the_address_does_not_escape(): void
    {
        $wrong = 'ecs_agent_'.str_repeat('B', 43);

        // 同じ /64 の中で住所を替えても、20 回で 429。断った回の監査ログは接続元ごとに 1 分 1 行
        for ($i = 1; $i <= AuthenticateAgent::FAILURES_PER_MINUTE; $i++) {
            $this->from6('2001:db8:1:1::'.dechex($i))->mcp($wrong, 'tools/list')->assertUnauthorized();
        }
        $this->from6('2001:db8:1:1::ffff')->mcp($wrong, 'tools/list')->assertStatus(429);
        $this->assertSame(1, AuditEvent::query()->where('sequence', '>', $this->auditFrom)->where('type', 'operator.agent_denied')->count());

        // /64 を替えても、同じ /48 の中では合わせて 5 倍で 429
        $wide = AuthenticateAgent::FAILURES_PER_MINUTE * AgentFailures::WIDE_FACTOR;
        for ($n = 2; $n <= $wide - AuthenticateAgent::FAILURES_PER_MINUTE + 1; $n++) {
            $this->from6('2001:db8:1:'.dechex($n).'::1')->mcp($wrong, 'tools/list')->assertUnauthorized();
        }
        $this->from6('2001:db8:1:ffff::1')->mcp($wrong, 'tools/list')->assertStatus(429);
        // ほかの /48 は数えが分かれる
        $this->from6('2001:db8:2:1::1')->mcp($wrong, 'tools/list')->assertUnauthorized();

        // 鍵の受け渡しも同じ（/64 の中で住所を替えても 30 回で 429）
        $client = $this->clientId();
        for ($i = 1; $i <= OAuthController::FAILURES_PER_MINUTE; $i++) {
            $this->from6('2001:db8:3:1::'.dechex($i))->refresh($client, 'ecs_refresh_'.str_repeat('C', 43))->assertStatus(400);
        }
        $this->from6('2001:db8:3:1::ffff')->refresh($client, 'ecs_refresh_'.str_repeat('C', 43))->assertStatus(429);
    }

    public function test_keys_from_one_grant_share_the_rate_limits_and_only_two_stay_live(): void
    {
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $first = $this->exchange($client, $this->approvedCode($client, $challenge, 'write'), $verifier)->assertOk();
        $a = (string) $first->json('access_token');

        // 1 分 60 回は系統で数える（入れ替えた鍵で上限を割らせない）
        for ($i = 0; $i < AuthenticateAgent::PER_MINUTE / 2; $i++) {
            $this->mcp($a, 'tools/list')->assertOk();
        }
        $second = $this->refresh($client, (string) $first->json('refresh_token'))->assertOk();
        $b = (string) $second->json('access_token');
        for ($i = 0; $i < AuthenticateAgent::PER_MINUTE / 2; $i++) {
            $this->mcp($b, 'tools/list')->assertOk();
        }
        $this->mcp($b, 'tools/list')->assertStatus(429);
        $this->mcp($a, 'tools/list')->assertStatus(429);

        // 書く道具の 1 分 10 回も系統で数える（断られた呼び出しも数える）
        $this->travel(2)->minutes();
        for ($i = 0; $i < AgentWriteTool::PER_MINUTE / 2; $i++) {
            $this->mcp($a, 'tools/call', ['name' => 'create_product', 'arguments' => ['title' => "缶 {$i}", 'price' => 1000]])->assertOk();
            $this->mcp($b, 'tools/call', ['name' => 'create_product', 'arguments' => ['title' => "瓶 {$i}", 'price' => 1000]])->assertOk();
        }
        $over = $this->mcp($b, 'tools/call', ['name' => 'create_product', 'arguments' => ['title' => '多すぎ', 'price' => 1000]])->assertOk();
        $this->assertTrue((bool) $over->json('result.isError'));
        $this->assertStringContainsString('Too many changes', (string) $over->json('result.content.0.text'));

        // 監査ログには系統が入る（おかしな動きは系統で数える）
        $grant = AgentOAuthGrant::query()->sole();
        $this->assertSame([$grant->id], AuditEvent::query()->where('sequence', '>', $this->auditFrom)->where('type', 'operator.agent_request')->get()->map(static fn (AuditEvent $e): mixed => $e->data['grant'] ?? null)->unique()->values()->all());

        // 同時に生きる鍵は 2 本まで: もう一度入れ替えると、いちばん古い A は期限が今になる（取り消しではないので、系統は生きている）
        $this->travel(2)->minutes();
        $third = $this->refresh($client, (string) $second->json('refresh_token'))->assertOk();
        $this->mcp($a, 'tools/list')->assertUnauthorized();
        $this->mcp($b, 'tools/list')->assertOk();
        $this->mcp((string) $third->json('access_token'), 'tools/list')->assertOk();
        $this->assertNull(AgentToken::query()->where('token_hash', hash('sha256', $a))->value('revoked_at'));
        $this->assertNull($grant->fresh()?->revoked_at);
        $this->refresh($client, (string) $third->json('refresh_token'))->assertOk();
    }

    public function test_a_grant_has_an_absolute_term_and_refresh_can_narrow_the_scope(): void
    {
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge, 'write'), $verifier)->assertOk();
        $grant = AgentOAuthGrant::query()->sole();
        $this->assertEqualsWithDelta(AgentOAuthGrants::WRITE_GRANT_DAYS * 86400, $grant->created_at->diffInSeconds($grant->expires_at), 5);

        // 狭い種類を頼まれたら狭く出す（系統は書けるのまま。次の入れ替えで書けるに戻せる）
        $narrow = $this->machine('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens->json('refresh_token'), 'client_id' => $client, 'scope' => 'read'])->assertOk();
        $this->assertSame('read', $narrow->json('scope'));
        $this->assertNotContains('create_product', array_column((array) $this->mcp((string) $narrow->json('access_token'), 'tools/list')->json('result.tools'), 'name'));
        $tokens = $this->refresh($client, (string) $narrow->json('refresh_token'))->assertOk();
        $this->assertSame('read write', $tokens->json('scope'));

        // 30 日ごとに入れ替えても、書ける系統は 90 日で切れる（リフレッシュトークンの期限も系統の期限を越えない）
        for ($i = 0; $i < 3; $i++) {
            $this->travel(29)->days();
            $tokens = $this->refresh($client, (string) $tokens->json('refresh_token'))->assertOk();
        }
        $this->assertLessThanOrEqual($grant->expires_at->getTimestamp(), AgentOAuthRefreshToken::query()->whereNull('used_at')->sole()->expires_at->getTimestamp());
        $this->travel(4)->days();
        $this->refresh($client, (string) $tokens->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->travelBack();

        // リフレッシュトークンがまだ生きていても、系統の期限を過ぎたら断る
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge, 'write'), $verifier)->assertOk();
        $short = AgentOAuthGrant::query()->whereNull('revoked_at')->sole();
        AgentOAuthGrant::query()->whereKey($short->id)->update(['expires_at' => CarbonImmutable::now()->addMinute()]);
        $this->travel(2)->minutes();
        $this->assertTrue(AgentOAuthRefreshToken::query()->where('grant_id', $short->id)->whereNull('used_at')->sole()->expires_at->isFuture());
        $this->refresh($client, (string) $tokens->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->travelBack();

        // DB の CHECK も（書ける系統に 90 日より長い期限は置けない）
        $this->expectException(QueryException::class);
        AgentOAuthGrant::query()->whereKey($grant->id)->update(['expires_at' => $grant->created_at->addDays(100)]);
    }

    public function test_revoking_after_approval_and_before_the_exchange_kills_the_code(): void
    {
        [$staff] = $this->signIn('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'operate', 'test');
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();

        // ほかの端末を全部ログアウト（鍵の全部の取り消し）の後は、許可済みのコードも鍵に替えられない
        $code = $this->approvedCode($client, $challenge);
        $this->send('POST', '/auth/sessions/others')->assertOk();
        $this->exchange($client, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // 権限を下げた後も同じ（書けるで許可したコード）
        $code = $this->approvedCode($client, $challenge, 'write');
        app(OperatorAccess::class)->grant($staff, self::SHOP, 'read', 'test');
        $this->exchange($client, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, AgentToken::query()->count());
    }

    public function test_registration_is_limited_per_ipv6_network_and_only_unused_clients_fill_the_daily_cap(): void
    {
        // /48 の中で /64 を替えても、合わせて 5 倍まで
        $wide = OAuthController::REGISTER_PER_HOUR * AgentFailures::WIDE_FACTOR;
        for ($n = 1; $n <= $wide; $n++) {
            $this->register(['redirect_uris' => [self::REDIRECT]], '2001:db8:5:'.dechex($n).'::1')->assertCreated();
        }
        $this->register(['redirect_uris' => [self::REDIRECT]], '2001:db8:5:ffff::1')->assertStatus(429);

        // 全体の枠に数えるのは、頼みも系統も無い登録だけ（つないだ登録は数えない）。使われない登録は 1 時間で消えて枠が空く
        Cache::store()->flush();
        $this->signIn();
        $used = $this->clientId('つないだ');
        [$verifier, $challenge] = $this->pkce();
        $this->exchange($used, $this->approvedCode($used, $challenge), $verifier)->assertOk();
        $now = CarbonImmutable::now();
        DB::table('agent_oauth_clients')->insert(array_map(static fn (int $i): array => ['id' => strtolower((string) Str::ulid()), 'name' => 'x', 'redirect_uris' => json_encode([self::REDIRECT]), 'created_at' => $now, 'updated_at' => $now], range(1, AgentOAuthClients::GLOBAL_PER_DAY - $wide - 1)));
        $this->register(['redirect_uris' => [self::REDIRECT]], '198.51.100.7')->assertCreated();
        $this->register(['redirect_uris' => [self::REDIRECT]], '198.51.100.8')->assertStatus(429)->assertJsonPath('error', 'temporarily_unavailable');

        $this->travel(AgentOAuthClients::UNUSED_MINUTES + 1)->minutes();
        $this->artisan('agent:prune-oauth')->assertSuccessful();
        $this->assertSame([$used], DB::table('agent_oauth_clients')->pluck('id')->all());
        $this->register(['redirect_uris' => [self::REDIRECT]], '198.51.100.7')->assertCreated();
    }

    public function test_the_same_code_exchanged_at_once_gives_one_pair_of_keys(): void
    {
        $this->needsFork();
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $code = $this->approvedCode($client, $challenge);

        $results = $this->atOnce(4, static fn (): array => app(AgentOAuthGrants::class)->exchangeCode(['client_id' => $client, 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => self::REDIRECT]));

        $this->assertSame(1, count(array_filter($results, static fn (string $r): bool => $r === 'ok')), implode(',', $results));
        $this->assertSame(1, AgentOAuthGrant::query()->count());
        $this->assertSame(1, AgentToken::query()->count());
    }

    public function test_the_same_refresh_token_sent_at_once_rotates_once_without_revoking(): void
    {
        $this->needsFork();
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $refresh = (string) $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk()->json('refresh_token');

        $results = $this->atOnce(4, static fn (): array => app(AgentOAuthGrants::class)->refresh(['client_id' => $client, 'refresh_token' => $refresh]));

        $this->assertSame(1, count(array_filter($results, static fn (string $r): bool => $r === 'ok')), implode(',', $results));
        // 入れ替えの直後の再送なので、系統は取り消さない
        $this->assertNull(AgentOAuthGrant::query()->sole()->revoked_at);
        $this->assertSame(1, AgentOAuthRefreshToken::query()->whereNull('used_at')->count());
    }

    public function test_a_refresh_racing_a_revocation_leaves_no_live_key(): void
    {
        $this->needsFork();
        [$owner] = $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $refresh = (string) $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk()->json('refresh_token');
        $grantId = AgentOAuthGrant::query()->sole()->id;

        $this->atOnce(2, static function (int $i) use ($client, $refresh, $grantId, $owner): array {
            if ($i === 0) {
                return app(AgentOAuthGrants::class)->refresh(['client_id' => $client, 'refresh_token' => $refresh]);
            }

            app(AgentOAuthGrants::class)->revoke(AgentOAuthGrant::query()->findOrFail($grantId), $owner);

            return [];
        });

        $this->assertNotNull(AgentOAuthGrant::query()->sole()->revoked_at);
        $this->assertSame(0, AgentToken::query()->whereNull('revoked_at')->where('expires_at', '>', CarbonImmutable::now())->count());
    }

    public function test_failures_from_a_shared_address_do_not_block_valid_keys(): void
    {
        $this->signIn();
        $client = $this->clientId();
        [$verifier, $challenge] = $this->pkce();
        $tokens = $this->exchange($client, $this->approvedCode($client, $challenge), $verifier)->assertOk();
        $access = (string) $tokens->json('access_token');

        // /mcp: 同じ接続元から違う鍵が上限を越えても、正しい鍵は通る（クラウドのクライアントは出口の接続元を分け合う）
        for ($i = 0; $i < AuthenticateAgent::FAILURES_PER_MINUTE; $i++) {
            $this->mcp('ecs_agent_'.str_repeat('B', 43), 'tools/list')->assertUnauthorized();
        }
        $this->mcp('ecs_agent_'.str_repeat('B', 43), 'tools/list')->assertStatus(429);
        $this->mcp($access, 'tools/list')->assertOk();

        // 鍵の受け渡し: 断った回だけを数え、上限の後も正しい入れ替えは通る
        for ($i = 0; $i < OAuthController::FAILURES_PER_MINUTE; $i++) {
            $this->refresh($client, 'ecs_refresh_'.str_repeat('C', 43))->assertStatus(400);
        }
        $this->refresh($client, 'ecs_refresh_'.str_repeat('C', 43))->assertStatus(429);
        $this->refresh($client, (string) $tokens->json('refresh_token'))->assertOk();
    }

    /**
     * 道具を呼んで、答えの JSON を返す（誤りなら落とす）
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(string $access, string $name, array $arguments = []): array
    {
        $response = $this->mcp($access, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments])->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), $name.': '.json_encode($response->json('result.content')));
        $decoded = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertIsArray($decoded, $name);

        return $decoded;
    }

    /**
     * 道具を呼んで、断られた文を返す（通ったら落とす）
     *
     * @param  array<string, mixed>  $arguments
     */
    private function toolRefusal(string $access, string $name, array $arguments = []): string
    {
        $response = $this->mcp($access, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments])->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), $name.' should be refused: '.$response->getContent());

        return (string) $response->json('result.content.0.text');
    }

    private function listing(string $shop, string $title): string
    {
        return $this->inside($shop, static fn (): string => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 1000, 'status' => 'active'])->id);
    }

    /**
     * アクセストークンでもリフレッシュトークンでも、もう入れない
     *
     * @param  TestResponse<Response>  $tokens
     */
    private function assertCut(string $client, TestResponse $tokens): void
    {
        $this->mcp((string) $tokens->json('access_token'), 'tools/list')->assertUnauthorized();
        $this->refresh($client, (string) $tokens->json('refresh_token'))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    /**
     * 許可の頼みを出し、許可の画面で許可して、認可コードを返す（今のセッションの運営で。再確認は道具で済ませる）
     */
    /**
     * @param  list<string>|null  $shops  選ぶ店（既定は SHOP だけ）
     */
    private function approvedCode(string $client, string $challenge, string $scope = 'read', ?array $shops = null): string
    {
        $handle = $this->authorize($client, $challenge, ['state' => 'st']);
        $shops ??= [self::SHOP];
        $response = $this->send('POST', "/api/oauth-requests/{$handle}/approve", ['tenants' => $shops, 'scope' => $scope]);

        // 直前の再確認が切れていれば、ログインした運営のパスキーで確かめ直す
        if ($response->status() === 423 && $this->signedIn !== null) {
            $this->reconfirm(...$this->signedIn);
            $response = $this->send('POST', "/api/oauth-requests/{$handle}/approve", ['tenants' => $shops, 'scope' => $scope]);
        }

        return $this->redirectQuery((string) $response->assertOk()->json('redirect_to'))['code'];
    }

    /**
     * 運営を作ってログインし、再確認まで済ませる（approvedCode が再確認の切れた時に使う）
     *
     * @return array{0: Operator, 1: SoftwareAuthenticator}
     */
    private function signIn(string $email = 'hana@example.com', string $role = 'owner'): array
    {
        $this->signedIn = $this->enrolledOperator($email, $role);
        $this->reconfirm(...$this->signedIn);

        return $this->signedIn;
    }

    /** @var array{0: Operator, 1: SoftwareAuthenticator}|null */
    private ?array $signedIn = null;

    /**
     * @param  array<string, string|null>  $extra
     */
    private function authorize(string $client, string $challenge, array $extra = []): string
    {
        $location = (string) $this->authorizeResponse($client, $challenge, $extra)->assertRedirect()->headers->get('Location');
        $this->assertMatchesRegularExpression('#\A'.preg_quote($this->adminHost, '#').'/oauth/consent/[A-Za-z0-9_-]{43}\z#', $location);

        return substr($location, strrpos($location, '/') + 1);
    }

    /**
     * @param  array<string, string|null>  $extra
     * @return TestResponse<Response>
     */
    private function authorizeResponse(string $client, string $challenge, array $extra = []): TestResponse
    {
        $query = array_filter([
            'response_type' => 'code',
            'client_id' => $client,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            ...$extra,
        ], static fn (?string $value): bool => $value !== null);

        // 他サイト（claude.ai など）からの移動として開く。ブラウザが持っている今のセッションの Cookie を添える（実際は SameSite=Strict で付かない）
        if ($this->sessionId !== null) {
            $this->withCookie((string) config('admin.session_cookie'), $this->sessionId);
        }

        return $this->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->get($this->adminHost.'/oauth/authorize?'.http_build_query($query));
    }

    /**
     * @param  array<string, string>  $extra
     * @return TestResponse<Response>
     */
    private function exchange(string $client, string $code, ?string $verifier, array $extra = []): TestResponse
    {
        return $this->machine('POST', '/oauth/token', array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'client_id' => $client,
            'code_verifier' => $verifier,
            ...$extra,
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * @return TestResponse<Response>
     */
    private function refresh(string $client, string $refreshToken): TestResponse
    {
        return $this->machine('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => $client]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return TestResponse<Response>
     */
    private function register(array $metadata, string $ip = '203.0.113.5'): TestResponse
    {
        return $this->machine('POST', '/oauth/register', [], (string) json_encode($metadata), ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip]);
    }

    private function clientId(string $name = 'Claude'): string
    {
        return (string) $this->register(['client_name' => $name, 'redirect_uris' => [self::REDIRECT]])->assertCreated()->json('client_id');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    /**
     * @return array<string, string>
     */
    private function redirectQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /**
     * Cookie もセッションも持たない機械の要求（クライアントのサーバーから届く形）
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $server
     * @return TestResponse<Response>
     */
    private function machine(string $method, string $path, array $data = [], ?string $raw = null, array $server = []): TestResponse
    {
        $saved = $this->defaultCookies;
        $this->defaultCookies = [];
        $this->flushHeaders();

        try {
            return $this->call($method, $this->adminHost.$path, $data, [], [], ['HTTP_ACCEPT' => 'application/json', ...$server], $raw);
        } finally {
            $this->defaultCookies = $saved;
        }
    }

    /**
     * この後の要求を、この接続元（IPv6 の住所）から送る
     */
    private function from6(string $ip): self
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        return $this;
    }

    private function needsFork(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl が無い');
        }
    }

    /**
     * 別々の処理（fork）から同じ瞬間に $work を流し、子ごとの結果（ok か OAuth の誤りの名前）を返す。子はそれぞれ DB に繋ぎ直す
     *
     * @param  Closure(int): array<mixed>  $work
     * @return list<string>
     */
    private function atOnce(int $children, Closure $work): array
    {
        $dir = sys_get_temp_dir().'/o-oauth-'.Str::random(12);
        mkdir($dir);

        // 子に親の DB の接続を分け合わせない（それぞれ繋ぎ直す）
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }

        $start = microtime(true) + 1.0;
        $pids = [];

        for ($i = 0; $i < $children; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                time_sleep_until($start);

                try {
                    $work($i);
                    $result = 'ok';
                } catch (OAuthError $e) {
                    $result = $e->error;
                } catch (Throwable $e) {
                    $result = 'error:'.$e::class.':'.$e->getMessage();
                }

                file_put_contents("{$dir}/{$i}", $result);
                // 親のテストの後始末（DB の接続を閉じるなど）を子で走らせない
                posix_kill(posix_getpid(), SIGKILL);
            }

            $this->assertGreaterThan(0, $pid, '子の処理を作れなかった');
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        for ($i = 0; $i < $children; $i++) {
            $results[] = is_file("{$dir}/{$i}") ? (string) file_get_contents("{$dir}/{$i}") : 'missing';
            @unlink("{$dir}/{$i}");
        }

        rmdir($dir);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return TestResponse<Response>
     */
    private function mcp(?string $secret, string $method, array $params = []): TestResponse
    {
        $saved = $this->defaultCookies;
        $this->defaultCookies = [];
        $this->flushHeaders();

        try {
            return $this->postJson($this->adminHost.'/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], array_filter([
                'Authorization' => $secret === null ? null : 'Bearer '.$secret,
                'Accept' => 'application/json, text/event-stream',
            ]));
        } finally {
            $this->defaultCookies = $saved;
        }
    }
}
