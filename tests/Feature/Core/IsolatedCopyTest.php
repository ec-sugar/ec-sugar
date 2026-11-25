<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\IsolatedCopy;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Stripe\StripeApi;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\OperatorSession;
use Tests\TestCase;

/**
 * 隔離した写し（ADR 0082）のアプリの側の守り。印（app.isolated）があると、Http の口は送る前に断り（偽の答えより先）、
 * Stripe の口は支払いの代行へ送らず、メールは Mailpit か送らない形になり、管理画面の /auth/me が帯を出す印を返す。印が無ければ何も変えない
 */
final class IsolatedCopyTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    public function test_outgoing_http_is_refused_before_anything_is_sent(): void
    {
        $this->isolate();
        // 偽の答えを用意しても、そこへ届く前に断る（本物の宛先へも、偽物へも送らない）
        Http::fake(['*' => Http::response('ok')]);

        try {
            Http::post('https://hooks.slack.com/services/T000/B000/xxxx', ['text' => '注文が入りました']);
            $this->fail('隔離した複製環境で外へ送れた');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(IsolatedCopy::REFUSED, $e->getMessage());
            $this->assertStringContainsString('hooks.slack.com', $e->getMessage());
            // 送信先のパスや本文（秘密の URL の後ろ・個人情報）は文に出さない
            $this->assertStringNotContainsString('B000', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_without_the_mark_nothing_changes(): void
    {
        config(['app.isolated' => false, 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);
        IsolatedCopy::guard(app(Factory::class));
        Http::fake(['*' => Http::response('ok')]);

        $this->assertSame('ok', Http::get('https://example.com/')->body());
        $this->assertSame('smtp', config('mail.default'));
        $this->assertFalse(IsolatedCopy::enabled());
    }

    public function test_mail_goes_to_mailpit_or_nowhere(): void
    {
        // 外への送信先の smtp は、送らずに手元にためる形へ
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com']);
        $this->isolate();
        $this->assertSame('array', config('mail.default'));

        // 記録（log）にも書かない（個人情報が記録に残る）
        config(['mail.default' => 'log']);
        $this->isolate();
        $this->assertSame('array', config('mail.default'));

        // Mailpit（ステージングの形の写し）はそのまま（届いたメールを確かめられる）
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit']);
        $this->isolate();
        $this->assertSame('smtp', config('mail.default'));
    }

    public function test_stripe_is_not_called(): void
    {
        $this->isolate();

        $this->expectException(PaymentUnavailableException::class);
        $this->expectExceptionMessage('確認のための複製');
        app(StripeApi::class)->client('sk_test_'.str_repeat('a', 24));
    }

    public function test_the_admin_is_told_to_show_the_banner(): void
    {
        $this->configureAdminHost();
        $this->enrolledOperator();

        $this->send('GET', '/auth/me')->assertOk()->assertJsonPath('isolated', false);

        config(['app.isolated' => true]);
        $this->send('GET', '/auth/me')->assertOk()->assertJsonPath('isolated', true);
    }

    /**
     * 印を付けて、起動の時と同じ守りを掛け直す（CoreServiceProvider の boot は印の無いまま済んでいる）
     */
    private function isolate(): void
    {
        config(['app.isolated' => true]);
        IsolatedCopy::guard(app(Factory::class));
    }
}
