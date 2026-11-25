<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\JsonBodyLimit;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 大きな JSON でメモリを使い切らせない（システム全体の見直しで見つけた）。
 * Laravel は要求を作る所（Request::capture）で JSON の本文を丸ごと読み解き、ミドルウェア・ログイン・回数の制限より前に効く。
 * - どのホストでも、JSON の本文は 2 MB（JsonBodyLimit::MAX_BYTES）まで。public/index.php が Request::capture の前に見て、超えたら 413（Laravel を起こさない）。
 *   Content-Length の見出しで超えると分かれば本文を読まない
 * - ショップは JSON の本文を受けない（415。決済代行の通知は別の群で、生の本文を読んで署名を確かめる）
 * nginx の側の上限（ショップのホストは 1m、管理画面の API だけ 12m）は docker/check-compose.sh が見る
 */
final class RequestBodyLimitTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_json_over_the_limit_is_refused_by_the_header_without_reading_the_body(): void
    {
        $max = JsonBodyLimit::MAX_BYTES;
        $read = function (int $bytes): string {
            $this->fail('見出しで決まる時は本文を読まない');
        };

        foreach (['application/json', 'application/json; charset=utf-8', 'application/vnd.api+json', 'APPLICATION/JSON', 'text/json'] as $type) {
            $this->assertTrue(JsonBodyLimit::refuses(['CONTENT_TYPE' => $type, 'CONTENT_LENGTH' => (string) ($max + 1)], $read), $type);
            $this->assertFalse(JsonBodyLimit::refuses(['CONTENT_TYPE' => $type, 'CONTENT_LENGTH' => (string) $max], $read), $type);
        }

        // 数でない・負の・桁の多すぎる見出しも断る
        foreach (['-1', '1e9', '99999999999', '12abc', '0x10'] as $declared) {
            $this->assertTrue(JsonBodyLimit::refuses(['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => $declared], $read), $declared);
        }

        // フォームの形・ファイル・本文の型の無い要求は見ない（PHP と nginx の上限に任せる）
        foreach (['application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', 'text/plain', ''] as $type) {
            $this->assertFalse(JsonBodyLimit::refuses(['CONTENT_TYPE' => $type, 'CONTENT_LENGTH' => '999999999'], $read), $type);
        }
    }

    public function test_json_without_a_length_header_is_measured_up_to_one_byte_over_the_limit(): void
    {
        $max = JsonBodyLimit::MAX_BYTES;
        $asked = [];
        $body = static function (int $size) use (&$asked): Closure {
            return static function (int $bytes) use ($size, &$asked): string {
                $asked[] = $bytes;

                return str_repeat('a', min($size, $bytes));
            };
        };

        $this->assertTrue(JsonBodyLimit::refuses(['CONTENT_TYPE' => 'application/json'], $body(50 * 1024 * 1024)));
        $this->assertFalse(JsonBodyLimit::refuses(['CONTENT_TYPE' => 'application/json'], $body($max)));
        $this->assertFalse(JsonBodyLimit::refuses(['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => ''], $body(10)));
        // 読むのは上限 + 1 バイトまで（大きな本文を丸ごと読まない）
        $this->assertSame([$max + 1, $max + 1, $max + 1], $asked);
    }

    public function test_the_php_entry_refuses_before_laravel_reads_the_request(): void
    {
        // public/index.php を CGI の形の値で動かす。断る時は Laravel を起こさず、決まった文だけを返して終える
        $process = new Process([PHP_BINARY, 'public/index.php'], base_path(), [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) (JsonBodyLimit::MAX_BYTES + 1),
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/auth/login',
            'HTTP_HOST' => 'admin.localhost',
        ]);
        $process->mustRun();

        $this->assertSame(JsonBodyLimit::MESSAGE, $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        // 入口の順: 断る所は Request::capture() より前
        $entry = (string) file_get_contents(public_path('index.php'));
        $this->assertLessThan(strpos($entry, '$app->handleRequest(Request::capture());'), strpos($entry, "\nJsonBodyLimit::guard();"));
    }

    public function test_the_shop_refuses_json_bodies_but_payment_notices_still_arrive(): void
    {
        $this->tenant('rbm');

        foreach (['/cart/items', '/account/login', '/checkout', '/orders', '/p/newsletter'] as $path) {
            $this->call('POST', "http://rbm.localhost{$path}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"email":"hanako@example.com"}')
                ->assertStatus(Response::HTTP_UNSUPPORTED_MEDIA_TYPE)->assertHeader('X-Content-Type-Options', 'nosniff');
            tenancy()->end();
        }

        // 画面を求める呼び出しには文で
        $this->call('POST', 'http://rbm.localhost/cart/items', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], '{}')
            ->assertStatus(Response::HTTP_UNSUPPORTED_MEDIA_TYPE)->assertSeeText('この形式の送信は受け付けていません。');
        tenancy()->end();

        // 本文を持たない読み出し（GET）は、JSON の見出しが付いていても断らない（郵便番号の検索など。書き込みの POST は上で断っている）
        $this->assertNotSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $this->call('GET', 'http://rbm.localhost/postal/1500001', [], [], [], ['CONTENT_TYPE' => 'application/json'])->getStatusCode());
        tenancy()->end();

        // 決済代行の通知（セッションの無い群）は JSON のまま受け口へ届く（署名が無いので 400）
        $this->call('POST', 'http://rbm.localhost/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"id":"evt_1"}')->assertStatus(400);
    }
}
