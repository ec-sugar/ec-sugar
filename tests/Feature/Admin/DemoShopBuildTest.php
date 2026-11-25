<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Demo\DemoRestock;
use App\Admin\Demo\DemoShopBuilder;
use App\Admin\Demo\DemoShops;
use App\Admin\Models\AuditEvent;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Sample\Models\DemoStep;
use App\Core\Sample\Models\SampleMark;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Plugin\BookingDemoParts;
use Tests\Feature\Plugin\LotteryDemoParts;
use Tests\Feature\Plugin\TicketDemoParts;
use Tests\Support\AppContract;
use Tests\Support\FakeMedia;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * デモの店を作るスクリプトと毎晩の戻し（ADR 0086）。外へは出ない（写真は空のフォルダから。無い写真は無しで入れる）。
 * 4 つの扱いがどれも入ってショップの全部の画面が 500 にならない・流し直しても増えない・印の無い店には入れない・
 * 戻しはデモの店だけに効き、印の無い店（ID が demo で始まらない・印を外した）には名指ししても触れない
 */
final class DemoShopBuildTest extends TestCase
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

    /**
     * 扱い・テーマ・商品の数・定期便と予約とチケットと抽選販売の App が入っていない木（公開用の 1.0。ADR 0098）での商品の数
     * （予約の教室 2 つと公演のチケット 3 つは、その App の商品の扱いなので作らない）
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function kinds(): array
    {
        return [
            '酒蔵' => ['sake', 'kinari', 14, 14],
            'コーヒー焙煎所' => ['coffee', 'botanica', 13, 11],
            '和菓子店' => ['wagashi', 'default', 12, 12],
            'ライブハウス' => ['livehouse', 'fashion', 10, 7],
        ];
    }

    /**
     * コーヒー焙煎所の商品の数（予約の App が入っていない木〔公開用の 1.0〕では、予約の教室 2 つを作らない）
     */
    private static function coffeeListings(): int
    {
        return self::kinds()['コーヒー焙煎所'][AppContract::absentUnpublished() === [] ? 2 : 3];
    }

    #[DataProvider('kinds')]
    public function test_each_kind_goes_in_opens_the_shop_and_no_page_breaks(string $kind, string $theme, int $listings, int $withoutUnpublished): void
    {
        if (AppContract::absentUnpublished() !== []) {
            // 4 つの App は揃って入るか揃って入らないか（公開用の木は 4 つとも無い）
            $this->assertSame(AppContract::UNPUBLISHED, AppContract::absentUnpublished());
            $listings = $withoutUnpublished;
        }

        $id = "demo{$kind}";
        $this->tenant($id);
        $this->artisan('shop:demo', ['id' => $id, '--on' => true])->assertSuccessful();
        $this->artisan('demo:shop', ['kind' => $kind, '--tenant' => $id, '--photos' => $this->photos])->assertSuccessful();

        $tenant = Tenant::query()->findOrFail($id);
        $this->assertSame($theme, $tenant->getAttribute('theme'));
        $this->assertSame($kind, $tenant->getAttribute(DemoShop::BUILD)['kind']);
        // 台本の記録（ADR 0089 の Outbox）は、どの受け手にも落ちずに最後まで流れる（毎分の見回りが拾い直す物も残らない）
        $this->artisan('events:relay', ['--tenant' => $id])->assertSuccessful();
        $ids = $this->inside($id, function () use ($kind, $listings): array {
            $this->assertTrue(DemoStep::query()->where('key', "built:{$kind}")->exists());
            $this->assertSame($listings, Listing::query()->count());
            // 中身はお店の物（サンプルとしては数えない）
            $this->assertSame(0, SampleMark::query()->active()->count());
            $this->assertRelayedToTheEnd();

            return Listing::query()->pluck('id')->all();
        });

        // 流し直しても増えない
        $this->artisan('demo:shop', ['kind' => $kind, '--tenant' => $id, '--photos' => $this->photos])
            ->expectsOutputToContain('もう入っています')->assertSuccessful();
        $this->assertSame($listings, $this->inside($id, static fn (): int => Listing::query()->count()));

        // ショップの画面がどれも 500 にならない（デモの帯が出る）
        $paths = ['/', '/products', '/pages/about', '/pages/photo-credits', '/news', '/legal/commerce', '/cart', '/account/login', '/demo/mails', '/robots.txt'];

        $fixedPaths = $paths;

        foreach ($ids as $listingId) {
            $paths[] = "/products/{$listingId}";
        }

        $fixed = count($fixedPaths);

        foreach ($paths as $index => $path) {
            $response = $this->get("http://{$id}.localhost{$path}");
            tenancy()->end();
            $this->assertLessThan(500, $response->status(), "{$path} が {$response->status()}");

            // 決まった画面はどれも開ける（商品は会員だけの物が「見つかりません」になる）
            if ($index < $fixed) {
                $this->assertSame(200, $response->status(), "{$path} が {$response->status()}");
            }

            if ($response->status() === 200 && $path !== '/robots.txt') {
                $response->assertSee('デモのお店です', false);
                $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
            }
        }
    }

    public function test_the_script_refuses_a_shop_without_the_demo_mark(): void
    {
        $this->tenant('demonomark');
        $this->tenant('plain');

        $this->artisan('demo:shop', ['kind' => 'coffee', '--tenant' => 'demonomark', '--photos' => $this->photos])
            ->expectsOutputToContain('デモの印の無い店には入れません')->assertFailed();
        // ID が demo で始まらない店には印も付けられない
        $this->artisan('shop:demo', ['id' => 'plain', '--on' => true])->assertFailed();
        $this->artisan('demo:shop', ['kind' => 'coffee', '--tenant' => 'plain', '--photos' => $this->photos])->assertFailed();

        foreach (['demonomark', 'plain'] as $id) {
            $this->assertSame(0, $this->inside($id, static fn (): int => Listing::query()->count()));
            $this->assertNull(Tenant::query()->findOrFail($id)->getAttribute(DemoShop::BUILD));
        }
    }

    public function test_the_nightly_reset_brings_back_only_demo_shops(): void
    {
        $this->tenant('demoreset');
        $this->artisan('shop:demo', ['id' => 'demoreset', '--on' => true])->assertSuccessful();
        $this->artisan('demo:shop', ['kind' => 'coffee', '--tenant' => 'demoreset', '--photos' => $this->photos])->assertSuccessful();

        // デモでない店 2 つ: ID が demo で始まらない店（覚えと印を無理に入れても）と、ID は demo で始まっても印を外した店
        foreach (['plainshop', 'demooff'] as $id) {
            $tenant = $this->tenant($id);
            $tenant->setAttribute(DemoShop::FLAG, $id === 'plainshop');
            $tenant->setAttribute(DemoShop::BUILD, ['kind' => 'coffee', 'name' => '名前', 'photos' => null]);
            $tenant->save();
            $this->inside($id, static fn () => DemoStep::query()->create(['key' => 'built:coffee']));
        }

        // 来訪者が作った物（どの店にも 1 人ずつ）
        foreach (['demoreset', 'plainshop', 'demooff'] as $id) {
            $this->inside($id, static fn () => app(CustomerAccounts::class)->forEmail('visitor@example.com', 'storefront'));
        }

        $before = $this->inside('demoreset', static fn (): array => Listing::query()->orderBy('id')->pluck('id')->all());

        // 作り直しの間ずっと、中央の行に作り直し中の印がある（DB を消した直後のマイグレーションから、台本の最後の商品まで）。
        // 店ごとに回る見回り（events:relay など）はこの印で店を飛ばすので、印の無い窓があってはいけない
        $marks = [];
        $mark = static function () use (&$marks): void {
            $marks[] = Tenant::query()->findOrFail('demoreset')->isRebuilding();
        };
        Event::listen(MigrationsStarted::class, $mark);
        Event::listen('eloquent.creating: '.Listing::class, $mark);

        $this->assertFalse(Tenant::query()->findOrFail('demoreset')->isRebuilding());
        $this->artisan('demo:reset')->assertSuccessful();
        $this->assertGreaterThan(1, count($marks));
        $this->assertNotContains(false, $marks);
        $this->assertFalse(Tenant::query()->findOrFail('demoreset')->isRebuilding());
        $this->artisan('events:relay', ['--tenant' => 'demoreset'])->expectsOutputToContain('作り直し中で飛ばした店 0')->assertSuccessful();

        // デモの店は作った時の状態（来訪者は消え、商品は作り直した）
        $this->inside('demoreset', function () use ($before): void {
            $this->assertSame(0, CustomerAccount::query()->count());
            $this->assertSame(self::coffeeListings(), Listing::query()->count());
            $this->assertNotSame($before, Listing::query()->orderBy('id')->pluck('id')->all());
            $this->assertTrue(DemoStep::built());
            // 受け手ごとの印も店の DB ごと初めからになり、作り直した台本の記録を最後まで流している（ADR 0089）
            $this->assertRelayedToTheEnd();
        });
        $this->assertTrue(AuditEvent::query()->where('type', 'tenant.demo_reset')->where('tenant_id', 'demoreset')->exists());

        // デモでない店は名指ししても断り、何も変えない
        $this->artisan('demo:reset', ['--tenant' => ['plainshop']])->expectsOutputToContain('デモの店ではない')->assertFailed();
        $this->artisan('demo:reset', ['--tenant' => ['demooff']])->expectsOutputToContain('デモの店ではない')->assertFailed();

        foreach (['plainshop', 'demooff'] as $id) {
            $this->inside($id, function (): void {
                $this->assertSame(1, CustomerAccount::query()->count());
                $this->assertTrue(DemoStep::built());
            });
        }
    }

    public function test_the_hourly_restock_brings_stock_and_booking_slots_back_only_in_demo_shops(): void
    {
        $this->demo('demostock', 'coffee');
        $def = DemoShops::definition('coffee');

        // 予約の App が入っていない木（公開用の 1.0。ADR 0098）では、在庫の戻しだけを確かめる。
        // 予約の枠を触る所は BookingDemoParts（許す一覧の Booking* で公開の木に入らない。使う所ごとに class_exists で囲む）
        $booking = $this->hasApp('booking');

        [$mugId, $slotId] = $this->inside('demostock', function () use ($def, $booking): array {
            // 払う前の注文が在庫を押さえた形（押さえた数はそのまま残る）と、枠の定員が減った形
            $mug = DemoShopBuilder::find($def, 'mug') ?? throw new \LogicException('mug');
            app(StockService::class)->reserve($mug, Location::query()->orderBy('created_at')->firstOrFail(), 20);
            $this->assertSame(0, InventoryStock::query()->where('listing_id', $mug->id)->sum('quantity') - InventoryStock::query()->where('listing_id', $mug->id)->sum('reserved'));

            if (! $booking) {
                $this->assertNull(DemoShopBuilder::find($def, 'class-roast'));

                return [$mug->id, null];
            }

            $class = DemoShopBuilder::find($def, 'class-roast') ?? throw new \LogicException('class-roast');

            return [$mug->id, $this->narrowFirstSlot($class->id)];
        });

        $this->artisan('demo:restock')->assertSuccessful();

        $this->inside('demostock', function () use ($mugId, $slotId): void {
            $stock = InventoryStock::query()->where('listing_id', $mugId)->firstOrFail();
            $this->assertSame(20, $stock->available());
            $this->assertSame(20, $stock->reserved);

            if ($slotId !== null) {
                if (! class_exists(BookingDemoParts::class)) {
                    $this->missingPart('tests/Feature/Plugin/BookingDemoParts.php');
                }

                $this->assertSame(3, (new BookingDemoParts)->capacity($slotId));
            }

            $this->assertTrue(DomainEvent::query()->where('type', 'inventory.adjusted')->where('actor', DemoRestock::ACTOR)->exists());
        });

        // 2 回目は何も変えない
        $this->artisan('demo:restock')->expectsOutputToContain('0 件')->assertSuccessful();

        // デモでない店は名指ししても断る
        $plain = $this->tenant('plainstock');
        $plain->setAttribute(DemoShop::FLAG, true);
        $plain->setAttribute(DemoShop::BUILD, ['kind' => 'coffee', 'name' => '名前', 'photos' => null]);
        $plain->save();
        $this->artisan('demo:restock', ['--tenant' => ['plainstock']])->expectsOutputToContain('デモの店ではない')->assertFailed();
    }

    public function test_the_restock_adds_seats_for_the_tickets_held(): void
    {
        $this->requiresApps('tickets');

        // 公演と席を触る所は TicketDemoParts（許す一覧の Ticket* で公開の木に入らない）
        if (! class_exists(TicketDemoParts::class)) {
            $this->missingPart('tests/Feature/Plugin/TicketDemoParts.php');
        }

        $tickets = new TicketDemoParts;
        $this->demo('demoseat', 'livehouse');
        $def = DemoShops::definition('livehouse');

        $variantId = $this->inside('demoseat', function () use ($def, $tickets): string {
            $listing = DemoShopBuilder::find($def, 'oneman') ?? throw new \LogicException('oneman');
            $variant = ListingVariant::query()->where('listing_id', $listing->id)->where('sku', 'DEMO-LIVE-ONEMAN-SEAT')->firstOrFail();
            $account = app(CustomerAccounts::class)->forEmail('member@example.com', 'storefront');
            $tickets->buy($listing, $variant, 3, '夜鷹 響', $account);
            $this->assertSame(40, $tickets->capacity($variant->id));

            return $variant->id;
        });

        $this->artisan('demo:restock', ['--tenant' => ['demoseat']])->assertSuccessful();

        $this->inside('demoseat', function () use ($variantId, $tickets): void {
            // 押さえた 3 枚の分だけ席を足し、残りの席は作った時の 40 席
            $this->assertSame(43, $tickets->capacity($variantId));
            $this->assertSame(3, $tickets->heldFor($variantId));
            $stock = InventoryStock::query()->where('variant_id', $variantId)->firstOrFail();
            $this->assertSame(40, $stock->available());
        });
    }

    public function test_the_livehouse_offers_the_resale_lottery_on_its_sold_out_show(): void
    {
        $this->requiresApps('tickets', 'lottery');

        // 公演とリセールの抽選を見る所は TicketDemoParts・LotteryDemoParts（許す一覧の Ticket*・Lottery* で公開の木に入らない）
        if (! class_exists(TicketDemoParts::class) || ! class_exists(LotteryDemoParts::class)) {
            $this->missingPart('tests/Feature/Plugin/TicketDemoParts.php・LotteryDemoParts.php');
        }

        $tickets = new TicketDemoParts;
        $lottery = new LotteryDemoParts;
        $this->demo('demoresale', 'livehouse');
        $def = DemoShops::definition('livehouse');

        $listingId = $this->inside('demoresale', function () use ($def, $tickets, $lottery): string {
            $friday = DemoShopBuilder::find($def, 'friday') ?? throw new \LogicException('friday');
            $variant = ListingVariant::query()->where('listing_id', $friday->id)->sole();

            // 売り切れた公演で、公式の定価リセールを使い、買う人は App「抽選販売」の抽選で決まる（リセールの抽選を受け付けている）
            $this->assertTrue($tickets->resale($friday->id));
            $this->assertTrue(app(PluginStates::class)->enabled('lottery'));
            $this->assertTrue($lottery->soldOut($variant->id));
            $this->assertTrue($lottery->resaleOpen($friday, CarbonImmutable::now()));
            // 抽選販売の公演（受付中）はリセールの抽選に出さない
            $this->assertFalse($tickets->resale((DemoShopBuilder::find($def, 'anniversary') ?? throw new \LogicException('anniversary'))->id));

            return $friday->id;
        });

        // 商品の画面に「リセールの抽選に申し込む」
        $this->get("http://demoresale.localhost/products/{$listingId}")->assertOk()
            ->assertSee('/p/lottery/resale/'.$listingId, false)->assertSee('リセールの抽選に申し込む');
        tenancy()->end();

        // 毎時の戻しは売り切れのまま（在庫 0 へ戻す。リセールの抽選を消さない）
        $this->artisan('demo:restock', ['--tenant' => ['demoresale']])->assertSuccessful();
        $this->inside('demoresale', function () use ($listingId, $lottery): void {
            $listing = Listing::query()->findOrFail($listingId);
            $this->assertTrue($lottery->soldOut(ListingVariant::query()->where('listing_id', $listingId)->sole()->id));
            $this->assertTrue($lottery->resaleOpen($listing, CarbonImmutable::now()));
        });
    }

    public function test_a_failed_reset_keeps_the_test_key_and_the_next_run_finishes_it(): void
    {
        $this->demo('demofail', 'coffee');
        $keys = ['secret_key' => 'sk_test_'.str_repeat('c', 24), 'webhook_secret' => 'whsec_'.str_repeat('d', 24)];
        $this->inside('demofail', static fn () => app(PaymentGatewayService::class)->configureStripe($keys, 'test'));

        // 中身を入れる途中（商品を作るところ）で落とす
        $switch = new \ArrayObject(['fail' => true]);
        Event::listen('eloquent.creating: '.Listing::class, static function () use ($switch): void {
            if ($switch['fail'] === true) {
                throw new \RuntimeException('わざと落とす');
            }
        });

        $this->artisan('demo:reset', ['--tenant' => ['demofail']])->expectsOutputToContain('戻せませんでした')->assertFailed();

        // 鍵は作り直した DB に戻っている・途中の印が残る・運営の知らせ（incidents）に出る
        $this->assertSame($keys['secret_key'], $this->inside('demofail', static fn (): mixed => PaymentGateway::query()->where('provider', 'stripe')->where('mode', 'test')->first()?->credentials['secret_key'] ?? null));
        $this->assertIsArray(Tenant::query()->findOrFail('demofail')->getAttribute('demo_resetting'));
        $this->assertTrue(DB::table('incidents')->where('kind', 'command_failed')->where('location', 'demo:reset demofail')->exists());
        $this->assertFalse($this->inside('demofail', static fn (): bool => DemoStep::built()));

        // 売れる数の戻しは、途中の店に触れない
        $this->artisan('demo:restock', ['--tenant' => ['demofail']])->expectsOutputToContain('途中で止まっています')->assertFailed();

        // 次の回は続きから作り直して終わる（鍵はそのまま・途中の印は消える）
        $switch['fail'] = false;
        $this->artisan('demo:reset', ['--tenant' => ['demofail']])->expectsOutputToContain('作り直しから続けます')->assertSuccessful();
        $this->inside('demofail', function () use ($keys): void {
            $this->assertTrue(DemoStep::built());
            $this->assertSame(self::coffeeListings(), Listing::query()->count());
            $this->assertSame($keys['secret_key'], PaymentGateway::query()->where('provider', 'stripe')->where('mode', 'test')->firstOrFail()->credentials['secret_key'] ?? null);
        });
        $this->assertNull(Tenant::query()->findOrFail('demofail')->getAttribute('demo_resetting'));
    }

    public function test_the_reset_and_the_install_do_not_overlap(): void
    {
        $this->demo('demolock', 'coffee');
        $lock = Cache::lock('demo-shop:demolock', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('demo:reset', ['--tenant' => ['demolock']])->expectsOutputToContain('最中')->assertFailed();
            $this->artisan('demo:restock', ['--tenant' => ['demolock']])->expectsOutputToContain('飛ばしました')->assertSuccessful();
        } finally {
            $lock->release();
        }
    }

    public function test_the_reset_refuses_a_demo_shop_whose_script_never_finished(): void
    {
        $tenant = $this->tenant('demohalf');
        $this->artisan('shop:demo', ['id' => 'demohalf', '--on' => true])->assertSuccessful();
        $tenant->refresh();
        $tenant->setAttribute(DemoShop::BUILD, ['kind' => 'coffee', 'name' => '名前', 'photos' => null]);
        $tenant->save();
        $this->inside('demohalf', static fn () => app(CustomerAccounts::class)->forEmail('owner@example.com', 'storefront'));

        $this->artisan('demo:reset', ['--tenant' => ['demohalf']])->expectsOutputToContain('実行し終えていない')->assertFailed();
        $this->assertSame(1, $this->inside('demohalf', static fn (): int => CustomerAccount::query()->count()));
    }

    /**
     * 今の店の記録が、登録した受け手のどれにも最後まで流れ、届け直しを待つ・止めた記録が無い（ADR 0089）
     */
    private function assertRelayedToTheEnd(): void
    {
        $last = (int) DomainEvent::query()->max('sequence');
        $this->assertGreaterThan(0, $last);
        $this->assertSame(0, DB::table('event_retries')->count(), '届け直しを待つ・止めた記録がある');
        $this->assertGreaterThan(0, DB::table('event_receiver_positions')->count());
        $this->assertSame($last, (int) DB::table('event_receiver_positions')->min('position'), '最後まで流れていない受け手がある');
    }

    /**
     * 予約の商品の、これから先のいちばん早い枠の定員を 1 にして、その枠の ID を返す（店の中で呼ぶ。部品は BookingDemoParts）。
     * 戻り値の型をここで決めるのは、公開の木で部品の戻り値（無いクラスの呼び出し）が inside() の型の引数へ流れないようにするため
     */
    private function narrowFirstSlot(string $listingId): string
    {
        if (! class_exists(BookingDemoParts::class)) {
            $this->missingPart('tests/Feature/Plugin/BookingDemoParts.php');
        }

        $slots = new BookingDemoParts;
        $slotId = $slots->nextSlot($listingId);
        $slots->setCapacity($slotId, 1);

        return $slotId;
    }

    /**
     * デモの店を作って、台本を流す
     */
    private function demo(string $id, string $kind): void
    {
        $this->tenant($id);
        $this->artisan('shop:demo', ['id' => $id, '--on' => true])->assertSuccessful();
        $this->artisan('demo:shop', ['kind' => $kind, '--tenant' => $id, '--photos' => $this->photos])->assertSuccessful();
    }
}
