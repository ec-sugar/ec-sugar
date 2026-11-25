<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * 確かめ口（/up。攻撃テスト第 2 回）。応答の番号だけで確かめられ、枠組みも外のサイトも見せない
 */
final class HealthTest extends TestCase
{
    use DatabaseTruncation;

    public function test_up_answers_plain_text_on_every_host(): void
    {
        foreach (['http://admin.localhost/up', 'http://localhost/up'] as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $this->assertSame("ok\n", $response->getContent());
            $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
            $this->assertNull($response->getCookie('XSRF-TOKEN'));
        }
    }

    public function test_up_says_down_without_the_reason_even_in_debug(): void
    {
        config(['app.debug' => true]);
        Event::listen(DiagnosingHealth::class, static fn () => throw new RuntimeException('秘密の中身 db-password'));

        $response = $this->get('http://admin.localhost/up');

        $response->assertStatus(500);
        $this->assertSame("down\n", $response->getContent());
        $this->assertStringNotContainsString('秘密', (string) $response->getContent());
    }
}
