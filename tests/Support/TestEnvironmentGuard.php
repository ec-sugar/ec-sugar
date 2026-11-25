<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use RuntimeException;

/**
 * テストの入口の守り。テストを始める前に、テスト用の DB と写真の置き場を指しているかを確かめ、違えば止める。
 *
 * PHP のテストは走るたびに表を空にし（DatabaseTruncation）、店を作っては消す。設定の控え（bootstrap/cache/config.php。
 * `php artisan config:cache` で作られる）があると phpunit.xml の切り替えが効かず、控えに書かれた開発の DB
 * （ec_central と店の ec_t_*）のままテストが走り、開発の店と中身が消える。ブラウザのテストの下ごしらえ（tests/e2e/fixtures.php）も
 * 店を消すので同じ。
 *
 * 確かめるのは 2 か所:
 * 1. phpunit の入口（tests/bootstrap.php）。環境変数と控えのファイルを見て、テストを 1 つも始めずに止める
 * 2. テストごとのアプリの作り始め（Tests\TestCase::createApplication）。読み込んだ設定そのものを見る。表を空にする（setUpTraits）より前
 *
 * 許す形:
 * - PHP のテスト: 中央の DB は ec_test か ec_test_<文字>、店の DB の頭は「中央の DB の名前 + _t_」、写真の置き場は media-testing か
 *   media-testing-<文字>（中央の DB と同じ文字）の組（ほかの作業場所が ec_test_g・ec_test_g_t_・media-testing-g のような組で
 *   並べて走らせる。組でないと、ほかの作業場所の店とぶつかって消し合う）。APP_ENV は testing。
 *   <文字> は英小文字と数字の 1 つの区切りだけ（区切りをいくつでも許すと、ほかの作業の店の DB の名前〔ec_test_g_t_abc〕も中央の DB として通る）。
 *   t も断る（頭が ec_test_t_t_ になり、既定の ec_test の店の DB の頭 ec_test_t_ と重なる）
 * - ブラウザのテスト: 中央の DB は ec_e2e か ec_e2e_<文字>（作業ごとに分ける。tests/e2e/env.js の E2E_DB）、店の DB の頭は
 *   「中央の DB の名前 + _t_」、写真の置き場は media-e2e か media-e2e-<文字>（中央の DB と同じ文字）の組。本番ではない。
 *   <文字> は 1 つの区切りだけ（ec_e2e_a_b のような名前は、ほかの作業の店の DB〔ec_e2e_a_t_…〕と見分けにくいので断る）。
 *   t も断る（頭が ec_e2e_t_t_ になり、既定の ec_e2e の店の DB の頭 ec_e2e_t_ と重なる）
 * - どちらも、設定の控えが無く、DB_URL（DB の名前を上書きする）が空
 *
 * DB のホストとポートは確かめない。テスト用の DB は開発と同じ Postgres の中にあり、名前でしか見分けられないため。
 *
 * 秘密の鍵と外へ出る設定は、止めずに決まった値へ上書きする（PINNED・pinEnvironment。Tests\TestCase::createApplication が呼ぶ）。
 */
final class TestEnvironmentGuard
{
    public const string PHPUNIT = 'phpunit';

    public const string E2E = 'e2e';

    /**
     * テストでは外から何が渡されても決まった値にする環境変数（秘密の鍵と、外へ出る設定）。
     *
     * phpunit.xml の force="true" は putenv と $_ENV にしか書かず、シェルから渡した環境変数（$_SERVER に入り、Laravel が先に読む）に負ける。
     * 手元のシェルに「Stripe と接続する」を試す鍵などを入れたままテストを走らせても、鍵がテストに持ち込まれず、外へ送られないようにする。
     *
     * どれも止めずに上書きする。使うテストは config で入れ直すので、上書きで守りは足りる。止めると、シェルに鍵を置いて手元で試している人が
     * 毎回外さないとテストを走らせられない。一方、DB・店の DB の頭・写真の置き場・APP_ENV・DB_URL・設定の控えは、上書きせずに止める
     * （DB と置き場は作業ごとに外から渡して分けるのが正しい使い方で、黙って変えると別の作業場所の店を消し合う。problems() を参照）
     *
     * @var array<string, string>
     */
    public const array PINNED = [
        // Stripe の運営の鍵（ADR 0022）。使うテストは config で入れる
        'STRIPE_PLATFORM_SECRET_KEY' => '',
        'STRIPE_CONNECT_WEBHOOK_SECRET' => '',
        // Stripe の送信先。ブラウザのテストの偽物のためだけ。PHP のテストで差し替えると、鍵を別の宛先へ送りうる
        'STRIPE_API_BASE' => '',
        // 本番の鍵（sk_live_）を置けるか。テストでは本物のお金を動かさない（確かめるテストは config で入れる）
        'PAYMENT_LIVE_KEYS_ALLOWED' => 'false',
        // メールは送らずに手元にためる（smtp などを渡されても、お客さんの宛先へ送らない）
        'MAIL_MAILER' => 'array',
        // 外のサービスの鍵と送信先（メールの送信サービス・Slack の通知・ログの送信先）
        'POSTMARK_API_KEY' => '',
        'RESEND_API_KEY' => '',
        'SLACK_BOT_USER_OAUTH_TOKEN' => '',
        'LOG_SLACK_WEBHOOK_URL' => '',
        'PAPERTRAIL_URL' => '',
        // S3 互換のストレージ（保存先）の鍵。既定の保存先も手元（local）にする。テストの写真は FakeMedia で手元に置く
        'AWS_ACCESS_KEY_ID' => '',
        'AWS_SECRET_ACCESS_KEY' => '',
        'FILESYSTEM_DISK' => 'local',
    ];

    /** PHP のテストの中央の DB。ec_test か、作業ごとの ec_test_<文字>（t は断る） */
    private const string TEST_DATABASE = '/\Aec_test(?:_(?!t\z)[a-z0-9]+)?\z/';

    /** ブラウザのテストの中央の DB。ec_e2e か、作業ごとの ec_e2e_<文字>（t は断る） */
    private const string E2E_DATABASE = '/\Aec_e2e(?:_(?!t\z)[a-z0-9]+)?\z/';

    /**
     * ブラウザのテストの中央の DB の名前として使えるか（tests/e2e/fixtures.php が、つながった先の DB の名前を確かめるのにも使う）
     */
    public static function isBrowserTestDatabase(?string $database): bool
    {
        return $database !== null && preg_match(self::E2E_DATABASE, $database) === 1;
    }

    /**
     * PHP のテストの中央の DB として使えるか
     */
    public static function isPhpunitTestDatabase(?string $database): bool
    {
        return $database !== null && preg_match(self::TEST_DATABASE, $database) === 1;
    }

    /**
     * PHP のテストの中央の DB と組になる写真の置き場（ec_test → media-testing、ec_test_g → media-testing-g。phpunit.xml・docs/development/team.md）
     */
    public static function phpunitTestMediaDirectory(string $database): string
    {
        return 'media-testing'.str_replace('_', '-', substr($database, strlen('ec_test')));
    }

    /**
     * PHP のテストを並べて流すとき（ADR 0057）の、並べた各々の中央の DB。元の DB の文字の後ろに p と番号を足す
     * （ec_test → ec_test_p1、ec_test_o → ec_test_op1）。1 つの区切りのままなので入口の守りの決まりに合い、店の DB の頭
     * （ec_test_op1_t_）と写真の置き場（media-testing-op1）も、いつもの組の決め方で各々に分かれる。
     * 番号は paratest が各々に渡す TEST_TOKEN（1 から）。数字でなければ断る
     */
    public static function parallelPhpunitDatabase(string $database, string $token): string
    {
        if (! self::isPhpunitTestDatabase($database) || preg_match('/\A[1-9][0-9]{0,2}\z/', $token) !== 1) {
            throw new RuntimeException(self::message(["並べて流すときの元の DB（{$database}）か番号（{$token}）がテスト用の形ではありません。"]));
        }

        return ($database === 'ec_test' ? 'ec_test_' : $database).'p'.$token;
    }

    /**
     * ブラウザのテストの中央の DB と組になる写真の置き場（ec_e2e → media-e2e、ec_e2e_s → media-e2e-s。tests/e2e/env.js と同じ決め方）
     */
    public static function browserTestMediaDirectory(string $database): string
    {
        return 'media-e2e'.str_replace('_', '-', substr($database, strlen('ec_e2e')));
    }

    /**
     * 止める理由。空なら走らせてよい
     *
     * @param  self::PHPUNIT|self::E2E  $kind
     * @param  array{config_cache: ?string, app_env: ?string, connections: array<string, array{database: ?string, url: ?string}>, tenant_prefix: ?string, media_root: ?string}  $values
     * @return list<string>
     */
    public static function problems(string $kind, array $values): array
    {
        $problems = [];

        if ($values['config_cache'] !== null) {
            $problems[] = "設定のキャッシュ（{$values['config_cache']}）があります。キャッシュがあると phpunit.xml のテスト用の DB への切り替えが効かず、"
                .'キャッシュに書かれた開発の DB（ec_central と店の ec_t_*）を空にしてしまいます。`php artisan config:clear` でキャッシュを削除してから走らせてください。';
        }

        $env = $values['app_env'];

        if ($kind === self::PHPUNIT && $env !== 'testing') {
            $problems[] = 'APP_ENV が testing ではありません（'.self::show($env).'）。テストは phpunit.xml から（`php artisan test`）走らせてください。';
        }

        if ($kind === self::E2E && ($env === null || $env === 'production')) {
            $problems[] = 'APP_ENV が '.self::show($env).' です。ブラウザのテストは本番では走らせません。';
        }

        $central = null;

        foreach ($values['connections'] as $name => $connection) {
            $database = $connection['database'];
            $central ??= $database;

            if ($connection['url'] !== null && $connection['url'] !== '') {
                // 中身は見せない（合言葉が入る）
                $problems[] = "DB の接続 {$name} に DB_URL が入っています。DB_URL は DB の名前を上書きするので、テストでは空にしてください。";
            }

            $allowed = $kind === self::PHPUNIT
                ? self::isPhpunitTestDatabase($database)
                : self::isBrowserTestDatabase($database);

            if ($allowed && $database !== $central) {
                // どちらもテスト用の名前でも、組（店の DB の頭・写真の置き場）は最初の接続の DB で決めるので、ほかの作業の DB を空にしうる
                $problems[] = "DB の接続 {$name} の DB（{$database}）が、ほかの接続の DB（{$central}）と違います。テストの使う接続は同じ DB にそろえてください。";
            }

            if (! $allowed) {
                $problems[] = $kind === self::PHPUNIT
                    ? "DB の接続 {$name} の DB（".self::show($database).'）がテスト用の名前ではありません。ec_test か、作業ごとの ec_test_<英小文字と数字>（t は除く）にしてください'
                        .'（例: DB_DATABASE=ec_test_g TENANT_DB_PREFIX=ec_test_g_t_ MEDIA_DIRECTORY=media-testing-g php artisan test）。'
                    : "DB の接続 {$name} の DB（".self::show($database).'）がテスト用の名前ではありません。ブラウザのテストは ec_e2e か、'
                        .'作業ごとの ec_e2e_<文字>（例: E2E_DB=ec_e2e_s。tests/e2e/env.js）で走らせます。';
            }
        }

        $prefix = $values['tenant_prefix'];
        $expectedPrefix = $kind === self::PHPUNIT
            ? (self::isPhpunitTestDatabase($central) ? $central.'_t_' : 'ec_test_t_')
            : (self::isBrowserTestDatabase($central) ? $central.'_t_' : 'ec_e2e_t_');

        if ($prefix !== $expectedPrefix) {
            $problems[] = '店の DB の頭（'.self::show($prefix).'）がテスト用の形ではありません。'
                .($kind === self::PHPUNIT
                    ? "中央の DB の名前に _t_ を足した形（TENANT_DB_PREFIX={$expectedPrefix}）にしてください。ほかの頭だと、開発やほかの作業場所の店の DB を作り、消してしまいます。"
                    : "ブラウザのテストは中央の DB の名前に _t_ を足した形（{$expectedPrefix}）で走らせます（tests/e2e/env.js）。");
        }

        $media = $values['media_root'] === null ? null : basename($values['media_root']);
        // 中央の DB と組の置き場だけ（ほかの作業の置き場は、置き場の札が違うので店の片付けが断られ、テストが途中で止まる。
        // 写真を消し合うこともある）
        $expectedMedia = $kind === self::PHPUNIT
            ? self::phpunitTestMediaDirectory(self::isPhpunitTestDatabase($central) ? (string) $central : 'ec_test')
            : self::browserTestMediaDirectory(self::isBrowserTestDatabase($central) ? (string) $central : 'ec_e2e');

        if ($media !== $expectedMedia) {
            $problems[] = '写真の保存先（'.self::show($media).'）がテスト用ではありません。'
                .($kind === self::PHPUNIT
                    ? "中央の DB と組の保存先（MEDIA_DIRECTORY={$expectedMedia}）にしてください。"
                    : "ブラウザのテストは中央の DB と組の保存先（{$expectedMedia}）で走らせます（tests/e2e/env.js）。");
        }

        return $problems;
    }

    /**
     * 秘密の鍵と外へ出る設定（PINNED）を、Laravel が読む 3 か所（$_SERVER・$_ENV・putenv）にそろえて書く。アプリを作る前に呼ぶ。
     * .env の読み込みは今ある値を上書きしないので、.env に鍵があっても持ち込まれない
     */
    public static function pinEnvironment(): void
    {
        foreach (self::PINNED as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    /**
     * 止める理由があれば例外で止める
     *
     * @param  self::PHPUNIT|self::E2E  $kind
     * @param  array{config_cache: ?string, app_env: ?string, connections: array<string, array{database: ?string, url: ?string}>, tenant_prefix: ?string, media_root: ?string}  $values
     */
    public static function assertSafe(string $kind, array $values): void
    {
        $problems = self::problems($kind, $values);

        if ($problems !== []) {
            throw new RuntimeException(self::message($problems));
        }
    }

    /**
     * @param  list<string>  $problems
     */
    public static function message(array $problems): string
    {
        return "テストを始める前に止めました（何も消していません）:\n- ".implode("\n- ", $problems)."\n";
    }

    /**
     * 読み込んだ設定から（テストのアプリ・ブラウザのテストの下ごしらえ）
     *
     * @return array{config_cache: ?string, app_env: ?string, connections: array<string, array{database: ?string, url: ?string}>, tenant_prefix: ?string, media_root: ?string}
     */
    public static function valuesFromApplication(Application $app): array
    {
        $config = $app->make('config');
        $connections = [];

        // 表を空にする既定の接続と、テナントの中央の接続（違う名前なら両方）
        foreach (array_unique([(string) $config->get('database.default'), (string) $config->get('tenancy.database.central_connection')]) as $name) {
            $connections[$name] = [
                'database' => self::stringOrNull($config->get("database.connections.{$name}.database")),
                'url' => self::stringOrNull($config->get("database.connections.{$name}.url")),
            ];
        }

        $cache = $app->getCachedConfigPath();

        return [
            'config_cache' => is_file($cache) ? $cache : null,
            'app_env' => self::stringOrNull($config->get('app.env')),
            'connections' => $connections,
            'tenant_prefix' => self::stringOrNull($config->get('tenancy.database.prefix')),
            'media_root' => self::stringOrNull($config->get('filesystems.disks.media.root')),
        ];
    }

    /**
     * 環境変数から（phpunit の入口。アプリを作る前なので、config/ の既定の値をここにも写す）
     *
     * @return array{config_cache: ?string, app_env: ?string, connections: array<string, array{database: ?string, url: ?string}>, tenant_prefix: ?string, media_root: ?string}
     */
    public static function valuesFromEnvironment(string $basePath): array
    {
        // Application::getCachedConfigPath と同じ決め方（APP_CONFIG_CACHE が / で始まればそのまま、でなければ基点から）
        $cacheSetting = self::stringOrNull(Env::get('APP_CONFIG_CACHE'));
        $cache = match (true) {
            $cacheSetting === null => $basePath.'/bootstrap/cache/config.php',
            str_starts_with($cacheSetting, '/') || str_starts_with($cacheSetting, '\\') => $cacheSetting,
            default => $basePath.'/'.$cacheSetting,
        };

        $connection = self::stringOrNull(Env::get('DB_CONNECTION')) ?? 'sqlite';

        return [
            'config_cache' => is_file($cache) ? $cache : null,
            'app_env' => self::stringOrNull(Env::get('APP_ENV')),
            'connections' => [$connection => [
                'database' => self::stringOrNull(Env::get('DB_DATABASE')) ?? 'laravel',
                'url' => self::stringOrNull(Env::get('DB_URL')),
            ]],
            'tenant_prefix' => self::stringOrNull(Env::get('TENANT_DB_PREFIX')) ?? 'ec_t_',
            'media_root' => $basePath.'/storage/app/'.(self::stringOrNull(Env::get('MEDIA_DIRECTORY')) ?? 'media'),
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function show(?string $value): string
    {
        return $value === null ? '未設定' : ($value === '' ? '空' : $value);
    }
}
