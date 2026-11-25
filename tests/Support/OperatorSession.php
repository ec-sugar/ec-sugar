<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorEnrollments;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面のテスト用: ブラウザと同じように管理画面のホストへ JSON で送り、セッション Cookie を持ち越す。
 * パスキーの儀式は SoftwareAuthenticator で本物の検証を通す。setUp で configureAdminHost() を呼ぶ。
 */
trait OperatorSession
{
    private string $adminHost = 'http://admin.localhost';

    private ?string $sessionId = null;

    private function configureAdminHost(): void
    {
        config([
            'admin.url' => $this->adminHost,
            'passkeys.relying_party_id' => 'admin.localhost',
            'passkeys.allowed_origins' => [$this->adminHost],
        ]);
    }

    private function newAuthenticator(bool $synced = false): SoftwareAuthenticator
    {
        return new SoftwareAuthenticator('admin.localhost', $this->adminHost, $synced);
    }

    /**
     * 運営を作り、パスキーを登録してログインした状態にする（役割は既定でオーナー）。
     *
     * @return array{0: Operator, 1: SoftwareAuthenticator}
     */
    private function enrolledOperator(string $email = 'hana@example.com', string $role = 'owner'): array
    {
        // 新しい運営の既定はスタッフ（どの店にも入れない。ADR 0024）。テストは既定でオーナーにする
        $operator = Operator::create(['name' => 'Hana', 'email' => $email]);
        $operator->forceFill(['role' => $role])->save();
        $authenticator = $this->newAuthenticator();
        $this->enroll(app(OperatorEnrollments::class)->issue($operator, 'test')['token'], $authenticator);

        return [$operator, $authenticator];
    }

    private function enroll(string $token, SoftwareAuthenticator $authenticator): void
    {
        $this->send('POST', '/auth/enrollment', ['token' => $token])->assertOk();
        $options = $this->ceremonyOptions('/auth/enrollment/options');
        $this->send('POST', '/auth/enrollment/passkey', ['name' => 'MacBook', 'credential' => $authenticator->register($options)])->assertCreated();
    }

    /**
     * @return array<string, mixed>
     */
    private function ceremonyOptions(string $path): array
    {
        $options = $this->send('GET', $path)->assertOk()->json('options');
        $this->assertIsArray($options);

        return $options;
    }

    /**
     * 試行回数の制限のカウンタを空にする（テストの中で何度もログインするため）。
     */
    /**
     * 重要な操作の前の再確認（パスキーでもう一度）。この後しばらくはお金の操作などが通る
     */
    private function reconfirm(Operator $operator, SoftwareAuthenticator $authenticator): void
    {
        $options = $this->ceremonyOptions('/auth/confirm/options');
        $this->send('POST', '/auth/confirm', ['credential' => $authenticator->assert($options, $operator->getPasskeyUserHandle())])->assertNoContent();
    }

    private function resetRateLimit(): void
    {
        Cache::store()->flush();
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return TestResponse<Response>
     */
    private function send(string $method, string $uri, array $parameters = []): TestResponse
    {
        $cookie = (string) config('admin.session_cookie');

        if ($this->sessionId !== null) {
            $this->withCookie($cookie, $this->sessionId);
        }

        $url = str_starts_with($uri, 'http') ? $uri : $this->adminHost.$uri;
        $response = $method === 'GET'
            ? $this->getJson($url)
            : $this->json($method, $url, $parameters);

        $next = $response->getCookie($cookie);

        if ($next !== null) {
            $this->sessionId = $next->getValue();
        }

        return $response;
    }
}
