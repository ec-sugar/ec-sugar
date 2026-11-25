<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Console\AlertOperationsCommand;
use App\Admin\Jobs\QueueHeartbeat;
use App\Admin\Mail\OperationsAlertMail;
use App\Admin\Models\Operator;
use App\Admin\Services\OperationsOverview;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 止まった時の知らせ: キューの脈（5 分ごとに必ず出し、最も古い片付いていない脈が 10 分を過ぎたら要対応。
 * 後の脈が片付けば消えるので、失われた脈の誤った知らせは残らない）と、
 * 知らせの係の回どうしの鍵（定期の処理とサーバーの見張り〔ec-sugar watch〕の両方から走っても 1 通）
 */
final class QueueWatchTest extends TestCase
{
    use DatabaseTruncation;

    public function test_the_mark_keeps_the_oldest_unhandled_heartbeat_and_a_later_one_clears_it(): void
    {
        Queue::fake();
        $start = CarbonImmutable::parse('2026-11-04 12:00', 'Asia/Tokyo');

        // 止まっている間も 5 分ごとに出す。印は最も古い片付いていない脈の時刻のまま
        QueueHeartbeat::send($start);
        QueueHeartbeat::send($start->addMinutes(5));
        QueueHeartbeat::send($start->addMinutes(10));
        Queue::assertPushed(QueueHeartbeat::class, 3);
        $this->assertSame($start->getTimestamp(), $this->pending());

        // 最初の脈が失われても（更新の切り替えの最中など）、後の脈が片付けば消える
        (new QueueHeartbeat($start->addMinutes(10)->getTimestamp()))->handle();
        $this->assertNull($this->pending());

        // 印を消した後に、前に出した脈が遅れて片付いても、新しい印は消さない
        $later = $start->addMinutes(15);
        QueueHeartbeat::send($later);
        (new QueueHeartbeat($start->addMinutes(5)->getTimestamp()))->handle();
        $this->assertTrue($later->equalTo(QueueHeartbeat::pendingSince() ?? $start), '前の脈が新しい印を消した');
        (new QueueHeartbeat($later->getTimestamp()))->handle();
        $this->assertNull($this->pending());
    }

    public function test_the_mark_is_read_the_same_when_the_cache_gives_it_back_as_text(): void
    {
        // Redis のキャッシュは数を文字で返す（ステージングで見つけた: 整数だけを受けて、止まりを見逃し、片付いても印が消えなかった）
        $start = CarbonImmutable::parse('2026-11-04 12:00', 'Asia/Tokyo');
        Cache::forever(QueueHeartbeat::SENT, (string) $start->getTimestamp());

        $this->assertSame([['key' => 'queue_stopped', 'level' => 'urgent', 'count' => 15, 'tenant' => null]], $this->stopped(app(OperationsOverview::class)->present($start->addMinutes(15))));

        (new QueueHeartbeat($start->getTimestamp()))->handle();
        $this->assertNull($this->pending());

        // 数でない値は印として読まない
        Cache::forever(QueueHeartbeat::SENT, '12:00');
        $this->assertNull(QueueHeartbeat::pendingSince());
    }

    public function test_a_running_queue_clears_the_heartbeat_at_once(): void
    {
        // テストのキューはその場で片付ける（sync）。動いているキューでは印が残らない
        QueueHeartbeat::send();

        $this->assertNull($this->pending());
    }

    public function test_the_overview_says_the_queue_stopped_after_ten_minutes_even_with_few_jobs_waiting(): void
    {
        Queue::fake();
        $start = CarbonImmutable::parse('2026-11-04 12:00', 'Asia/Tokyo');
        QueueHeartbeat::send($start);

        // 10 分ちょうどまでは止まりとみなさない
        $health = app(OperationsOverview::class)->present($start->addMinutes(OperationsOverview::QUEUE_STALL_MINUTES));
        $this->assertSame([], $this->stopped($health));
        $this->assertSame('ok', $health['health']['queue']['status']);

        // 過ぎたら要対応（待ちは 1 件だけでも）。数は経った分
        $health = app(OperationsOverview::class)->present($start->addMinutes(OperationsOverview::QUEUE_STALL_MINUTES + 1));
        $this->assertSame([['key' => 'queue_stopped', 'level' => 'urgent', 'count' => 11, 'tenant' => null]], $this->stopped($health));
        $this->assertSame('bad', $health['health']['queue']['status']);
        $this->assertSame(1, $health['health']['queue']['waiting']);

        // 片付いたら消える
        (new QueueHeartbeat($start->getTimestamp()))->handle();
        $this->assertSame([], $this->stopped(app(OperationsOverview::class)->present($start->addMinutes(30))));
    }

    public function test_a_stopped_queue_is_mailed_once_and_two_runs_at_the_same_time_send_one_mail(): void
    {
        Mail::fake();
        Queue::fake();
        $this->owner();
        $start = CarbonImmutable::now();
        QueueHeartbeat::send($start->subMinutes(15));

        // ほかの回（定期の処理かサーバーの見張り）が鍵を持っている間は送らない
        $other = Cache::lock(AlertOperationsCommand::LOCK, AlertOperationsCommand::LOCK_SECONDS);
        $this->assertTrue($other->get());
        $this->artisan('operations:alert')->expectsOutput('ほかの回が通知しているところです')->assertSuccessful();
        Mail::assertNothingSent();
        $other->release();

        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSent(OperationsAlertMail::class, static fn (OperationsAlertMail $mail): bool => $mail->issues === ['バックグラウンド処理（キュー）が 15 分進んでいません（注文の通知のメールなどが送られていません）']);

        // 送った後は鍵を返している（次の回が送れる）。出ている間は 1 回だけ
        $this->assertTrue(Cache::lock(AlertOperationsCommand::LOCK, 1)->get());
        Cache::lock(AlertOperationsCommand::LOCK)->forceRelease();
        $this->artisan('operations:alert')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_the_heartbeat_runs_every_five_minutes_and_the_alert_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events());
        $find = static fn (string $name): ?Event => $events->first(static fn (Event $event): bool => $event->description === $name || str_ends_with((string) $event->command, "'artisan' {$name}"));

        $this->assertSame('*/5 * * * *', $find('operations:queue-heartbeat')?->expression);
        $this->assertSame('* * * * *', $find('operations:alert')?->expression);
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return list<array<string, mixed>>
     */
    private function stopped(array $overview): array
    {
        return array_values(array_filter($overview['issues'], static fn (array $issue): bool => $issue['key'] === 'queue_stopped'));
    }

    /** 片付いていない脈を出した時刻（UNIX 秒）。置き場から毎回読む */
    private function pending(): mixed
    {
        return Cache::get(QueueHeartbeat::SENT);
    }

    private function owner(): void
    {
        $operator = Operator::create(['name' => 'オーナー', 'email' => 'owner@example.com']);
        $operator->forceFill(['role' => 'owner'])->save();
    }
}
