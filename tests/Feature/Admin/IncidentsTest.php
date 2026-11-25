<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Incident;
use App\Admin\Services\Incidents;
use App\Core\Payment\PaymentUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 壊れたことの記録（ADR 0043）: 500 は中央の incidents に「どこで・何が・何回」だけで 1 行（同じ所は回数だけ増える）、
 * 店の文脈の中でも中央に書かれ店の ID が付く、4xx と見せてよい文の例外は書かない、メッセージ（個人情報が混ざりうる）はどの列にも無い、
 * 中央の DB が止まっても・表が無くても元の応答は変わらない、裏の仕事と定期の処理の失敗も書かれる
 */
final class IncidentsTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** 例外のメッセージに入れる個人情報（どこにも残らないこと） */
    private const string SECRET = '山田 太郎 yamada@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        // 500 を起こす経路（公開の印つき。この場面の中だけにある）。場所の行を見分けるため、1 行に 1 つずつ置く
        Route::middleware('public')->get('/_t0096/boom', static fn () => throw new RuntimeException(self::SECRET));
        Route::middleware('public')->get('/_t0096/other', static fn () => throw new RuntimeException(self::SECRET));
        // vendor の中で落ちる（日付の読み取りの誤り）。場所はそれを呼んだここの行
        Route::middleware('public')->get('/_t0096/vendor', static fn () => CarbonImmutable::parse('いつでもない日 '.self::SECRET));
        Route::middleware('public')->get('/_t0096/unavailable', static fn () => throw new PaymentUnavailableException('今は支払いに進めません'));
        Route::middleware('public')->get('/_t0096/forbidden', static fn () => abort(403));
        Route::middleware('public')->get('/_t0096/invalid', static fn (Request $request) => $request->validate(['name' => 'required']));
        // 店の文脈の中（店を特定する群。セッションを持たない）
        Route::middleware(['storefront-media', 'public'])->get('/_t0096/shop-boom', static fn () => throw new RuntimeException(self::SECRET));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_500_writes_one_row_and_the_same_place_only_adds_to_the_count(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00', 'UTC'));
        $this->get('http://localhost/_t0096/boom')->assertStatus(500);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:05', 'UTC'));
        $this->get('http://localhost/_t0096/boom')->assertStatus(500);

        $row = Incident::query()->sole();
        $this->assertSame(Incidents::HTTP_ERROR, $row->kind);
        $this->assertSame(RuntimeException::class, $row->exception);
        // 場所はアプリの中のいちばん深いフレーム（この経路を置いた行）
        $this->assertSame('tests/Feature/Admin/IncidentsTest.php:'.$this->lineOf("'/_t0096/boom'"), $row->location);
        $this->assertNull($row->tenant_id);
        $this->assertSame(2, $row->count);
        $this->assertTrue($row->first_at->equalTo(CarbonImmutable::parse('2026-10-30 12:00', 'UTC')));
        $this->assertTrue($row->last_at->equalTo(CarbonImmutable::parse('2026-10-30 12:05', 'UTC')));
        $this->assertNull($row->notified_at);

        // 違う場所は別の行。vendor の中で落ちたものは、それを呼んだアプリの行
        $this->get('http://localhost/_t0096/other')->assertStatus(500);
        $this->get('http://localhost/_t0096/vendor')->assertStatus(500);
        $this->assertSame([
            'tests/Feature/Admin/IncidentsTest.php:'.$this->lineOf("'/_t0096/boom'") => 2,
            'tests/Feature/Admin/IncidentsTest.php:'.$this->lineOf("'/_t0096/other'") => 1,
            'tests/Feature/Admin/IncidentsTest.php:'.$this->lineOf("'/_t0096/vendor'") => 1,
        ], Incident::query()->orderBy('location')->pluck('count', 'location')->all());
    }

    public function test_inside_a_shop_it_is_written_to_the_central_table_with_the_shop_id(): void
    {
        $this->tenant('incx');

        $this->get('http://incx.localhost/_t0096/shop-boom')->assertStatus(500);
        tenancy()->end();

        $row = Incident::query()->sole();
        $this->assertSame('incx', $row->tenant_id);
        $this->assertSame(Incidents::HTTP_ERROR, $row->kind);
        // 店の DB には表が無い（書くのはいつも中央）
        $this->assertFalse($this->inside('incx', static fn (): bool => DB::connection('tenant')->getSchemaBuilder()->hasTable('incidents')));
    }

    public function test_4xx_and_errors_that_may_be_shown_are_not_written(): void
    {
        $this->get('http://localhost/_t0096/nothing-here')->assertNotFound();
        $this->getJson('http://localhost/_t0096/invalid')->assertUnprocessable();
        $this->get('http://localhost/_t0096/forbidden')->assertForbidden();
        // 見せてよい文の例外は 5xx（503）でも壊れていない
        $this->getJson('http://localhost/_t0096/unavailable')->assertStatus(503);

        $this->assertSame(0, Incident::query()->count());
    }

    public function test_no_column_holds_the_message_and_the_columns_are_fixed(): void
    {
        $this->tenant('incy');
        $this->get('http://localhost/_t0096/boom')->assertStatus(500);
        $this->get('http://localhost/_t0096/vendor')->assertStatus(500);
        $this->get('http://incy.localhost/_t0096/shop-boom')->assertStatus(500);
        tenancy()->end();

        $rows = DB::table('incidents')->get()->all();
        $this->assertCount(3, $rows);
        $all = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($all);

        foreach (['山田', 'yamada@example.com', 'いつでもない日', '_t0096'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }

        // 列は決まったものだけ（メッセージ・スタック・URL・利用者の列を足していない）。last_trace_id は処理の ID（ULID だけ。ADR 0087）
        $this->assertSame(
            ['fingerprint', 'kind', 'exception', 'location', 'tenant_id', 'count', 'first_at', 'last_at', 'notified_at', 'notified_count', 'last_trace_id'],
            array_keys((array) $rows[0]),
        );
    }

    public function test_when_the_central_database_is_down_or_the_table_is_missing_the_response_does_not_change(): void
    {
        $expected = $this->getJson('http://localhost/_t0096/boom')->assertStatus(500)->json();
        $this->assertIsArray($expected);
        $this->assertSame(1, Incident::query()->sole()->count);

        // 表が無い
        DB::statement('ALTER TABLE incidents RENAME TO incidents_away');

        try {
            $withoutTable = $this->getJson('http://localhost/_t0096/boom')->assertStatus(500)->json();
            $this->assertSame($expected, $withoutTable);
        } finally {
            DB::statement('ALTER TABLE incidents_away RENAME TO incidents');
        }

        // 中央の DB に届かない
        $connection = (string) config('tenancy.database.central_connection');
        $port = config("database.connections.{$connection}.port");
        config(["database.connections.{$connection}.port" => 1]);
        DB::purge($connection);

        try {
            $withoutDatabase = $this->getJson('http://localhost/_t0096/boom')->assertStatus(500)->json();
            $this->assertSame($expected, $withoutDatabase);
        } finally {
            config(["database.connections.{$connection}.port" => $port]);
            DB::purge($connection);
        }

        // 止まっていた間の分は数えていない（あとから壊れた記録が混ざらない）
        $this->assertSame(1, Incident::query()->sole()->count);
    }

    public function test_a_report_inside_a_central_transaction_is_written_on_its_own_connection(): void
    {
        // 中央の接続が読むだけのトランザクションの途中でも、相手を壊さずに書く（巻き戻されても残る）
        Route::middleware('public')->get('/_t0096/in-transaction', static function () {
            DB::transaction(static function (): void {
                DB::statement('SET TRANSACTION READ ONLY');
                report(new RuntimeException(self::SECRET));
                // 相手のトランザクションは壊れていない
                DB::select('SELECT 1');
            });

            return response('ok');
        });

        $this->get('http://localhost/_t0096/in-transaction')->assertOk();
        $this->assertSame(1, Incident::query()->sole()->count);
    }

    public function test_a_failed_job_and_a_failed_scheduled_task_are_written(): void
    {
        // 裏の仕事（試し直しを使い切った）。場所は仕事の中の行
        try {
            IncidentsTestFailingJob::dispatch();
            $this->fail('仕事が落ちなかった');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $job = Incident::query()->where('kind', Incidents::JOB_FAILED)->sole();
        $this->assertSame(RuntimeException::class, $job->exception);
        $this->assertSame('tests/Feature/Admin/IncidentsTest.php:'.$this->lineOf('throw new RuntimeException(\'仕事の中'), $job->location);

        // 定期の処理（同じプロセスで動く処理が落ちた）。場所は落ちた行。schedule:run と同じく、落ちたら ScheduledTaskFailed を出す
        // （schedule:run そのものは流さない。ほかの定期の処理まで別のプロセスで動き出すため）
        $callback = app(Schedule::class)->call(static fn () => throw new RuntimeException(self::SECRET));

        try {
            $callback->run(app());
            $this->fail('定期の処理が落ちなかった');
        } catch (RuntimeException $e) {
            event(new ScheduledTaskFailed($callback, $e));
        }

        $task = Incident::query()->where('kind', Incidents::COMMAND_FAILED)->sole();
        $this->assertSame('tests/Feature/Admin/IncidentsTest.php:'.$this->lineOf('$callback = app(Schedule::class)->call('), $task->location);

        // 定期のコマンド（別のプロセスで動き、終わりの番号で失敗が届く）。場所はコマンドの名前と引数
        $command = app(Schedule::class)->command('operations:collect --verify');
        event(new ScheduledTaskFailed($command, new RuntimeException('Scheduled command failed with exit code [1].')));
        $this->assertSame(
            ['artisan operations:collect --verify'],
            Incident::query()->where('kind', Incidents::COMMAND_FAILED)->where('location', 'like', 'artisan%')->pluck('location')->all(),
        );
        // HTTP の外の報告（裏の仕事の処理役・定期の処理の中の report）は、ここでは数えない（上の 2 つで 1 回ずつ数える）
        report(new RuntimeException(self::SECRET));
        $this->assertSame(3, Incident::query()->count());
    }

    /**
     * このファイルの中で、$needle を含む行の番号
     */
    private function lineOf(string $needle): int
    {
        $lines = file(__FILE__);
        $this->assertIsArray($lines);

        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle) && ! str_contains($line, 'lineOf(')) {
                return $index + 1;
            }
        }

        $this->fail("行が見つからない: {$needle}");
    }
}

/**
 * 落ちる裏の仕事（試し直しなし）
 */
final class IncidentsTestFailingJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('仕事の中 '.'山田 太郎 yamada@example.com');
    }
}
