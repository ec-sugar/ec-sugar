<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 店の数の形とショップのホストの親（config/tls.php）は、新しい名前 EC_SUGAR_… を先に、名前を移す前のサーバーの旧名 SUGAR_EC_… も読む。
 * 新しく入れたサーバーの env は新しい名前だけなので、旧名だけを読むと複数の店の形で店のホストの証明書を取らせなくなる
 */
final class TlsConfigNamesTest extends TestCase
{
    private const array NAMES = ['EC_SUGAR_SHOPS', 'SUGAR_EC_SHOPS', 'EC_SUGAR_SHOP_DOMAIN', 'SUGAR_EC_SHOP_DOMAIN'];

    protected function tearDown(): void
    {
        foreach (self::NAMES as $name) {
            $this->unsetEnv($name);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $env
     */
    #[DataProvider('cases')]
    public function test_new_names_are_read_first_and_old_names_still_work(array $env, string $shops, ?string $domain): void
    {
        foreach ($env as $name => $value) {
            $this->setEnv($name, $value);
        }

        $config = require base_path('config/tls.php');

        $this->assertSame($shops, $config['shops']);
        $this->assertSame($domain, $config['shop_domain']);
    }

    /**
     * @return iterable<string, array{array<string, string>, string, ?string}>
     */
    public static function cases(): iterable
    {
        yield '新しい名前だけ（新しく入れたサーバー）' => [['EC_SUGAR_SHOPS' => 'multi', 'EC_SUGAR_SHOP_DOMAIN' => 'new.example.jp'], 'multi', 'new.example.jp'];
        yield '旧名だけ（名前を移す前のサーバー）' => [['SUGAR_EC_SHOPS' => 'multi', 'SUGAR_EC_SHOP_DOMAIN' => 'old.example.jp'], 'multi', 'old.example.jp'];
        yield '両方なら新しい名前' => [['EC_SUGAR_SHOPS' => 'single', 'SUGAR_EC_SHOPS' => 'multi', 'EC_SUGAR_SHOP_DOMAIN' => 'new.example.jp', 'SUGAR_EC_SHOP_DOMAIN' => 'old.example.jp'], 'single', 'new.example.jp'];
        yield 'どちらも無ければ 1 店舗・親なし' => [[], 'single', null];
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function unsetEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
