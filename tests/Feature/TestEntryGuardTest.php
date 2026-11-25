<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\TestEnvironmentGuard;
use Tests\TestCase;

/**
 * テストの入口の守りがつながっていること。
 * phpunit.xml の入口（tests/bootstrap.php）と、テストごとのアプリの作り始め（Tests\TestCase::createApplication）の 2 か所で止める。
 * 止まることは、無い DB の名前（ec_other）や、作業の外に置いた控えで確かめる。開発の DB へは向けない
 */
final class TestEntryGuardTest extends TestCase
{
    public function test_phpunit_starts_from_the_guard(): void
    {
        $xml = simplexml_load_file(base_path('phpunit.xml'));

        $this->assertNotFalse($xml);
        $this->assertSame('tests/bootstrap.php', (string) $xml['bootstrap']);
    }

    public function test_the_bootstrap_lets_a_test_environment_through(): void
    {
        $process = $this->runBootstrap([]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_the_bootstrap_stops_on_a_config_cache(): void
    {
        $cache = $this->temporaryConfigCache();

        try {
            $process = $this->runBootstrap(['APP_CONFIG_CACHE' => $cache]);
        } finally {
            unlink($cache);
        }

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('設定のキャッシュ', $process->getErrorOutput());
        $this->assertStringContainsString('php artisan config:clear', $process->getErrorOutput());
    }

    public function test_the_bootstrap_stops_on_a_database_or_prefix_that_is_not_for_tests(): void
    {
        $database = $this->runBootstrap(['DB_DATABASE' => 'ec_other', 'TENANT_DB_PREFIX' => 'ec_other_t_']);
        $prefix = $this->runBootstrap(['TENANT_DB_PREFIX' => 'ec_other_t_']);
        $media = $this->runBootstrap(['MEDIA_DIRECTORY' => 'media']);

        $this->assertSame(1, $database->getExitCode());
        $this->assertStringContainsString('ec_other', $database->getErrorOutput());
        $this->assertSame(1, $prefix->getExitCode());
        $this->assertStringContainsString('店の DB の頭', $prefix->getErrorOutput());
        $this->assertSame(1, $media->getExitCode());
        $this->assertStringContainsString('写真の保存先', $media->getErrorOutput());
    }

    public function test_creating_the_application_stops_on_a_tenant_prefix_that_is_not_for_tests(): void
    {
        // 入口（bootstrap）をすり抜けても、表を空にする前（setUpTraits の前）のアプリの作り始めで止まる
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('店の DB の頭（ec_other_t_）');

        $this->withEnvironment(['TENANT_DB_PREFIX' => 'ec_other_t_'], fn () => $this->createApplication());
    }

    public function test_creating_the_application_stops_on_a_database_that_is_not_for_tests(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ec_other');

        $this->withEnvironment(['DB_DATABASE' => 'ec_other', 'TENANT_DB_PREFIX' => 'ec_other_t_'], fn () => $this->createApplication());
    }

    public function test_the_values_are_read_from_the_application_config(): void
    {
        $this->assertSame([], TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, TestEnvironmentGuard::valuesFromApplication($this->app)));

        config()->set('database.connections.'.config('database.default').'.database', 'ec_other');
        $this->assertStringContainsString('ec_other', implode("\n", TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, TestEnvironmentGuard::valuesFromApplication($this->app))));

        $cache = $this->temporaryConfigCache();

        try {
            $values = $this->withEnvironment(['APP_CONFIG_CACHE' => $cache], fn (): array => TestEnvironmentGuard::valuesFromApplication($this->app));
        } finally {
            unlink($cache);
        }

        $this->assertSame($cache, $values['config_cache']);
    }

    /**
     * 秘密の鍵と外へ出る設定は、テストの中では決まった値（空・array など）になっている。
     * 下の「外から渡しても届かない」が、外の環境変数に偽の値を入れた子のプロセスで、このテストだけを走らせる
     */
    public function test_secrets_and_outbound_settings_are_pinned_in_tests(): void
    {
        $this->assertSame('', config('payment.stripe_platform_secret'));
        $this->assertSame('', config('payment.stripe_connect_webhook_secret'));
        $this->assertSame('', config('payment.stripe_api_base'));
        $this->assertFalse(config('payment.live_keys_allowed'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('', config('services.postmark.key'));
        $this->assertSame('', config('services.resend.key'));
        $this->assertSame('', config('services.slack.notifications.bot_user_oauth_token'));
        $this->assertSame('', config('logging.channels.slack.url'));
        $this->assertSame('', config('logging.channels.papertrail.handler_with.host'));
        $this->assertSame('', config('filesystems.disks.s3.key'));
        $this->assertSame('', config('filesystems.disks.s3.secret'));
        $this->assertSame('local', config('filesystems.default'));
    }

    public function test_values_passed_from_the_shell_do_not_reach_the_tests(): void
    {
        // シェルの環境変数は phpunit.xml の force より勝つ（Laravel が $_SERVER を先に読むため）。偽の値で確かめる（本物の鍵の形にしない）。
        // 結果は JUnit の形の記録で見る（画面への出力の形は走らせ方で変わる）
        $log = tempnam(sys_get_temp_dir(), 'ec-pinned-junit-');
        $this->assertNotFalse($log);

        $process = new Process([
            PHP_BINARY, base_path('vendor/bin/phpunit'), '--no-coverage', '--colors=never', '--log-junit', $log,
            '--filter', 'test_secrets_and_outbound_settings_are_pinned_in_tests', base_path('tests/Feature/TestEntryGuardTest.php'),
        ], base_path(), [
            'STRIPE_PLATFORM_SECRET_KEY' => 'from-the-shell',
            'STRIPE_CONNECT_WEBHOOK_SECRET' => 'from-the-shell',
            'STRIPE_API_BASE' => 'http://127.0.0.1:9/',
            'PAYMENT_LIVE_KEYS_ALLOWED' => 'true',
            'MAIL_MAILER' => 'smtp',
            'POSTMARK_API_KEY' => 'from-the-shell',
            'RESEND_API_KEY' => 'from-the-shell',
            'SLACK_BOT_USER_OAUTH_TOKEN' => 'from-the-shell',
            'LOG_SLACK_WEBHOOK_URL' => 'http://127.0.0.1:9/',
            'PAPERTRAIL_URL' => '127.0.0.1',
            'AWS_ACCESS_KEY_ID' => 'from-the-shell',
            'AWS_SECRET_ACCESS_KEY' => 'from-the-shell',
            'FILESYSTEM_DISK' => 's3',
            // 作業ごとに分けるテスト用の DB と写真の置き場は、外から渡したものがそのまま効く（このプロセスと同じものを渡す）
            'DB_DATABASE' => (string) config('database.connections.'.config('database.default').'.database'),
            'TENANT_DB_PREFIX' => (string) config('tenancy.database.prefix'),
            'MEDIA_DIRECTORY' => basename((string) config('filesystems.disks.media.root')),
        ]);
        $process->setTimeout(120);

        try {
            $process->run();
            $junit = simplexml_load_file($log);
        } finally {
            unlink($log);
        }

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        // 1 つも走らずに通ったのではないこと
        $this->assertNotFalse($junit);
        $this->assertSame(['1', '0', '0'], [(string) $junit->testsuite['tests'], (string) $junit->testsuite['failures'], (string) $junit->testsuite['errors']]);
    }

    public function test_every_forced_value_in_phpunit_xml_is_pinned_by_the_guard(): void
    {
        // phpunit.xml の force は外の環境変数に負けるので、守りの一覧（TestEnvironmentGuard::PINNED）にも同じ値で載せる
        $xml = simplexml_load_file(base_path('phpunit.xml'));
        $this->assertNotFalse($xml);

        foreach ($xml->php->env as $env) {
            if ((string) $env['force'] === 'true') {
                $this->assertSame((string) $env['value'], TestEnvironmentGuard::PINNED[(string) $env['name']] ?? null, (string) $env['name']);
            }
        }
    }

    /**
     * phpunit.xml の値を土台に、一部を差し替えて tests/bootstrap.php だけを別のプロセスで走らせる（テストは走らせない）
     *
     * @param  array<string, string>  $override
     */
    private function runBootstrap(array $override): Process
    {
        $env = $override + [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'ec_test_x',
            'DB_URL' => '',
            'TENANT_DB_PREFIX' => 'ec_test_x_t_',
            'MEDIA_DIRECTORY' => 'media-testing-x',
            'APP_CONFIG_CACHE' => false,
        ];

        $process = new Process([PHP_BINARY, base_path('tests/bootstrap.php')], base_path(), $env);
        $process->run();

        return $process;
    }

    /**
     * 作業の外（一時の場所）に置く設定の控え。中身は読まれないうちに止まるので空の配列
     */
    private function temporaryConfigCache(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ec-config-cache-');
        $this->assertNotFalse($path);
        file_put_contents($path, "<?php return [];\n");

        return $path;
    }

    /**
     * 環境変数を一時だけ差し替えて呼ぶ。作り直したアプリが置き換えた共有の入れ物も、このテストのアプリに戻す
     *
     * @template T
     *
     * @param  array<string, string>  $values
     * @param  callable(): T  $callback
     * @return T
     */
    private function withEnvironment(array $values, callable $callback): mixed
    {
        $saved = [];

        foreach ($values as $key => $value) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        try {
            return $callback();
        } finally {
            foreach ($saved as $key => [$env, $server, $put]) {
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }

                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }

                putenv($put === false ? $key : "{$key}={$put}");
            }

            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }
    }
}
