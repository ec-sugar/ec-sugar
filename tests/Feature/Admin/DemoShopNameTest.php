<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Demo\DemoShops;
use App\Admin\Models\AuditEvent;
use App\Admin\Models\ShopDigest;
use App\Admin\Services\ShopNames;
use App\Core\Event\Models\DomainEvent;
use App\Core\Sample\Demo\DemoShop;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeMedia;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * デモのスクリプト（demo:shop）と店の名前。2026-11-09 の本番で、shop:create で付けた「菓匠 しろつめ」が
 * --name を付けずに流した demo:shop で候補のはじめ「和菓子 こよみ堂」に上書きされた。
 * --name が無ければ中央の店の今の名前のまま（毎晩の戻しも）、名前が無い店だけ候補、--name を付けたときだけ変え、変えるときは店の名前の道（記録と監査ログ）を通る
 */
final class DemoShopNameTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private string $photos = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        FakeMedia::fake();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        $this->photos = sys_get_temp_dir().'/demo-photos-'.bin2hex(random_bytes(4));
        mkdir($this->photos);
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        @rmdir($this->photos);

        parent::tearDown();
    }

    public function test_without_a_name_the_script_keeps_the_name_given_at_shop_create_even_after_the_nightly_reset(): void
    {
        $this->demoShop('demowagashi', '菓匠 しろつめ');

        $this->artisan('demo:shop', ['kind' => 'wagashi', '--tenant' => 'demowagashi', '--photos' => $this->photos])
            ->expectsOutputToContain('デモ「菓匠 しろつめ」を入れました')->assertSuccessful();
        $this->assertNameIs('demowagashi', '菓匠 しろつめ');
        $this->assertSame(0, $this->renames('demowagashi'));

        // 2 回目も同じ（何も変えない）
        $this->artisan('demo:shop', ['kind' => 'wagashi', '--tenant' => 'demowagashi', '--photos' => $this->photos])
            ->expectsOutputToContain('何も変えていません')->assertSuccessful();
        $this->assertNameIs('demowagashi', '菓匠 しろつめ');

        // 毎晩の戻しも覚えた名前（＝今の名前）で作り直す。
        // 戻しは店の DB を作り直すので、控えた記録の連鎖の先頭を忘れる（忘れないと、作り直した後の検証が
        // 「前に確かめた時より前の状態に戻っている」と毎晩知らせる）
        $this->artisan('operations:collect', ['--tenant' => 'demowagashi', '--verify' => true])->assertSuccessful();
        $before = ShopDigest::query()->findOrFail('demowagashi');
        $this->assertNotNull($before->chain_head_hash);
        $this->artisan('demo:reset', ['--tenant' => ['demowagashi']])->assertSuccessful();
        $this->assertNameIs('demowagashi', '菓匠 しろつめ');
        $this->assertSame(0, $this->renames('demowagashi'));
        $this->assertNull(ShopDigest::query()->findOrFail('demowagashi')->chain_head_hash);
        $this->artisan('operations:collect', ['--tenant' => 'demowagashi', '--verify' => true])->assertSuccessful();
        $after = ShopDigest::query()->findOrFail('demowagashi');
        $this->assertFalse($after->chain_rolled_back);
        $this->assertNotSame($before->chain_head_hash, $after->chain_head_hash);
    }

    public function test_a_shop_without_a_name_gets_the_first_candidate_through_the_shop_name_path(): void
    {
        $this->demoShop('democoffee', '');

        $this->artisan('demo:shop', ['kind' => 'coffee', '--tenant' => 'democoffee', '--photos' => $this->photos])->assertSuccessful();
        $first = DemoShops::definition('coffee')->names()[0];
        $this->assertNameIs('democoffee', $first);
        $this->assertSame(1, $this->renames('democoffee'));

        // 2 回目は名前がもうあるので、そのまま
        $this->artisan('demo:shop', ['kind' => 'coffee', '--tenant' => 'democoffee', '--photos' => $this->photos])
            ->expectsOutputToContain('何も変えていません')->assertSuccessful();
        $this->assertNameIs('democoffee', $first);
        $this->assertSame(1, $this->renames('democoffee'));
    }

    public function test_an_explicit_name_renames_the_shop_once_and_a_bad_one_is_refused(): void
    {
        $this->demoShop('demosake', '仮の名前');

        // 形の違う名前は、何も入れずに断る
        $this->artisan('demo:shop', ['kind' => 'sake', '--tenant' => 'demosake', '--name' => "霞堤\n酒造", '--photos' => $this->photos])
            ->expectsOutputToContain('改行や制御文字')->assertExitCode(2);
        $this->assertSame('仮の名前', Tenant::query()->findOrFail('demosake')->name);
        $this->assertNull(Tenant::query()->findOrFail('demosake')->getAttribute(DemoShop::BUILD));

        $this->artisan('demo:shop', ['kind' => 'sake', '--tenant' => 'demosake', '--name' => '　霞堤酒造　', '--photos' => $this->photos])
            ->expectsOutputToContain('デモ「霞堤酒造」を入れました')->assertSuccessful();
        $this->assertNameIs('demosake', '霞堤酒造');
        $this->assertSame(1, $this->renames('demosake'));

        // 同じ名前でもう一度流しても、記録は増えない
        $this->artisan('demo:shop', ['kind' => 'sake', '--tenant' => 'demosake', '--name' => '霞堤酒造', '--photos' => $this->photos])
            ->expectsOutputToContain('何も変えていません')->assertSuccessful();
        $this->assertSame(1, $this->renames('demosake'));

        // 入れ終えた店でも、--name を変えれば名前だけ変わる
        $this->artisan('demo:shop', ['kind' => 'sake', '--tenant' => 'demosake', '--name' => '霞堤酒造 本店', '--photos' => $this->photos])
            ->expectsOutputToContain('名前だけ「霞堤酒造 本店」に変えました')->assertSuccessful();
        $this->assertNameIs('demosake', '霞堤酒造 本店');
        $this->assertSame(2, $this->renames('demosake'));
    }

    public function test_the_demo_rename_path_refuses_a_shop_without_the_demo_mark(): void
    {
        $tenant = $this->tenant('demoplain', 'ふつうの店');

        try {
            $tenant->run(static fn (): bool => app(ShopNames::class)->renameDemo($tenant, '別の名前', 'cli:demo-shop'));
            $this->fail('デモの印の無い店の名前を変えた');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('デモの印の無い店', (string) $e->validator->errors()->first('name'));
        }

        $this->assertSame('ふつうの店', Tenant::query()->findOrFail('demoplain')->name);
        $this->assertSame(0, $this->renames('demoplain'));
    }

    /**
     * shop:create で名前を付けた店（名前は空でもよい）に、デモの印を付ける
     */
    private function demoShop(string $id, string $name): void
    {
        $this->tenant($id, $name);
        $this->artisan('shop:demo', ['id' => $id, '--on' => true])->assertSuccessful();
    }

    /**
     * 中央の店の名前と、毎晩の戻しに使う覚えの名前がそろっている
     */
    private function assertNameIs(string $id, string $name): void
    {
        $tenant = Tenant::query()->findOrFail($id);
        $this->assertSame($name, $tenant->name);
        $this->assertSame($name, DemoShops::buildOf($tenant)['name'] ?? null);
    }

    /**
     * 名前を変えた数（中央の監査ログと店の記録の両方。どちらにも名前そのものは入れない）。DB を読むので呼ぶたびに値が変わりうる
     *
     * @phpstan-impure
     */
    private function renames(string $id): int
    {
        $audits = AuditEvent::query()->where('type', 'tenant.renamed')->where('tenant_id', $id)->get();

        foreach ($audits as $audit) {
            $this->assertSame(['by' => 'cli:demo-shop'], $audit->data);
            $this->assertNull($audit->operator_id);
        }

        $events = $this->inside($id, static fn (): int => DomainEvent::query()->where('type', 'shop.renamed')->where('actor', 'cli:demo-shop')->count());
        $this->assertSame($audits->count(), $events, '監査ログと店の記録の数が合わない');

        return $events;
    }
}
