<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorEnrollments;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\TestCase;

/**
 * パスキーが 1 本だけの運営への知らせ（1）。管理画面はサーバーが返す本数で知らせを出し、2 本目を足すと消す。
 * 本数は本人の分だけを返す（ほかの運営の本数や中身は出さない）。
 */
final class SinglePasskeyNoticeTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureAdminHost();
    }

    public function test_one_passkey_is_reported_and_adding_a_synced_second_one_clears_it(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();

        // 1 本だけ: ホーム（/auth/me）とパスキーの画面（/auth/passkeys）の両方で 1 本と分かる
        $this->send('GET', '/auth/me')->assertOk()
            ->assertJsonPath('passkeys.count', 1)
            ->assertJsonPath('passkeys.synced', false);
        $this->send('GET', '/auth/passkeys')->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('passkeys.0.synced', false);

        // 別の端末の同期のパスキー（iCloud キーチェーンなど）を足す
        $this->reconfirm($operator, $authenticator);
        $options = $this->ceremonyOptions('/auth/passkeys/options');
        $this->send('POST', '/auth/passkeys', ['name' => 'iPhone', 'credential' => $this->newAuthenticator(synced: true)->register($options)])->assertCreated();

        // 2 本: 知らせは出ない。同期のパスキーがあることは分かる（2 段階認証を勧める一言のため）
        $this->send('GET', '/auth/me')->assertOk()
            ->assertJsonPath('passkeys.count', 2)
            ->assertJsonPath('passkeys.synced', true);
        $this->send('GET', '/auth/passkeys')->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('passkeys.0.synced', false)
            ->assertJsonPath('passkeys.1.synced', true);
    }

    public function test_the_count_is_only_your_own_and_hidden_before_login(): void
    {
        // ログインの前は本数を出さない
        $this->assertStringNotContainsString('passkeys', (string) $this->send('GET', '/auth/me')->assertUnauthorized()->getContent());

        // ほかの運営が 2 本持っていても、自分の本数（1 本）だけが返る
        [$other, $otherAuthenticator] = $this->enrolledOperator('other@example.com');
        $this->reconfirm($other, $otherAuthenticator);
        $options = $this->ceremonyOptions('/auth/passkeys/options');
        $this->send('POST', '/auth/passkeys', ['name' => '予備', 'credential' => $this->newAuthenticator(synced: true)->register($options)])->assertCreated();
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->resetRateLimit();

        $staff = Operator::create(['name' => 'Staff', 'email' => 'staff@example.com']);
        $this->enroll(app(OperatorEnrollments::class)->issue($staff, 'test')['token'], $this->newAuthenticator());

        $this->send('GET', '/auth/me')->assertOk()
            ->assertJsonPath('operator.email', 'staff@example.com')
            ->assertJsonPath('passkeys', ['count' => 1, 'synced' => false]);
        $this->send('GET', '/auth/passkeys')->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonCount(1, 'passkeys');
    }
}
