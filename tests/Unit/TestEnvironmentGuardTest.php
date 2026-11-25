<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestEnvironmentGuard;

/**
 * テストの入口の守りの判定。設定の控えがある・テスト用でない DB を指しているときに止まることを、
 * 判定の関数に値を渡して確かめる（本当に開発の DB へ向けて走らせない）。
 */
final class TestEnvironmentGuardTest extends TestCase
{
    /**
     * @return array{config_cache: ?string, app_env: ?string, connections: array<string, array{database: ?string, url: ?string}>, tenant_prefix: ?string, media_root: ?string}
     */
    private static function phpunitValues(string $database = 'ec_test', ?string $prefix = null, ?string $media = null): array
    {
        return [
            'config_cache' => null,
            'app_env' => 'testing',
            'connections' => ['pgsql' => ['database' => $database, 'url' => '']],
            'tenant_prefix' => $prefix ?? $database.'_t_',
            // 既定は中央の DB と組の置き場（ec_test_g → media-testing-g）
            'media_root' => '/work/storage/app/'.($media ?? 'media-testing'.str_replace('_', '-', substr($database, strlen('ec_test')))),
        ];
    }

    /**
     * @return array{config_cache: ?string, app_env: ?string, connections: array<string, array{database: ?string, url: ?string}>, tenant_prefix: ?string, media_root: ?string}
     */
    private static function e2eValues(string $database = 'ec_e2e', ?string $prefix = null, ?string $media = null): array
    {
        return [
            'config_cache' => null,
            'app_env' => 'local',
            'connections' => ['pgsql' => ['database' => $database, 'url' => '']],
            'tenant_prefix' => $prefix ?? $database.'_t_',
            'media_root' => '/work/storage/app/'.($media ?? 'media-e2e'),
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function allowedForPhpunit(): array
    {
        return [
            '既定のテスト用の DB' => ['ec_test', 'ec_test_t_', 'media-testing'],
            'ほかの作業場所の別名' => ['ec_test_g', 'ec_test_g_t_', 'media-testing-g'],
            // 今まで作業ごとの環境で使ってきた名前（1 文字・2 文字・数字入り）
            '今までの名前（1 文字）' => ['ec_test_k', 'ec_test_k_t_', 'media-testing-k'],
            '今までの名前（2 文字）' => ['ec_test_lp', 'ec_test_lp_t_', 'media-testing-lp'],
            '別名（2 文字）' => ['ec_test_bt', 'ec_test_bt_t_', 'media-testing-bt'],
            '数字入り' => ['ec_test_u2', 'ec_test_u2_t_', 'media-testing-u2'],
        ];
    }

    #[DataProvider('allowedForPhpunit')]
    public function test_test_databases_are_allowed(string $database, string $prefix, string $media): void
    {
        $this->assertSame([], TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, self::phpunitValues($database, $prefix, $media)));
        $this->assertTrue(TestEnvironmentGuard::isPhpunitTestDatabase($database));
        $this->assertSame($media, TestEnvironmentGuard::phpunitTestMediaDirectory($database));
    }

    /**
     * 並べて流すとき（ADR 0057）の各々の組: 元の DB の文字に p と番号を足し、頭と置き場もいつもの決め方で分かれ、入口の守りを通る
     */
    public function test_parallel_workers_get_their_own_set_that_passes_the_guard(): void
    {
        $cases = [['ec_test', '1', 'ec_test_p1'], ['ec_test', '12', 'ec_test_p12'], ['ec_test_o', '3', 'ec_test_op3'], ['ec_test_u2', '2', 'ec_test_u2p2']];

        foreach ($cases as [$base, $token, $worker]) {
            $this->assertSame($worker, TestEnvironmentGuard::parallelPhpunitDatabase($base, $token));
            $media = TestEnvironmentGuard::phpunitTestMediaDirectory($worker);
            $this->assertSame([], TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, self::phpunitValues($worker, $worker.'_t_', $media)));
            // 元の組とも、ほかの番号の組とも重ならない
            $this->assertNotSame($base, $worker);
            $this->assertNotSame($worker, TestEnvironmentGuard::parallelPhpunitDatabase($base, (string) ((int) $token + 1)));
        }

        // 元の DB がテスト用でない・番号が数字でないなら止める（名前は SQL の識別子にもなる）
        foreach ([['ec_central', '1'], ['ec_test_g_t_abc', '1'], ['ec_test', '0'], ['ec_test', 'a'], ['ec_test', '1;'], ['ec_test', '']] as [$base, $token]) {
            try {
                TestEnvironmentGuard::parallelPhpunitDatabase($base, $token);
                $this->fail("{$base} と {$token} が通った");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('並べて流すとき', $e->getMessage());
            }
        }
    }

    /**
     * 組が合わない・作業ごとでない形。止める理由の数と、理由に入る言葉
     *
     * @return array<string, array{string, string, string, int, string}>
     */
    public static function mismatchedForPhpunit(): array
    {
        return [
            // 置き場が作業ごとでない: 既定の置き場や、ほかの作業の置き場の写真を消しうる
            '保存先が既定のまま' => ['ec_test_g', 'ec_test_g_t_', 'media-testing', 1, '写真の保存先'],
            '保存先がほかの作業のもの' => ['ec_test_g', 'ec_test_g_t_', 'media-testing-x', 1, '写真の保存先'],
            '既定の DB に別名の保存先' => ['ec_test', 'ec_test_t_', 'media-testing-g', 1, '写真の保存先'],
            '保存先の区切りが 2 つ' => ['ec_test_g', 'ec_test_g_t_', 'media-testing-g-t', 1, '写真の保存先'],
            'ブラウザのテストの保存先' => ['ec_test_g', 'ec_test_g_t_', 'media-e2e-g', 1, '写真の保存先'],
            // 頭がほかの作業のもの: 作るときにほかの作業の店の DB と同じ名前になり、消し合う
            '別の作業の頭' => ['ec_test_x', 'ec_test_y_t_', 'media-testing-x', 1, '店の DB の頭'],
            // ほかの作業の店の DB の名前（ec_test_g_t_abc）を中央の DB にした形。頭と置き場は既定の形を求めるので、それも合わない
            'ほかの作業の店の DB' => ['ec_test_g_t_abc', 'ec_test_g_t_abc_t_', 'media-testing-g-t-abc', 3, 'テスト用の名前ではありません'],
            '区切りが 2 つ' => ['ec_test_g_h', 'ec_test_g_h_t_', 'media-testing-g-h', 3, 'テスト用の名前ではありません'],
            // t: 頭が ec_test_t_t_ になり、既定の店の DB の頭 ec_test_t_ と重なる
            '文字が t' => ['ec_test_t', 'ec_test_t_t_', 'media-testing-t', 3, 'テスト用の名前ではありません'],
            '大文字' => ['ec_test_G', 'ec_test_G_t_', 'media-testing-G', 3, 'テスト用の名前ではありません'],
            '区切りが空' => ['ec_test_', 'ec_test__t_', 'media-testing-', 3, 'テスト用の名前ではありません'],
        ];
    }

    #[DataProvider('mismatchedForPhpunit')]
    public function test_the_tests_stop_on_a_set_that_does_not_match(string $database, string $prefix, string $media, int $count, string $reason): void
    {
        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, self::phpunitValues($database, $prefix, $media));

        $this->assertCount($count, $problems, implode("\n", $problems));
        $this->assertStringContainsString($reason, implode("\n", $problems));
    }

    public function test_a_config_cache_stops_the_tests(): void
    {
        $values = ['config_cache' => '/work/bootstrap/cache/config.php'] + self::phpunitValues();

        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, $values);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('設定のキャッシュ（/work/bootstrap/cache/config.php）', $problems[0]);
        $this->assertStringContainsString('php artisan config:clear', $problems[0]);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function notTestDatabases(): array
    {
        return [
            '開発の中央の DB' => ['ec_central'],
            'ブラウザのテストの DB' => ['ec_e2e'],
            '開発の店の DB' => ['ec_t_demo'],
            'Laravel の既定' => ['laravel'],
            '頭だけ似ている' => ['ec_testing'],
            '前に何か付く' => ['x_ec_test'],
            '大文字' => ['EC_TEST'],
            '空' => [''],
            '無い' => [null],
        ];
    }

    #[DataProvider('notTestDatabases')]
    public function test_a_database_that_is_not_for_tests_stops_the_tests(?string $database): void
    {
        $values = ['connections' => ['pgsql' => ['database' => $database, 'url' => '']]] + self::phpunitValues();

        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, $values);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('テスト用の名前ではありません', implode("\n", $problems));
    }

    public function test_every_connection_the_tests_use_is_checked(): void
    {
        // 既定の接続はテスト用でも、テナントの中央の接続が別の DB を指していれば止める
        $values = ['connections' => [
            'pgsql' => ['database' => 'ec_test', 'url' => ''],
            'central' => ['database' => 'ec_central', 'url' => null],
        ]] + self::phpunitValues();

        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, $values);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('central', $problems[0]);
    }

    public function test_a_database_url_stops_the_tests_without_showing_it(): void
    {
        // DB_URL は DB の名前を上書きする。合言葉が入るので、理由の文には出さない
        $values = ['connections' => ['pgsql' => ['database' => 'ec_test', 'url' => 'pgsql://ec_app:secret-pass@127.0.0.1:54329/ec_central']]] + self::phpunitValues();

        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, $values);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('DB_URL', $problems[0]);
        $this->assertStringNotContainsString('secret-pass', $problems[0]);
        $this->assertStringNotContainsString('ec_central', $problems[0]);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function badTenantPrefixes(): array
    {
        return [
            '開発の店の頭' => ['ec_test', 'ec_t_'],
            'ブラウザのテストの店の頭' => ['ec_test', 'ec_e2e_t_'],
            '終わりの _t_ が無い' => ['ec_test', 'ec_test_'],
            '空（店の ID がそのまま DB の名前になる）' => ['ec_test', ''],
            '無い' => ['ec_test', null],
            // 中央の DB と組になっていないと、ほかの作業場所のテストの店とぶつかる（同じ名前の店の DB を作り、消し合う）
            'ほかの作業場所の頭' => ['ec_test_g', 'ec_test_t_'],
            '既定の DB に別名の頭' => ['ec_test', 'ec_test_g_t_'],
        ];
    }

    #[DataProvider('badTenantPrefixes')]
    public function test_a_tenant_prefix_that_is_not_for_tests_stops_the_tests(string $database, ?string $prefix): void
    {
        $values = ['tenant_prefix' => $prefix] + self::phpunitValues($database);

        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, $values);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('店の DB の頭', $problems[0]);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function notTestingEnvironments(): array
    {
        return ['開発' => ['local'], '本番' => ['production'], '無い' => [null]];
    }

    #[DataProvider('notTestingEnvironments')]
    public function test_an_environment_other_than_testing_stops_the_tests(?string $env): void
    {
        $values = ['app_env' => $env] + self::phpunitValues();

        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, $values);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('APP_ENV', $problems[0]);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function notTestMediaRoots(): array
    {
        return [
            '開発の保存先' => ['/work/storage/app/media'],
            'ブラウザのテストの保存先' => ['/work/storage/app/media-e2e'],
            '頭だけ似ている' => ['/work/storage/app/media-testingx'],
            '無い' => [null],
        ];
    }

    #[DataProvider('notTestMediaRoots')]
    public function test_a_media_root_that_is_not_for_tests_stops_the_tests(?string $root): void
    {
        $values = ['media_root' => $root] + self::phpunitValues();

        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, $values);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('写真の保存先', $problems[0]);
    }

    public function test_the_browser_tests_stop_on_settings_that_are_not_theirs(): void
    {
        $this->assertSame([], TestEnvironmentGuard::problems(TestEnvironmentGuard::E2E, self::e2eValues()));

        $cases = [
            ['config_cache' => '/work/bootstrap/cache/config.php'],
            ['app_env' => 'production'],
            ['connections' => ['pgsql' => ['database' => 'ec_central', 'url' => '']]],
            ['connections' => ['pgsql' => ['database' => 'ec_test', 'url' => '']]],
            ['connections' => ['pgsql' => ['database' => 'ec_e2e', 'url' => 'pgsql://u:p@h/ec_central']]],
            ['tenant_prefix' => 'ec_t_'],
            ['tenant_prefix' => 'ec_test_t_'],
            ['media_root' => '/work/storage/app/media'],
            ['media_root' => '/work/storage/app/media-testing'],
        ];

        foreach ($cases as $case) {
            $this->assertCount(1, TestEnvironmentGuard::problems(TestEnvironmentGuard::E2E, $case + self::e2eValues()), (string) json_encode($case));
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function allowedForBrowserTests(): array
    {
        return [
            '既定' => ['ec_e2e', 'ec_e2e_t_', 'media-e2e'],
            '作業ごとの別名' => ['ec_e2e_s', 'ec_e2e_s_t_', 'media-e2e-s'],
            '別名（2 文字）' => ['ec_e2e_bt', 'ec_e2e_bt_t_', 'media-e2e-bt'],
        ];
    }

    #[DataProvider('allowedForBrowserTests')]
    public function test_the_browser_tests_allow_a_matching_set_per_work(string $database, string $prefix, string $media): void
    {
        $this->assertSame([], TestEnvironmentGuard::problems(TestEnvironmentGuard::E2E, self::e2eValues($database, $prefix, $media)));
        $this->assertTrue(TestEnvironmentGuard::isBrowserTestDatabase($database));
        $this->assertSame($media, TestEnvironmentGuard::browserTestMediaDirectory($database));
    }

    /**
     * 組が合わない・作業ごとでない形。止める理由の数と、理由に入る言葉
     *
     * @return array<string, array{string, string, string, int, string}>
     */
    public static function mismatchedForBrowserTests(): array
    {
        return [
            // 頭がほかの作業のもの: 作るときにほかの作業の店の DB と同じ名前になり、消し合う
            '別の作業の頭' => ['ec_e2e_x', 'ec_e2e_y_t_', 'media-e2e-x', 1, '店の DB の頭'],
            '既定の頭のまま' => ['ec_e2e_s', 'ec_e2e_t_', 'media-e2e-s', 1, '店の DB の頭'],
            '既定の DB に別名の頭' => ['ec_e2e', 'ec_e2e_s_t_', 'media-e2e', 1, '店の DB の頭'],
            // 置き場が作業ごとでない: 既定の置き場や、ほかの作業の置き場の写真を消しうる
            '保存先が既定のまま' => ['ec_e2e_s', 'ec_e2e_s_t_', 'media-e2e', 1, '写真の保存先'],
            '保存先がほかの作業のもの' => ['ec_e2e_s', 'ec_e2e_s_t_', 'media-e2e-x', 1, '写真の保存先'],
            '既定の DB に別名の保存先' => ['ec_e2e', 'ec_e2e_t_', 'media-e2e-s', 1, '写真の保存先'],
            'PHP のテストの保存先' => ['ec_e2e_s', 'ec_e2e_s_t_', 'media-testing-s', 1, '写真の保存先'],
            // 開発やほかのテストの DB。頭と置き場は既定の形を求めるので、それも合わない
            '開発の中央の DB' => ['ec_central', 'ec_central_t_', 'media-e2e', 2, 'テスト用の名前ではありません'],
            'PHP のテストの DB' => ['ec_test_s', 'ec_test_s_t_', 'media-e2e', 2, 'テスト用の名前ではありません'],
            // 区切りが 2 つ: ほかの作業の店の DB（ec_e2e_s_t_…）と見分けにくい
            '区切りが 2 つ' => ['ec_e2e_s_t', 'ec_e2e_s_t_t_', 'media-e2e-s-t', 3, 'テスト用の名前ではありません'],
            // t: 頭が ec_e2e_t_t_ になり、既定の店の DB の頭 ec_e2e_t_ と重なる
            '文字が t' => ['ec_e2e_t', 'ec_e2e_t_t_', 'media-e2e-t', 3, 'テスト用の名前ではありません'],
            '大文字' => ['ec_e2e_S', 'ec_e2e_S_t_', 'media-e2e-S', 3, 'テスト用の名前ではありません'],
            '頭だけ似ている' => ['ec_e2ex', 'ec_e2ex_t_', 'media-e2ex', 3, 'テスト用の名前ではありません'],
        ];
    }

    #[DataProvider('mismatchedForBrowserTests')]
    public function test_the_browser_tests_stop_on_a_set_that_does_not_match(string $database, string $prefix, string $media, int $count, string $reason): void
    {
        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::E2E, self::e2eValues($database, $prefix, $media));

        $this->assertCount($count, $problems, implode("\n", $problems));
        $this->assertStringContainsString($reason, implode("\n", $problems));
    }

    public function test_every_connection_must_point_to_the_same_database(): void
    {
        // どちらもテスト用の名前でも、組は最初の接続の DB で決めるので、違う DB を指す接続があれば止める
        $connections = ['connections' => [
            'pgsql' => ['database' => 'ec_e2e_s', 'url' => ''],
            'central' => ['database' => 'ec_e2e', 'url' => null],
        ]];

        $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::E2E, $connections + self::e2eValues('ec_e2e_s', null, 'media-e2e-s'));

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('central', $problems[0]);
        $this->assertStringContainsString('そろえて', $problems[0]);

        $phpunit = ['connections' => [
            'pgsql' => ['database' => 'ec_test_g', 'url' => ''],
            'central' => ['database' => 'ec_test', 'url' => null],
        ]];
        $this->assertCount(1, TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, $phpunit + self::phpunitValues('ec_test_g')));
    }

    public function test_assert_safe_throws_with_every_reason_and_what_to_do(): void
    {
        $values = ['config_cache' => '/work/bootstrap/cache/config.php', 'connections' => ['pgsql' => ['database' => 'ec_central', 'url' => '']]] + self::phpunitValues();

        try {
            TestEnvironmentGuard::assertSafe(TestEnvironmentGuard::PHPUNIT, $values);
            $this->fail('止まらなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('テストを始める前に止めました', $e->getMessage());
            $this->assertStringContainsString('設定のキャッシュ', $e->getMessage());
            $this->assertStringContainsString('ec_central', $e->getMessage());
        }

        TestEnvironmentGuard::assertSafe(TestEnvironmentGuard::PHPUNIT, self::phpunitValues());
        $this->addToAssertionCount(1);
    }
}
