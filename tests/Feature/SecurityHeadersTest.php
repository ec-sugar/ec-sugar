<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 防御ヘッダが全応答に付くこと（App\Http\Middleware\SecurityHeaders）。
 */
final class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_present_on_responses(): void
    {
        $response = $this->getJson('http://localhost/');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

        // Symfony が private を足すので完全一致ではなく含有で見る
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->getJson('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
        $this->getJson('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
