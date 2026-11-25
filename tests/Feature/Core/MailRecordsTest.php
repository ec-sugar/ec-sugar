<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Admin\Mail\ShopTestMail;
use App\Core\Crypto\BlindIndex;
use App\Core\Mail\MailRecords;
use App\Core\Mail\Models\MailRecord;
use App\Core\Mail\RecordsMail;
use App\Core\Sample\Models\DemoMail;
use App\Core\Trace\TraceId;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\WithTenants;
use Tests\TestCase;
use Throwable;

/**
 * メールの記録（ADR 0095）。店の文脈で札の付いたメールを送ると、送る口で「送っている → 送った・送れなかった」が店の DB に残る。
 * 送れなかった理由は決まった語と SMTP の番号だけ（文・宛先・本文は残さない）、宛先と件名は暗号化、トランザクションが巻き戻っても残る、
 * 同じ仕事のやり直しは 1 行、一斉の配信は送れなかった分だけ、デモの店は送らず記録もしない、古い記録は消える
 */
final class MailRecordsTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** 次に送る時に投げる例外（null なら送れる） */
    public static ?Throwable $next = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::$next = null;
        Mail::extend('flaky', static fn (): TransportInterface => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): SentMessage
            {
                $error = MailRecordsTest::$next;

                if ($error !== null) {
                    throw $error;
                }

                return new SentMessage($message, $envelope ?? SymfonyEnvelope::create($message));
            }

            public function __toString(): string
            {
                return 'flaky://';
            }
        });
        config(['mail.mailers.flaky' => ['transport' => 'flaky']]);
        $this->tenant('mra');
        $this->tenant('mrb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_sent_mail_is_recorded_encrypted_with_its_order_and_trace(): void
    {
        $order = strtolower((string) Str::ulid());

        $this->inside('mra', function () use ($order): void {
            $trace = TraceId::start();
            Mail::to('Hanako@Example.com')->send((new ShopTestMail('店 mra', '2026-11-11 10:00'))->forTransaction($order));

            $record = MailRecord::query()->sole();
            $this->assertSame('core.test', $record->kind);
            $this->assertSame('sent', $record->status);
            $this->assertNotNull($record->sent_at);
            $this->assertSame('[店 mra] テストメール', $record->subject);
            $this->assertSame(['addresses' => ['Hanako@Example.com']], $record->recipients);
            // お客さんの台帳と同じブラインドインデックス（大文字・小文字をそろえて）
            $this->assertSame(BlindIndex::of('email', 'hanako@example.com'), $record->recipient_index);
            $this->assertSame($order, $record->transaction_id);
            $this->assertSame($trace, $record->trace_id);

            // DB には宛先も件名も平文で置かない
            $raw = (string) json_encode(DB::table('mail_records')->get()->all(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('example.com', strtolower($raw));
            $this->assertStringNotContainsString('テストメール', $raw);
        });

        // ほかの店の DB には出ない
        $this->inside('mrb', fn () => $this->assertSame(0, MailRecord::query()->count()));
    }

    public function test_a_failed_send_keeps_the_reason_and_codes_but_no_text_even_when_the_transaction_rolls_back(): void
    {
        $this->inside('mra', function (): void {
            self::$next = new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <nobody@example.com>: Recipient address rejected: User unknown".', 550);

            // 注文の知らせのように、送った印の行のロックの中（トランザクション）で送って落ちる
            try {
                DB::transaction(static function (): void {
                    Mail::mailer('flaky')->to('nobody@example.com')->send(new ShopTestMail('店 mra', 'now'));
                });
                $this->fail('送れなかった例外は呼んだ所へそのまま投げ直す');
            } catch (UnexpectedResponseException) {
            }

            $record = MailRecord::query()->sole();
            $this->assertSame('failed', $record->status);
            $this->assertSame('rejected', $record->reason);
            $this->assertSame(550, $record->smtp_code);
            $this->assertSame('5.1.1', $record->smtp_status);
            $this->assertNull($record->sent_at);

            // 例外の文・応答の文・宛先は残さない
            $raw = (string) json_encode(DB::table('mail_records')->get()->all(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Recipient address rejected', $raw);
            $this->assertStringNotContainsString('nobody', $raw);
        });
    }

    public function test_the_reason_words_come_from_codes_and_known_transport_failures(): void
    {
        $this->assertSame(['auth', 535, '5.7.8'], MailRecords::reason(new TransportException('Failed to authenticate on SMTP server with username "secret-user" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed".".', 535)));
        $this->assertSame(['temporary', 451, '4.7.1'], MailRecords::reason(new UnexpectedResponseException('Expected response code "250" but got code "451", with message "451 4.7.1 Try again later".', 451)));
        $this->assertSame(['connection', null, null], MailRecords::reason(new TransportException('Connection could not be established with host "smtp.example.com:587": stream_socket_client(): Unable to connect')));
        $this->assertSame(['timeout', null, null], MailRecords::reason(new TransportException('Connection to "smtp.example.com:587" timed out.')));
        // 送る口でない例外は「そのほか」
        $this->assertSame(['error', null, null], MailRecords::reason(new RuntimeException('550 5.1.1 rejected')));
    }

    public function test_a_retry_of_the_same_queue_job_updates_one_record(): void
    {
        $this->inside('mra', function (): void {
            $job = (string) Str::uuid();

            // 1 回目: 一時的に受け取れない
            MailRecords::jobStarted($job);
            self::$next = new UnexpectedResponseException('Expected response code "250" but got code "421", with message "421 4.3.2 busy".', 421);

            try {
                Mail::mailer('flaky')->to('a@example.com')->send(new ShopTestMail('店 mra', 'now'));
            } catch (UnexpectedResponseException) {
            }

            MailRecords::jobEnded();
            $this->assertSame('failed', MailRecord::query()->sole()->status);

            // やり直し: 同じ仕事・同じ宛先・同じ種類は同じ行を直す
            MailRecords::jobStarted($job);
            self::$next = null;
            Mail::mailer('flaky')->to('a@example.com')->send(new ShopTestMail('店 mra', 'now'));
            MailRecords::jobEnded();

            $record = MailRecord::query()->sole();
            $this->assertSame('sent', $record->status);
            $this->assertSame(2, $record->attempts);
            $this->assertNull($record->reason);
            $this->assertNull($record->smtp_code);

            // 別の仕事は別の行
            MailRecords::jobStarted((string) Str::uuid());
            Mail::mailer('flaky')->to('a@example.com')->send(new ShopTestMail('店 mra', 'now'));
            MailRecords::jobEnded();
            $this->assertSame(2, MailRecord::query()->count());
        });
    }

    public function test_bulk_mails_keep_only_the_failures(): void
    {
        $this->inside('mra', function (): void {
            Mail::mailer('flaky')->to('a@example.com')->send(new BulkProbeMail);
            $this->assertSame(0, MailRecord::query()->count());

            self::$next = new UnexpectedResponseException('Expected response code "250" but got code "552", with message "552 5.2.2 mailbox full".', 552);

            try {
                Mail::mailer('flaky')->to('b@example.com')->send(new BulkProbeMail);
            } catch (UnexpectedResponseException) {
            }

            $record = MailRecord::query()->sole();
            $this->assertSame(['probe.bulk', 'failed', 'rejected', '5.2.2'], [$record->kind, $record->status, $record->reason, $record->smtp_status]);
            $this->assertSame(BlindIndex::of('email', 'b@example.com'), $record->recipient_index);
        });
    }

    public function test_mails_outside_shops_and_untagged_mails_are_not_recorded(): void
    {
        // 店の文脈の外（運営へのメールなど）
        Mail::to('owner@example.com')->send(new ShopTestMail('管理者', 'now'));
        // 店の文脈の中でも、札の無いメール
        $this->inside('mra', function (): void {
            Mail::raw('札の無いメール', static fn ($m) => $m->to('a@example.com')->subject('札なし'));
            $this->assertSame(0, MailRecord::query()->count());
        });
    }

    public function test_a_demo_shop_keeps_the_mail_in_the_visitor_inbox_and_records_nothing(): void
    {
        $this->tenant('demomail');
        $this->artisan('shop:demo', ['id' => 'demomail', '--on' => true])->assertSuccessful();

        $this->inside('demomail', function (): void {
            Mail::mailer('flaky')->to('owner@example.com')->send(new ShopTestMail('デモ', 'now'));
            $this->assertSame(0, MailRecord::query()->count());
            // 来訪者でない宛先は受け箱にも置かない（前からの決まりのまま）
            $this->assertSame(0, DemoMail::query()->count());
        });
    }

    public function test_old_records_are_pruned_per_shop(): void
    {
        $this->inside('mra', function (): void {
            Mail::to('a@example.com')->send(new ShopTestMail('店 mra', 'now'));
            Mail::to('b@example.com')->send(new ShopTestMail('店 mra', 'now'));
            DB::table('mail_records')->where('id', MailRecord::query()->orderBy('id')->value('id'))->update(['created_at' => now()->subDays(MailRecords::KEEP_DAYS + 1)]);
        });

        $this->artisan('mail-records:prune')->expectsOutputToContain('1 件')->assertSuccessful();
        $this->inside('mra', fn () => $this->assertSame(1, MailRecord::query()->count()));
    }
}

/**
 * 一斉の配信の見本（送れなかった分だけ残る）
 */
final class BulkProbeMail extends Mailable
{
    use RecordsMail;

    public function envelope(): Envelope
    {
        return new Envelope(subject: '一斉の配信');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>一斉</p>');
    }

    public function recordKind(): string
    {
        return 'probe.bulk';
    }

    public function recordBulk(): bool
    {
        return true;
    }
}
