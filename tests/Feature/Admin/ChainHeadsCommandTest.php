<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\ShopDigest;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 記録の連鎖の先頭をサーバーの外へ送る形で出す・外から取った先頭と今の記録を照らす（ADR 0110 の 5。R7 の最後）。
 * 出すのは中央に控えた（確かめ済みの）先頭だけ。照らすと、末尾の切り落としと書き換えに気づける。
 */
final class ChainHeadsCommandTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_it_prints_the_remembered_heads_in_the_shape_the_host_accepts(): void
    {
        $this->tenant('cha');
        $this->tenant('chb');
        $this->tenant('chc');
        $this->events('cha', 3);
        $this->events('chb', 1);
        // chc は記録が無い（守るものが無いので出さない）
        Artisan::call('operations:collect', ['--verify' => true]);

        $lines = $this->heads();
        $this->assertCount(2, $lines);

        foreach ($lines as $line) {
            // ホストの ec_offsite_heads_valid と同じ形（店の ID・番号・64 桁の hash・時刻）
            $this->assertMatchesRegularExpression('/\A[a-z0-9][a-z0-9-]{0,30}[a-z0-9]\t[0-9]{1,18}\t[0-9a-f]{64}\t[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $line);
        }

        [$id, $sequence, $hash] = explode("\t", $lines[0]);
        $this->assertSame('cha', $id);
        $digest = ShopDigest::query()->findOrFail('cha');
        $this->assertSame([(string) $digest->chain_head_sequence, $digest->chain_head_hash], [$sequence, $hash]);
        $this->assertSame($hash, $this->inside('cha', static fn () => DomainEvent::query()->orderByDesc('sequence')->value('hash')));
    }

    public function test_a_shop_id_the_host_would_refuse_is_left_out_without_breaking_the_others(): void
    {
        $this->tenant('cha');
        $this->tenant('odd_id');
        $this->events('cha', 1);
        $this->events('odd_id', 1);
        Artisan::call('operations:collect', ['--verify' => true]);

        $lines = $this->heads();
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith("cha\t", $lines[0]);
    }

    public function test_checking_the_heads_finds_a_cut_tail_and_a_rewritten_event(): void
    {
        $this->tenant('cha');
        $this->tenant('chb');
        $this->events('cha', 3);
        $this->events('chb', 2);
        Artisan::call('operations:collect', ['--verify' => true]);
        $file = $this->saveHeads();

        // 今の記録と照らす: 全部合う
        $this->assertSame(0, Artisan::call('operations:check-chain-heads', ['--file' => $file]));
        $this->assertMatchesRegularExpression('/^ok cha: .*\n^ok chb: /mu', Artisan::output());

        // 記録が増えるのは問題ない（控えた番号の記録が同じ hash で残っている）
        $this->events('cha', 2);
        $this->artisan('operations:check-chain-heads', ['--file' => $file])->assertExitCode(0);

        // cha の末尾を切り落とす（表の持ち主ならトリガーを外せる）。chb の控えた記録を書き換える
        $sequence = (int) explode("\t", $this->heads()[0])[1];
        $this->inside('cha', static function () use ($sequence): void {
            DB::statement('ALTER TABLE domain_events DISABLE TRIGGER USER');
            DB::table('domain_events')->where('sequence', '>=', $sequence - 2)->delete();
            DB::statement('ALTER TABLE domain_events ENABLE TRIGGER USER');
        });
        $this->inside('chb', static function (): void {
            DB::statement('ALTER TABLE domain_events DISABLE TRIGGER USER');
            DB::table('domain_events')->where('sequence', DomainEvent::query()->max('sequence'))->update(['hash' => str_repeat('0', 64)]);
            DB::statement('ALTER TABLE domain_events ENABLE TRIGGER USER');
        });

        $this->assertSame(1, Artisan::call('operations:check-chain-heads', ['--file' => $file]));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/^NG cha: .*切り落とされた恐れ/mu', $output);
        $this->assertMatchesRegularExpression('/^NG chb: .*書き換えられた恐れ/mu', $output);
    }

    public function test_a_missing_shop_is_reported_and_a_malformed_file_is_refused(): void
    {
        $missing = $this->file("nai\t3\t".str_repeat('a', 64)."\t2026-11-24T03:00:00Z\n");
        $this->artisan('operations:check-chain-heads', ['--file' => $missing])->expectsOutputToContain('NG nai: 店がありません')->assertExitCode(1);

        foreach ([
            '列が足りない' => "cha\t3\t".str_repeat('a', 64)."\n",
            'hash が短い' => "cha\t3\tabc\t2026-11-24T03:00:00Z\n",
            '店の ID に下線' => "c_a\t3\t".str_repeat('a', 64)."\t2026-11-24T03:00:00Z\n",
            '時刻の形が違う' => "cha\t3\t".str_repeat('a', 64)."\t20261124\n",
            '空' => "\n",
        ] as $why => $body) {
            $this->artisan('operations:check-chain-heads', ['--file' => $this->file($body)])->assertExitCode(2);
            $this->addToAssertionCount(1);
            unset($why);
        }

        $this->artisan('operations:check-chain-heads', ['--file' => '/nonexistent/heads.tsv'])->assertExitCode(2);
    }

    private function events(string $tenant, int $count): void
    {
        $this->inside($tenant, static function () use ($count): void {
            for ($i = 0; $i < $count; $i++) {
                DB::transaction(static function () use ($i): void {
                    $store = app(EventStore::class);
                    $store->lock();
                    $store->append('test.noted', 'test', strtolower((string) Str::ulid()), ['n' => (string) $i]);
                });
            }
        });
    }

    /**
     * @return list<string>
     */
    private function heads(): array
    {
        Artisan::call('operations:chain-heads');

        return array_values(array_filter(explode("\n", trim(Artisan::output())), static fn (string $line): bool => $line !== ''));
    }

    private function saveHeads(): string
    {
        return $this->file(implode("\n", $this->heads())."\n");
    }

    private function file(string $body): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'heads');
        file_put_contents($path, $body);
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));

        return $path;
    }
}
