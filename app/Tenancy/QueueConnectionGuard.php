<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * キューの Redis の接続が、店の名札（接頭辞）の付く接続なら起動で止める。
 *
 * stancl/tenancy は、config/tenancy.php の redis.prefixed_connections にある Redis の接続に、店の文脈の間だけ店ごとの名札を付ける
 * （RedisTenancyBootstrapper）。キューがその接続を使うと、ショップで入れた仕事（注文のメールなど）が名札つきの鍵に入り、
 * 中央で動く送信担当（queue:work redis）から見えない。10-28 にメールが 14 時間届かなかった。
 * 出荷の設定はキュー専用の名札の付かない接続 queue（config/database.php の redis.queue）を使うが、.env の REDIS_QUEUE_CONNECTION などで
 * 書き換えられても起動は普通に通り、メールが黙って届かなくなる。気づけない誤りなので、動き出す前に止める。
 *
 * 見る範囲:
 *   - driver が redis のキューの接続はすべて見る。既定のキュー（queue.default）が sync や database でも見る。
 *     送信担当は既定に関係なく「queue:work redis」と名指しで動き、仕事も onConnection で接続を選べるので、既定だけでは足りない
 *   - redis 以外（sync・database・deferred など）は見ない。Redis の名札と関係しない（database の connection は DB の接続の名前）。
 *     failover は名前で別のキューの接続を指すだけなので、指された先の redis の接続をここで見ている
 *   - phpredis が無く名札を付ける仕組みが外れている手元でも見る。設定そのものの誤りで、本番（phpredis あり）では事故になるため
 *
 * 止める場所: 本番・開発・テストのどれでも止める。web の要求も、送信担当も、migrate などの artisan のコマンドも止まる。
 * 配備の途中（移行の段）で止まれば、誤った設定のまま客の注文を受けずに済む。
 * 通すのは設定の控え（bootstrap/cache/config.php）を消すコマンドだけ。誤った設定が控えに焼かれていると、
 * .env を直しても控えを消すまで直らないので、消す手立てまで止めない。
 */
final class QueueConnectionGuard
{
    /** 誤った設定でも通すコマンド（設定の控えを消すものだけ。控えを消せば、直した .env が読まれる） */
    private const array RECOVERY_COMMANDS = ['config:clear', 'optimize:clear'];

    public static function enforce(Application $app): void
    {
        // 何のコマンドかは Laravel の runningConsoleCommand と同じく、引数（argv）の 2 番目で見る（契約の Application には無いので自前で）
        if ($app->runningInConsole() && in_array($_SERVER['argv'][1] ?? null, self::RECOVERY_COMMANDS, true)) {
            return;
        }

        self::check($app->make('config'));
    }

    /**
     * @throws QueueConnectionMisconfigured
     */
    public static function check(Repository $config): void
    {
        $prefixed = array_map(
            static fn (mixed $name): ?string => self::redisConnectionName($name),
            (array) $config->get('tenancy.redis.prefixed_connections', []),
        );

        foreach ((array) $config->get('queue.connections', []) as $queue => $settings) {
            if (! is_array($settings) || ($settings['driver'] ?? null) !== 'redis') {
                continue;
            }

            // 接続の名前が無いときは、Laravel は Redis の default を使う（RedisConnector → RedisManager::connection）
            $connection = self::redisConnectionName($settings['connection'] ?? null);

            if ($connection === null) {
                throw new QueueConnectionMisconfigured(
                    "キューの接続 {$queue} の Redis の接続の名前（connection）が文字ではありません。"
                    .'どの接続に店のプレフィックスが付くか確認できないので、起動を止めました。'
                    .'プレフィックスの付かないキュー専用の接続 queue を指定してください（REDIS_QUEUE_CONNECTION=queue）。',
                );
            }

            if (in_array($connection, $prefixed, true)) {
                throw new QueueConnectionMisconfigured(
                    "キューの接続 {$queue} が使う Redis の接続 {$connection} に、店のプレフィックスが付く設定になっています"
                    .'（config/tenancy.php の redis.prefixed_connections）。'
                    .'このままでは、ショップで入れた仕事（注文のメールなど）が店ごとの鍵に入り、中央で動く送信担当（queue:work）に届かず、黙って送られません。'
                    .'キューにはプレフィックスの付かない専用の接続 queue（config/database.php の redis.queue）を使い（REDIS_QUEUE_CONNECTION=queue）、'
                    .'その接続を prefixed_connections に足さないでください。設定のキャッシュがあれば php artisan config:clear で消してください。',
                );
            }
        }
    }

    /**
     * Redis の接続の名前を、Laravel が実際に使う名前にそろえる。RedisManager::connection と同じく、空（null・''・'0'）は default。
     * 文字でも数でもない名前は null（確かめられない）。
     */
    private static function redisConnectionName(mixed $name): ?string
    {
        if ($name !== null && ! is_string($name) && ! is_int($name)) {
            return null;
        }

        $name = (string) $name;

        return $name === '' || $name === '0' ? 'default' : $name;
    }
}
