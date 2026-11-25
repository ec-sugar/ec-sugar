<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Admin\Http\Middleware\AuthenticateAgent;
use App\Admin\Http\Middleware\EnsureOperatorIsActive;
use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\Middleware\RestrictAdminIp;
use App\Core\Plugin\PluginRoutes;
use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RejectMalformedInput;
use App\Http\Middleware\RequirePlugin;
use App\Storefront\Http\Middleware\SecretLink;
use App\Storefront\Http\Middleware\StorefrontSecurityHeaders;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/**
 * 登録されている全経路の認可を機械的に確かめる。人の目で「付け忘れ」に気づく前提をやめる。
 *
 * 規則:
 *   1. どの経路も、ログインを求める（auth:…）か、公開の印（public）のどちらかを持つ。どちらも無ければ違反
 *   2. 両方を持つ経路は意図が曖昧なので違反
 *   3. 管理画面のホストの経路は、接続元の制限（admin ミドルウェア群）を必ず通る
 *   4. 管理画面でログインを求めるなら、ガードは operator
 *   5. EnterTenant は /api/tenants/{tenant}/… にだけ付き、auth:operator と EnsureOperatorIsActive より後ろ
 *   6. 管理画面で {tenant} を含む経路は、必ず EnterTenant を通る
 *   7. EnterTenant の経路は、controller の引数でモデルを結びつけない（SubstituteBindings は EnterTenant より前に動き、
 *      テナントではなく中央 DB を引く。ID は文字列で受け取り、テナント文脈に入った後で探す）
 *   8. 店頭（ドメインでテナントを特定する経路）は、店頭の防御ヘッダを一番外側に置き、中央のホストを断り、壊れた文字を断る。
 *      モデルも結びつけない
 *   9. 店頭でログインを求めるなら、ガードは customer（お客さんの会員。ADR 0044）
 *
 * 新しい経路・機能に付け忘れやすい守り（ADR 0081。どれも全部の経路を一度に調べる）:
 *  10. 道筋に合い言葉（{token}・{code}・{secret} など）を持つ経路は、秘密の道筋の宣言（secret-link）を持つ。
 *      秘密でない値（郵便番号・配るための紹介のコード）は、理由つきの一覧（OPEN_PATH_SECRETS）にだけ書く
 *  11. 署名つきの URL の行き先（核の SecretLinks で作る経路の名前をコードから集める）と、Laravel の署名の確かめ（signed）を通る経路は、
 *      秘密の道筋の宣言を持つ。SecretLinks に渡す経路の名前は文字で書く（集められない書き方は違反）
 *  12. 秘密の道筋の宣言はショップの経路にだけ（掛けるのはショップの防御ヘッダ）
 *  13. ショップで書き込む経路（POST など）は、回数の上限（throttle）を通る。数えなくてよいものは理由つきの一覧（UNTHROTTLED_WRITES）にだけ
 *  14. App の経路（storefront.plugin.<名前>.…）は、その App を使う店だけに開く印（plugin:<名前>）を通り、印の名前と経路の名前が合う
 *  15. ショップのセッションの群の経路は、CSRF の確かめを外さない。外すものは理由つきの一覧（WITHOUT_CSRF）にだけ
 *  16. AI エージェントの鍵の守り（AuthenticateAgent。ADR 0100）は、管理画面の POST /mcp にだけ付き、POST /mcp は必ずそれを通る。
 *      /mcp は公開の印（セッションのログインではない）を持ち、管理画面のセッション・CSRF を外す
 *  17. 管理画面の接続元の制限（RestrictAdminIp）を外してよいのは、理由つきの一覧（WITHOUT_ADMIN_IP）の経路だけ（規則 3 の例外。ADR 0101 の要点の 6）。
 *      クラウドの AI エージェントのクライアントが届く /mcp と OAuth の機械の受け口だけで、許可の画面（/oauth/authorize・ログイン・SPA の殻と API）は制限の中
 */
final class RouteAudit
{
    /**
     * 道筋に合い言葉の名前の引数を持つが、秘密ではない経路（理由つき）
     */
    public const array OPEN_PATH_SECRETS = [
        'GET */postal/{code}' => '郵便番号（住所を引くための公開の値で、秘密ではない）',
        'GET */p/referral/r/{code}' => '紹介のコード（会員が人に配って広めるためのもので、秘密ではない。会員になった時に紹介した人を結ぶだけ）',
    ];

    /**
     * ショップで書き込むのに回数の上限を通らない経路（理由つき）
     */
    public const array UNTHROTTLED_WRITES = [
        'POST */account/logout' => '会員のログアウト。自分の印を消すだけで、何も作らず送らない',
        'POST */account/leave' => '退会。会員だけで、本人の再確認（コードとパスキーは回数の上限つき）の後だけ通り、1 回で会員を終える',
        'POST */p/newsletter/unsubscribe/{token}' => 'メールの 1 クリックの配信停止（RFC 8058）。メールのソフトの送り元はまとまった住所から来るので、止める操作を断らない（トークン 1 つでその 1 人を止めるだけ）',
        'POST */p/cart-reminder/unsubscribe/{token}' => 'メールの 1 クリックの配信停止（RFC 8058）。メルマガの停止と同じ理由',
        'POST */p/favorites/unsubscribe/{token}' => 'メールの 1 クリックの配信停止（RFC 8058）。メルマガの停止と同じ理由',
    ];

    /**
     * ショップのセッションの群（storefront）にいるのに、CSRF の確かめを外した経路（理由つき）
     */
    public const array WITHOUT_CSRF = [
        'POST */p/newsletter/unsubscribe/{token}' => 'メールのソフトのワンクリックの配信停止（RFC 8058）は CSRF のトークンを持たない。トークンは 256 ビットの乱数で、できるのは止めることだけ',
        'POST */p/cart-reminder/unsubscribe/{token}' => '同じ（メールのソフトのワンクリックの配信停止）',
        'POST */p/favorites/unsubscribe/{token}' => '同じ（メールのソフトのワンクリックの配信停止）',
        'POST */p/line/webhook' => 'LINE からの通知。送るのは LINE のサーバーで、CSRF の代わりに署名（チャネルの秘密の鍵の HMAC）で確認する',
    ];

    /**
     * 管理画面のホストにいるのに、接続元の制限（RestrictAdminIp）を外した経路（理由つき。「メソッド /道筋」）
     */
    public const array WITHOUT_ADMIN_IP = [
        'POST /mcp' => 'AI エージェントの MCP の入口（ADR 0100・0101）。claude.ai などのクラウドのクライアントから届くように。守りは鍵（AuthenticateAgent。毎回今の段階で確認し直す）と鍵ごと・失敗の接続元ごとの回数の上限',
        'GET /mcp' => 'MCP の入口の GET。405 を返すだけ（何も読まない・書かない）',
        'DELETE /mcp' => 'MCP の入口の DELETE。405 を返すだけ（何も読まない・書かない）',
        'GET /.well-known/oauth-protected-resource' => 'OAuth の保護された資源のメタデータ（RFC 9728）。決まった値だけ',
        'GET /.well-known/oauth-protected-resource/mcp' => '同じ（/mcp の道筋を足した形。RFC 9728 の 3.1）',
        'GET /.well-known/oauth-authorization-server' => 'OAuth の認可サーバーのメタデータ（RFC 8414）。決まった値だけ',
        'POST /oauth/register' => '動的なクライアント登録（RFC 7591）。クライアントのサーバーから届く。公開のクライアントだけ・行き先の形と数・接続元ごとと全体の回数の上限。登録しただけでは何も読めない（許可は制限の中の許可の画面で運営が押す）',
        'POST /oauth/token' => '鍵の受け渡し（認可コード ＋ PKCE・リフレッシュトークン）。クライアントのサーバーから届く。確認はコードと PKCE とリフレッシュトークンで、断った回を接続元ごとに数える',
        'POST /oauth/revoke' => '鍵の取り消し（RFC 7009）。取り消すことしかできない',
    ];

    /** 合い言葉とみなす道筋の引数の名前 */
    private const string SECRET_PARAMETER = '/\A(token|code|secret|signature|key|hash)\z|token|secret/i';

    /**
     * @param  array<string, string>|null  $signedNames  署名つきの URL の行き先（経路の名前 => 作る所）。null ならコードから集める（テストで足す時だけ渡す）
     */
    public function __construct(private readonly Router $router, private readonly string $adminDomain, private readonly ?array $signedNames = null) {}

    /**
     * @return list<string> 違反の説明
     */
    public function violations(): array
    {
        $violations = [];
        [$signedNames, $unreadable] = self::signedRouteNames();
        $signedNames = [...$signedNames, ...($this->signedNames ?? [])];

        foreach ($unreadable as $place) {
            $violations[] = "{$place}: SecretLinks に渡す経路の名前を文字で書いていない（テストが行き先を確認できない）";
        }

        foreach ($signedNames as $name => $place) {
            if ($this->router->getRoutes()->getByName($name) === null) {
                $violations[] = "{$place}: SecretLinks の行き先の経路 {$name} が無い";
            }
        }

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $key = self::key($route);
            $middleware = $this->middlewareOf($route);
            $auth = array_values(array_filter($middleware, static fn (string $m): bool => $m === Authenticate::class || str_starts_with($m, Authenticate::class.':')));
            $public = in_array(PublicRoute::class, $middleware, true);
            $isAdmin = $route->getDomain() === $this->adminDomain;

            if ($auth === [] && ! $public) {
                $violations[] = "{$key}: ログインも公開の印も無い";
            }

            if ($auth !== [] && $public) {
                $violations[] = "{$key}: ログインと公開の印が両方ある";
            }

            // 規則 3 と 17: 管理画面の経路は接続元の制限を通る。外してよいのは理由つきの一覧（WITHOUT_ADMIN_IP）だけ
            if ($isAdmin && ! in_array(RestrictAdminIp::class, $middleware, true) && ! array_key_exists(self::adminKey($route), self::WITHOUT_ADMIN_IP)) {
                $violations[] = "{$key}: 管理画面なのに接続元の制限を通らない";
            }

            if ($isAdmin && $auth !== [] && $auth !== [Authenticate::class.':operator']) {
                $violations[] = "{$key}: 管理画面のログインが operator のガードではない（".implode(',', $auth).'）';
            }

            // 規則 16: AI エージェントの鍵の守りは管理画面の POST /mcp にだけ。POST /mcp は必ずそれを通る
            $agent = in_array(AuthenticateAgent::class, $middleware, true);
            $isMcpPost = $isAdmin && $route->uri() === 'mcp' && in_array('POST', $route->methods(), true);

            if ($agent && ! $isMcpPost) {
                $violations[] = "{$key}: AI エージェントの鍵の守りは管理画面の POST /mcp にだけ付ける";
            }

            if ($isMcpPost && ! $agent) {
                $violations[] = "{$key}: MCP の入口が AI エージェントの鍵の守りを通らない";
            }

            $enter = array_search(EnterTenant::class, $middleware, true);

            if ($enter !== false) {
                $authAt = array_search(Authenticate::class.':operator', $middleware, true);
                $activeAt = array_search(EnsureOperatorIsActive::class, $middleware, true);

                if (! str_starts_with($route->uri(), 'api/tenants/{tenant}/') || ! $isAdmin) {
                    $violations[] = "{$key}: EnterTenant は管理画面の /api/tenants/{tenant}/… にだけ付ける";
                }

                if ($authAt === false || $activeAt === false || $authAt > $enter || $activeAt > $enter) {
                    $violations[] = "{$key}: EnterTenant の前に auth:operator と EnsureOperatorIsActive が要る";
                }

                if ($route->signatureParameters(['subClass' => UrlRoutable::class]) !== []) {
                    $violations[] = "{$key}: テナントの経路でモデルを結びつけている（中央 DB を引く。ID を文字列で受け取る）";
                }
            } elseif ($isAdmin && str_contains($route->uri(), '{tenant}')) {
                $violations[] = "{$key}: テナントを指す経路なのに EnterTenant を通らない";
            }

            // 規則 9: 店頭でログインを求めるなら、ガードは customer（お客さんの会員。ADR 0044）だけ
            if (in_array(InitializeTenancyByDomain::class, $middleware, true) && $auth !== [] && $auth !== [Authenticate::class.':customer']) {
                $violations[] = "{$key}: 店頭のログインが customer のガードではない（".implode(',', $auth).'）';
            }

            if (in_array(InitializeTenancyByDomain::class, $middleware, true)) {
                if ($middleware[0] !== StorefrontSecurityHeaders::class) {
                    $violations[] = "{$key}: 店頭の経路なのに、店頭の防御ヘッダが一番外側に無い";
                }

                foreach ([PreventAccessFromCentralDomains::class => '中央のホストを断る処理', RejectMalformedInput::class => '壊れた文字を断る処理'] as $required => $what) {
                    if (! in_array($required, $middleware, true)) {
                        $violations[] = "{$key}: 店頭の経路なのに{$what}を通らない";
                    }
                }

                if ($route->signatureParameters(['subClass' => UrlRoutable::class]) !== []) {
                    $violations[] = "{$key}: 店頭の経路でモデルを結びつけている（中央 DB を引く。ID を文字列で受け取る）";
                }
            }

            array_push($violations, ...$this->secretViolations($route, $key, $middleware, $isAdmin, $signedNames));
            array_push($violations, ...self::intakeViolations($route, $key, $middleware));
        }

        return $violations;
    }

    /**
     * 規則 10〜12: 秘密の道筋（署名か合い言葉を URL に持つ画面）は宣言を持つ。宣言はショップの経路にだけ
     *
     * @param  list<string>  $middleware
     * @param  array<string, string>  $signedNames
     * @return list<string>
     */
    private function secretViolations(Route $route, string $key, array $middleware, bool $isAdmin, array $signedNames): array
    {
        $violations = [];
        $declared = array_filter($middleware, static fn (string $m): bool => $m === SecretLink::class || str_starts_with($m, SecretLink::class.':')) !== [];
        $storefront = in_array(InitializeTenancyByDomain::class, $middleware, true);
        $secrets = array_values(array_filter($route->parameterNames(), static fn (string $name): bool => preg_match(self::SECRET_PARAMETER, $name) === 1));

        // 管理画面は全部の応答が参照元を渡さず・検索に載せない（AdminSecurityHeaders）。合い言葉は URL の断片（#）に載せる
        if ($secrets !== [] && ! $isAdmin && ! $declared && ! array_key_exists($key, self::OPEN_PATH_SECRETS)) {
            $violations[] = "{$key}: パスにトークン（".implode(',', $secrets).'）があるのに、秘密のリンクの宣言（secret-link）が無い';
        }

        $name = $route->getName();

        if (is_string($name) && isset($signedNames[$name]) && ! $declared) {
            $violations[] = "{$key}: 署名つきの URL の行き先（{$signedNames[$name]}）なのに、秘密のリンクの宣言（secret-link）が無い";
        }

        $validates = array_filter($middleware, static fn (string $m): bool => $m === ValidateSignature::class || str_starts_with($m, ValidateSignature::class.':')) !== [];

        if ($validates && ! $declared) {
            $violations[] = "{$key}: 署名を検証する（signed）のに、秘密のリンクの宣言（secret-link）が無い";
        }

        if ($declared && ! $storefront) {
            $violations[] = "{$key}: 秘密のリンクの宣言はショップの経路にだけ付ける（守りを掛けるのはショップの防御ヘッダ）";
        }

        return $violations;
    }

    /**
     * 規則 13〜15: ショップで書き込む経路は回数の上限を通る。App の経路は App の印を通る。セッションの群は CSRF を外さない
     *
     * @param  list<string>  $middleware
     * @return list<string>
     */
    private static function intakeViolations(Route $route, string $key, array $middleware): array
    {
        $violations = [];

        if (! in_array(InitializeTenancyByDomain::class, $middleware, true)) {
            return [];
        }

        $writes = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [];
        $throttled = array_filter($middleware, static fn (string $m): bool => str_starts_with($m, ThrottleRequests::class)) !== [];

        if ($writes && ! $throttled && ! array_key_exists($key, self::UNTHROTTLED_WRITES)) {
            $violations[] = "{$key}: ショップで書き込む経路なのに、回数の上限（throttle）を通らない";
        }

        $plugins = array_values(array_map(static fn (string $m): string => substr($m, strlen(RequirePlugin::class) + 1),
            array_filter($middleware, static fn (string $m): bool => str_starts_with($m, RequirePlugin::class.':'))));
        $name = (string) $route->getName();
        $named = preg_match('/\Astorefront\.plugin\.([a-z][a-z0-9-]*)\./', $name, $m) === 1 ? $m[1] : null;

        // App を止めた店でも開く経路（PluginRoutes の keptStorefrontRoutes。もう払ったお客さんの受け取り）は、核が使う店の印を付けない
        $kept = $plugins === [] && $named !== null && $route->getAction(PluginRoutes::KEPT) === $named;

        if ($named !== null && $plugins !== [$named] && ! $kept) {
            $violations[] = "{$key}: App {$named} の経路なのに、その App を使う店だけに開く印（plugin:{$named}）を通らない";
        }

        $session = in_array(StartSession::class, $middleware, true);

        if ($writes && $session && ! in_array(ValidateCsrfToken::class, $middleware, true) && ! array_key_exists($key, self::WITHOUT_CSRF)) {
            $violations[] = "{$key}: ショップのセッションの群なのに、CSRF の確認を外している";
        }

        if ($named === null && $plugins !== []) {
            $violations[] = "{$key}: App の印（plugin:".implode(',', $plugins).'）があるのに、経路の名前が storefront.plugin.<名前>. で始まらない';
        }

        return $violations;
    }

    /**
     * コード（app/ と plugins/*\/src）の中で、核の SecretLinks に渡している経路の名前と、その置き場所。
     * 名前を文字で書いていない呼び出しは、置き場所を 2 つ目に返す
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function signedRouteNames(?string $root = null): array
    {
        $root ??= dirname(__DIR__, 2);
        $names = [];
        $unreadable = [];

        foreach ([...self::phpFiles("{$root}/app"), ...self::phpFiles("{$root}/plugins", '/src/')] as $file) {
            $relative = substr($file, strlen($root) + 1);

            if ($relative === 'app/Storefront/Access/SecretLinks.php') {
                continue;
            }

            [$found, $bad] = self::secretLinkCalls((string) file_get_contents($file));

            foreach ($found as $name) {
                $names[$name] ??= $relative;
            }

            foreach ($bad as $line) {
                $unreadable[] = "{$relative}:{$line}";
            }
        }

        return [$names, $unreadable];
    }

    /**
     * 1 つのファイルの中身から、SecretLinks::signed / path に渡した経路の名前と、名前を文字で書いていない呼び出しの行
     *
     * @return array{0: list<string>, 1: list<int>}
     */
    public static function secretLinkCalls(string $code): array
    {
        $names = [];
        $bad = [];
        preg_match_all('/SecretLinks::(?:signed|path)\(\s*(\S)/', $code, $calls, PREG_OFFSET_CAPTURE);

        foreach ($calls[0] as [, $offset]) {
            if (preg_match("/\GSecretLinks::(?:signed|path)\(\s*'([a-z0-9._-]+)'/", $code, $m, 0, $offset) === 1) {
                $names[] = $m[1];
            } else {
                $bad[] = substr_count(substr($code, 0, $offset), "\n") + 1;
            }
        }

        return [$names, $bad];
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory, ?string $within = null): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php' && ($within === null || str_contains($file->getPathname(), $within))
                && ! str_contains($file->getPathname(), '/vendor/') && ! str_contains($file->getPathname(), '/node_modules/')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * 理由つきの例外の一覧のうち、今の経路に無いもの・もう例外の要らないもの（残しておくと、後で同じ道筋に足した経路が黙って通る）
     *
     * @return list<string>
     */
    public function staleExceptions(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $routes[self::key($route)] = $this->middlewareOf($route);
        }

        $stale = [];

        foreach ([self::OPEN_PATH_SECRETS, self::UNTHROTTLED_WRITES, self::WITHOUT_CSRF] as $list) {
            foreach (array_keys($list) as $key) {
                if (! array_key_exists($key, $routes)) {
                    $stale[] = "{$key}: 例外の一覧にあるのに、その経路が無い";
                }
            }
        }

        foreach (array_keys(self::UNTHROTTLED_WRITES) as $key) {
            if (array_filter($routes[$key] ?? [], static fn (string $m): bool => str_starts_with($m, ThrottleRequests::class)) !== []) {
                $stale[] = "{$key}: 回数の上限を通るので、例外の一覧から消す";
            }
        }

        foreach (array_keys(self::WITHOUT_CSRF) as $key) {
            if (in_array(ValidateCsrfToken::class, $routes[$key] ?? [], true)) {
                $stale[] = "{$key}: CSRF を確認しているので、例外の一覧から消す";
            }
        }

        $admin = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if ($route->getDomain() === $this->adminDomain) {
                $admin[self::adminKey($route)] = $this->middlewareOf($route);
            }
        }

        foreach (array_keys(self::WITHOUT_ADMIN_IP) as $key) {
            if (! array_key_exists($key, $admin)) {
                $stale[] = "{$key}: 接続元の制限の例外の一覧にあるのに、管理画面にその経路が無い";
            } elseif (in_array(RestrictAdminIp::class, $admin[$key], true)) {
                $stale[] = "{$key}: 接続元の制限を通るので、例外の一覧から消す";
            }
        }

        return $stale;
    }

    /**
     * 管理画面のホストで、ログインを求める経路。
     *
     * @return list<Route>
     */
    public function adminRoutesRequiringLogin(): array
    {
        return array_values(array_filter(
            $this->router->getRoutes()->getRoutes(),
            fn (Route $route): bool => $route->getDomain() === $this->adminDomain
                && in_array(Authenticate::class.':operator', $this->middlewareOf($route), true),
        ));
    }

    /**
     * 管理画面の経路の「メソッド /道筋」（ホストは管理画面のホストに決まっているので付けない）
     */
    public static function adminKey(Route $route): string
    {
        return $route->methods()[0].' /'.ltrim($route->uri(), '/');
    }

    public static function key(Route $route): string
    {
        return $route->methods()[0].' '.($route->getDomain() ?? '*').'/'.ltrim($route->uri(), '/');
    }

    /**
     * @return list<string> グループを展開し、優先順に並べた後のミドルウェア
     */
    private function middlewareOf(Route $route): array
    {
        return array_values(array_map(static fn (mixed $m): string => is_string($m) ? $m : get_debug_type($m), $this->router->gatherRouteMiddleware($route)));
    }
}
