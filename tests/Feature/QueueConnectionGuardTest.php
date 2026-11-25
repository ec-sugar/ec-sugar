<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Tenancy\QueueConnectionGuard;
use App\Tenancy\QueueConnectionMisconfigured;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * キューの Redis の接続が、店の名札（接頭辞）の付く接続（config/tenancy.php の redis.prefixed_connections）だったら、起動で止めること。
 * 名札が付くと、ショップで入れた仕事（注文のメールなど）が店ごとの鍵に入り、中央で動く送信担当（queue:work）から見えない。
 * 10-28 にメールが 14 時間届かなかった事故が、設定の書き換え（.env の REDIS_QUEUE_CONNECTION など）で黙って戻ってこないようにする。
 */
final class QueueConnectionGuardTest extends TestCase
{
    /** @var list<string>|null テストの中で書き換えるコマンドの引数（argv）の控え */
    private ?array $originalArgv = null;

    protected function tearDown(): void
    {
        if ($this->originalArgv !== null) {
            $_SERVER['argv'] = $this->originalArgv;
        }

        parent::tearDown();
    }

    public function test_the_shipped_settings_pass(): void
    {
        // 出荷の設定（キューは名札の付かない専用の接続 queue）では止まらない。このテストが動いていること自体が、起動が通った印
        $this->assertSame('queue', config('queue.connections.redis.connection'));

        QueueConnectionGuard::check(config());
        $this->addToAssertionCount(1);
    }

    public function test_it_stops_when_the_queue_uses_the_default_redis_connection(): void
    {
        config(['queue.connections.redis.connection' => 'default']);

        $this->expectException(QueueConnectionMisconfigured::class);
        $this->expectExceptionMessageMatches('/キューの接続 redis が使う Redis の接続 default に、店のプレフィックス/u');

        QueueConnectionGuard::check(config());
    }

    public function test_it_stops_when_the_queue_uses_the_cache_redis_connection(): void
    {
        config(['queue.connections.redis.connection' => 'cache']);

        $this->expectException(QueueConnectionMisconfigured::class);

        QueueConnectionGuard::check(config());
    }

    /**
     * 接続の名前が無い・空のとき、Laravel は Redis の default を使う（RedisManager::connection）。default には名札が付くので止める
     *
     * @return array<string, array{0: mixed}>
     */
    public static function namesThatMeanDefault(): array
    {
        return [
            '無い（null）' => [null],
            '空の文字' => [''],
            '0 の文字（Laravel は空と同じに扱う）' => ['0'],
        ];
    }

    #[DataProvider('namesThatMeanDefault')]
    public function test_it_stops_when_the_queue_connection_name_falls_back_to_default(mixed $name): void
    {
        config(['queue.connections.redis.connection' => $name]);

        $this->expectException(QueueConnectionMisconfigured::class);

        QueueConnectionGuard::check(config());
    }

    public function test_it_stops_when_the_queue_connection_key_is_missing(): void
    {
        $redis = config('queue.connections.redis');
        unset($redis['connection']);
        config(['queue.connections.redis' => $redis]);

        $this->expectException(QueueConnectionMisconfigured::class);

        QueueConnectionGuard::check(config());
    }

    public function test_it_stops_when_the_queue_connection_is_added_to_the_prefixed_connections(): void
    {
        // 接続はそのままでも、名札を付ける側の一覧に queue を足されたら同じ事故になる
        config(['tenancy.redis.prefixed_connections' => ['default', 'cache', 'queue']]);

        $this->expectException(QueueConnectionMisconfigured::class);

        QueueConnectionGuard::check(config());
    }

    public function test_it_stops_when_the_prefixed_connections_name_default_by_an_empty_name(): void
    {
        // tenancy 側の一覧の空の名前も、Redis の default を指す（RedisTenancyBootstrapper は Redis::connection(名前) で名札を付ける）
        config([
            'queue.connections.redis.connection' => 'default',
            'tenancy.redis.prefixed_connections' => [null],
        ]);

        $this->expectException(QueueConnectionMisconfigured::class);

        QueueConnectionGuard::check(config());
    }

    public function test_it_checks_every_redis_queue_even_if_the_default_queue_is_not_redis(): void
    {
        // 送信担当は既定のキューに関係なく「queue:work redis」と名指しで動き、仕事も onConnection で接続を選べる。既定が sync でも見る
        config([
            'queue.default' => 'sync',
            'queue.connections.redis-high' => ['driver' => 'redis', 'connection' => 'cache', 'queue' => 'high'],
        ]);

        $this->expectException(QueueConnectionMisconfigured::class);
        $this->expectExceptionMessageMatches('/キューの接続 redis-high が使う Redis の接続 cache に/u');

        QueueConnectionGuard::check(config());
    }

    public function test_it_ignores_queues_that_do_not_use_redis(): void
    {
        // database の connection は DB の接続の名前で、Redis の名札とは関係ない。同じ名前 default でも止めない
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'default',
            'queue.connections.sync' => ['driver' => 'sync'],
        ]);

        QueueConnectionGuard::check(config());
        $this->addToAssertionCount(1);
    }

    public function test_it_stops_on_a_queue_connection_name_that_is_not_text(): void
    {
        // 名前が文字でないと、どの Redis の接続に名札が付くか確かめられない。分からないものは通さない
        config(['queue.connections.redis.connection' => ['default']]);

        $this->expectException(QueueConnectionMisconfigured::class);

        QueueConnectionGuard::check(config());
    }

    public function test_enforce_stops_artisan_commands_such_as_migrate(): void
    {
        $this->pretendArtisanCommand('migrate');
        config(['queue.connections.redis.connection' => 'default']);

        $this->expectException(QueueConnectionMisconfigured::class);

        QueueConnectionGuard::enforce($this->app);
    }

    public function test_enforce_lets_the_commands_that_clear_the_config_cache_through(): void
    {
        // 誤った設定が控え（bootstrap/cache/config.php）に焼かれていると、.env を直しても控えを消すまで直らない。消すコマンドだけは通す
        config(['queue.connections.redis.connection' => 'default']);

        foreach (['config:clear', 'optimize:clear'] as $command) {
            $this->pretendArtisanCommand($command);
            QueueConnectionGuard::enforce($this->app);
        }

        $this->addToAssertionCount(1);
    }

    public function test_booting_the_app_with_a_wrong_queue_connection_stops(): void
    {
        // 本物の起動で確かめる: 別のプロセスで .env の書き換えと同じ形（環境変数）にして artisan を起こす
        $result = Process::path(base_path())
            ->env(['REDIS_QUEUE_CONNECTION' => 'default'])
            ->run([PHP_BINARY, 'artisan', '--version']);

        $this->assertFalse($result->successful(), '誤った設定で起動が通った');
        // artisan は理由の文を画面の幅で折り返すので、空白を除いてから探す
        $said = (string) preg_replace('/\s+/u', '', $result->output().$result->errorOutput());
        $this->assertStringContainsString('キューの接続redisが使うRedisの接続defaultに、店のプレフィックス', $said);
    }

    public function test_booting_the_app_with_the_shipped_queue_connection_passes(): void
    {
        $result = Process::path(base_path())
            ->env(['REDIS_QUEUE_CONNECTION' => 'queue'])
            ->run([PHP_BINARY, 'artisan', '--version']);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }

    private function pretendArtisanCommand(string $command): void
    {
        $this->originalArgv ??= $_SERVER['argv'];
        $_SERVER['argv'] = ['artisan', $command];
    }
}
