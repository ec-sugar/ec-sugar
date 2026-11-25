<?php

declare(strict_types=1);

/*
| PHP のテストの入口（phpunit.xml の bootstrap）。
|
| テストは表を空にし、店を作っては消す。設定の控え（bootstrap/cache/config.php）がある・テスト用でない DB や写真の置き場を
| 指しているなら、テストを 1 つも始めずに理由を出して止める。phpunit.xml の <env> はこのファイルより前に入っている。
| 読み込んだ設定そのものは、テストごとに Tests\TestCase::createApplication がもう一度確かめる（表を空にする前）。
|
| 並べて流すとき（`php artisan test --parallel`。ADR 0057）は、paratest が各々の処理に番号（TEST_TOKEN）を渡す。
| 元の組（例 ec_test_o・ec_test_o_t_・media-testing-o）を確かめてから、各々の組（ec_test_op1・ec_test_op1_t_・media-testing-op1）に
| 差し替え、各々の中央の DB が無ければ作る。Laravel の「並べたときに DB の名前へ _test_<番号> を足す」仕組みは、入口の守りの決まりの外の
| 名前になるので止める（LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES）。
*/

use Illuminate\Support\Env;
use Tests\Support\TestEnvironmentGuard;

require __DIR__.'/../vendor/autoload.php';

$check = static function (): void {
    $problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::PHPUNIT, TestEnvironmentGuard::valuesFromEnvironment(dirname(__DIR__)));

    if ($problems !== []) {
        fwrite(STDERR, TestEnvironmentGuard::message($problems));
        exit(1);
    }
};

$check();

$token = Env::get('TEST_TOKEN');

// 差し替えは並べた各々の処理で 1 度だけ。テストが子の処理で phpunit を走らせても（TestEntryGuardTest）、子は番号を受け継ぐが
// 渡された組のまま確かめる（さらに p<番号> を足した DB を作らない）。印は EC_TEST_PARALLEL_DATABASE
if (is_string($token) && $token !== '' && Env::get('EC_TEST_PARALLEL_DATABASE') === null) {
    $base = (string) Env::get('DB_DATABASE');
    $worker = TestEnvironmentGuard::parallelPhpunitDatabase($base, $token);

    $set = static function (string $name, string $value): void {
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    };

    $set('DB_DATABASE', $worker);
    $set('TENANT_DB_PREFIX', $worker.'_t_');
    $set('MEDIA_DIRECTORY', TestEnvironmentGuard::phpunitTestMediaDirectory($worker));
    $set('LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES', '1');
    $set('EC_TEST_PARALLEL_DATABASE', $worker);

    // 差し替えた後の組も、同じ決まりで確かめる
    $check();

    // 各々の中央の DB が無ければ、元の DB につないで作る（中身の表は、最初のテストの DatabaseTruncation が流す移行で作られる）
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', Env::get('DB_HOST'), Env::get('DB_PORT'), $base),
        (string) Env::get('DB_USERNAME'),
        (string) Env::get('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
    $exists->execute([$worker]);

    if ($exists->fetchColumn() === false) {
        try {
            // 名前は上の決まり（英小文字と数字と _）を通ったものだけ
            $pdo->exec('CREATE DATABASE "'.$worker.'"');
        } catch (PDOException $e) {
            // 同じ名前を同時に作った（作業を重ねて走らせた）ときは、もうあるので進める
            if ($e->getCode() !== '42P04') {
                throw $e;
            }
        }
    }

    unset($pdo, $exists, $base, $worker);
}
