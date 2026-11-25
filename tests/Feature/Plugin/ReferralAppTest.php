<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventRelay;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Party\Services\CustomerAddresses;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Models\PointEntry;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Carbon\CarbonImmutable;
use Closure;
use EcSugar\PluginReferral\Models\Referral;
use EcSugar\PluginReferral\ReferralAdminPage;
use EcSugar\PluginReferral\ReferralCodes;
use EcSugar\PluginReferral\ReferralCookie;
use EcSugar\PluginReferral\Referrals;
use EcSugar\PluginReferral\ReferralSettings;
use EcSugar\PluginReferral\SamePerson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「友だち紹介」（ADR 0075）。会員の紹介のリンクから来た人が 30 日のうちに会員になり、最初に払われた注文が全部発送されたら、
 * 紹介した人と紹介された人の両方に核のポイントの帳簿で付ける（理由 referral）。
 * 自分の紹介（同じ台帳・メール・電話・届け先）・月の上限・2 回目の注文・取消・30 日の境・App を止めた・同時の発送・同じ接続元（確かめ待ち）・
 * ほかの店の印・Cookie の改ざん・イベントに個人情報が無い・ポイントを使わない店
 */
final class ReferralAppTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private string $knit;

    /** @var array<string, string> 送る Cookie（名前 => 値） */
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        FakeStripe::install();
        $this->tenant('rfa', 'かくざとう');
        $this->knit = $this->inside('rfa', fn (): string => $this->prepare());
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_friend_who_joins_through_the_link_and_whose_first_order_ships_gives_both_of_them_points(): void
    {
        $code = $this->inside('rfa', fn (): string => $this->codeOf($this->member('taro@example.com')));

        // リンクを開くと、印の Cookie（暗号化。中身は印と時刻）を置いてトップへ
        $open = $this->send('GET', "/p/referral/r/{$code}")->assertRedirect('http://rfa.localhost');
        $cookie = $open->getCookie(ReferralCookie::NAME);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertMatchesRegularExpression('/\A'.$code.'\.[0-9]+\z/', (string) $cookie->getValue());

        // 会員になる（メールのコードで）: 紹介を結び、印は使い終わる
        $friendId = $this->join('hanako@example.com', '198.51.100.7');
        $this->assertSame('', (string) $this->lastCookie(ReferralCookie::NAME));

        $this->inside('rfa', function () use ($friendId): void {
            $referral = Referral::query()->sole();
            $this->assertSame([Referral::LINKED, $friendId], [$referral->status, $referral->friend_account_id]);
            $this->assertNotNull($referral->source_index);
            $this->assertSame(64, strlen((string) $referral->source_index));

            // 最初の注文: 払われると結び、全部発送されると両方に 500 ずつ（核の帳簿へ、理由 referral）
            $first = $this->order('hanako@example.com', phone: '08055556666', line1: '2-2-2');
            $this->pay($first);
            $this->assertSame([Referral::ORDERED, $first->id], [$referral->refresh()->status, $referral->refresh()->transaction_id]);
            $this->assertSame(0, PointEntry::query()->count());

            $this->shipAll($first);
            $referral = $referral->fresh();
            $this->assertSame([Referral::REWARDED, 500, 500], [$referral?->status, $referral?->referrer_points, $referral?->friend_points]);
            $taro = CustomerAccount::query()->whereBlind('email', 'taro@example.com')->sole();
            $this->assertSame(500, app(PointService::class)->balance($taro->id));
            $this->assertSame(500, app(PointService::class)->balance($friendId));
            $this->assertSame([['adjust', 'referral'], ['adjust', 'referral']], PointEntry::query()->orderBy('seq')->get()->map(static fn (PointEntry $e): array => [$e->kind, $e->cause])->all());
            $this->assertSame(1, DomainEvent::query()->where('type', 'referral.rewarded')->count());

            // 2 回目の注文では付かない
            $second = $this->order('hanako@example.com', phone: '08055556666', line1: '2-2-2');
            $this->pay($second);
            $this->shipAll($second);
            $this->assertSame(2, PointEntry::query()->count());
        });

        // マイページの動きには「友だち紹介で付いた」
        $this->send('GET', '/account')->assertOk()->assertSee('友だち紹介で付与');
    }

    public function test_the_member_page_shows_the_link_to_members_only_and_renewing_it_stops_the_old_link(): void
    {
        $this->send('GET', '/p/referral/invite')->assertRedirect('http://rfa.localhost/account/login?return=%2Fp%2Freferral%2Finvite');

        $this->join('taro@example.com', '198.51.100.8');
        $page = $this->send('GET', '/p/referral/invite')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        preg_match('#http://rfa\.localhost/p/referral/r/('.ReferralCodes::PATTERN.')#', (string) $page->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null);
        $old = $m[1];
        $page->assertSee('LINE で送る')->assertSee('あなたに 500 ポイント、お友だちに 500 ポイントが付きます。')->assertSee('1 か月に 10 人まで');
        // マイページに入口
        $this->send('GET', '/account')->assertOk()->assertSee('/p/referral/invite', false)->assertSee('友だちを紹介する');

        // 作り直すと前の印は使えない（開いても印の Cookie を置かない）
        $this->send('POST', '/p/referral/invite/renew')->assertRedirect('http://rfa.localhost/p/referral/invite');
        $new = $this->inside('rfa', fn (): string => (string) DB::table('referral_codes')->value('code'));
        $this->assertNotSame($old, $new);
        $this->assertSame(1, $this->inside('rfa', fn (): int => DomainEvent::query()->where('type', 'referral.code_regenerated')->count()));

        $this->cookies = [];
        $this->assertNull($this->send('GET', "/p/referral/r/{$old}")->assertRedirect('http://rfa.localhost')->getCookie(ReferralCookie::NAME));
        $this->assertNotNull($this->send('GET', "/p/referral/r/{$new}")->getCookie(ReferralCookie::NAME));
        // 形の違う印は経路に合わない
        $this->send('GET', '/p/referral/r/0000000000')->assertNotFound();
    }

    public function test_no_points_for_referring_yourself_or_someone_with_the_same_email_phone_or_address(): void
    {
        $this->inside('rfa', function (): void {
            $taro = $this->member('taro@example.com');
            $code = $this->codeOf($taro);

            // 自分の印では結ばない
            $this->assertNull(app(Referrals::class)->link($taro->id, $code, '198.51.100.1', CarbonImmutable::now()));

            // 紹介した人の前の注文（届け先は渋谷区 1-1-1、電話 09012345678）
            $this->order('taro@example.com', phone: '09012345678', line1: '1-1-1');

            // 同じ届け先（電話は違う）: 発送の時に「自分の紹介」で付けない
            $sameAddress = $this->linkedFriend($code, 'jiro@example.com', '198.51.100.2');
            $order = $this->order('jiro@example.com', phone: '07000000001', line1: '１－１－１');
            $this->pay($order);
            $this->shipAll($order);
            $this->assertSame([Referral::REFUSED, 'self'], [$sameAddress->refresh()->status, $sameAddress->refresh()->reason]);

            // 同じ電話（届け先は違う）
            $samePhone = $this->linkedFriend($code, 'saburo@example.com', '198.51.100.3');
            $order = $this->order('saburo@example.com', phone: '090-1234-5678', line1: '9-9-9');
            $this->pay($order);
            $this->shipAll($order);
            $this->assertSame([Referral::REFUSED, 'self'], [$samePhone->refresh()->status, $samePhone->refresh()->reason]);
            $this->assertSame(0, PointEntry::query()->count());

            // 同じメール: 紹介された人の注文の買う人が、紹介した人のメール（核の注文は台帳と買う人を別に持てる）
            $friend = $this->member('shiro@example.com');
            $this->assertFalse(SamePerson::matches($taro->id, $friend->id, null));
            $sale = $this->rawOrder($friend, 'taro@example.com', '07099998888');
            $this->assertTrue(SamePerson::matches($taro->id, $friend->id, $sale->id));
            // 会員の届け先に同じ電話を入れている
            $other = $this->member('goro@example.com');
            app(CustomerAddresses::class)->add($other, ['name' => '五郎', 'phone' => '09012345678', 'postal' => '1000001', 'prefecture' => '東京都', 'city' => '千代田区', 'line1' => '5', 'line2' => null], 'test');
            $this->assertTrue(SamePerson::matches($taro->id, $other->id, null));
            // 違う人は通る
            $stranger = $this->member('rokuro@example.com');
            $this->assertFalse(SamePerson::matches($taro->id, $stranger->id, $this->rawOrder($stranger, 'rokuro@example.com', '07011112222')->id));
        });
    }

    public function test_a_referrer_gets_points_for_ten_friends_a_month_and_not_the_eleventh_until_next_month(): void
    {
        // 月の初め（日本の時間の 12 月 1 日 0 時 30 分 = UTC では 11 月 30 日）: 月は日本の時間の暦で数える
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30', 'Asia/Tokyo'));

        $this->inside('rfa', function (): void {
            $taro = $this->member('taro@example.com');
            $code = $this->codeOf($taro);
            $referrals = [];

            for ($i = 1; $i <= 12; $i++) {
                $referrals[$i] = $this->linkedFriend($code, "friend{$i}@example.com", "198.51.100.{$i}");
            }

            for ($i = 1; $i <= 11; $i++) {
                $this->shipFirst("friend{$i}@example.com", $i);
            }

            // 10 人目までは付き、11 人目は今月の上限で付けない
            $this->assertSame(Referral::REWARDED, $referrals[10]->refresh()->status);
            $this->assertSame([Referral::REFUSED, 'limit'], [$referrals[11]->refresh()->status, $referrals[11]->refresh()->reason]);
            $this->assertSame(5000, app(PointService::class)->balance($taro->id));
            $this->assertSame(10, app(Referrals::class)->rewardedThisMonth($taro->id));

            // 来月（日本の時間の 1 日 0 時）からは、また付く
            $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00', 'Asia/Tokyo'));
            $this->shipFirst('friend12@example.com', 12);
            $this->assertSame(Referral::REWARDED, $referrals[12]->refresh()->status);
            $this->assertSame(5500, app(PointService::class)->balance($taro->id));

            // 設定で上限を変えられる（1〜100）
            app(PluginSettings::class)->update('referral', ['monthly_limit' => '1'], 'test');
            $this->assertSame(1, app(ReferralSettings::class)->monthlyLimit());
        });
    }

    public function test_a_cancelled_first_order_gives_nothing_and_a_later_order_does_not_either_but_an_unpaid_one_does_not_count(): void
    {
        $this->inside('rfa', function (): void {
            $code = $this->codeOf($this->member('taro@example.com'));

            // 払われずに消えた注文は「最初の注文」に数えない
            $lapsedFriend = $this->linkedFriend($code, 'hanako@example.com', '198.51.100.1');
            $lapsed = $this->order('hanako@example.com', phone: '07000000001', line1: '3');
            DB::transaction(function () use ($lapsed): void {
                app(EventStore::class)->lock();
                app(PaymentNotices::class)->cancelUnpaidOrder(Transaction::query()->lockForUpdate()->findOrFail($lapsed->id), 'system:expiry');
            });
            $this->assertSame(Referral::LINKED, $lapsedFriend->refresh()->status);
            $this->shipFirst('hanako@example.com', 1);
            $this->assertSame(Referral::REWARDED, $lapsedFriend->refresh()->status);

            // 払った後に発送の前で取り消した: 付けない。2 回目の注文が発送されても付けない
            $friend = $this->linkedFriend($code, 'jiro@example.com', '198.51.100.2');
            $first = $this->order('jiro@example.com', phone: '07000000002', line1: '4');
            $this->pay($first);
            app(SaleOrderService::class)->cancel($first, 'operator:test');
            $this->assertSame([Referral::REFUSED, 'cancelled'], [$friend->refresh()->status, $friend->refresh()->reason]);
            $this->shipFirst('jiro@example.com', 2);
            $this->assertSame(Referral::REFUSED, $friend->refresh()->status);
            $this->assertSame(0, PointEntry::query()->whereIn('customer_account_id', [$friend->friend_account_id])->count());
        });
    }

    public function test_a_friend_with_an_order_paid_before_joining_is_not_new(): void
    {
        $this->inside('rfa', function (): void {
            $code = $this->codeOf($this->member('taro@example.com'));
            // ゲストで払った注文がある人
            $guest = $this->order('hanako@example.com', phone: '07000000001', line1: '3');
            $this->pay($guest);
            $account = CustomerAccount::query()->whereBlind('email', 'hanako@example.com')->sole();
            CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);

            $this->assertNull(app(Referrals::class)->link($account->id, $code, '198.51.100.1', CarbonImmutable::now()));
            $this->assertSame(0, Referral::query()->count());
        });
    }

    public function test_the_link_works_for_thirty_days_and_not_a_minute_after(): void
    {
        $code = $this->inside('rfa', fn (): string => $this->codeOf($this->member('taro@example.com')));
        $this->send('GET', "/p/referral/r/{$code}");
        $this->travel(30)->days();
        $this->travel(-1)->minutes();
        $this->join('hanako@example.com', '198.51.100.9');
        $this->assertSame(1, $this->inside('rfa', fn (): int => Referral::query()->count()));

        $this->cookies = [];
        $this->send('GET', "/p/referral/r/{$code}");
        $this->travel(30)->days();
        $this->travel(1)->minutes();
        $this->join('jiro@example.com', '198.51.100.10');
        $this->assertSame(1, $this->inside('rfa', fn (): int => Referral::query()->count()));
    }

    public function test_a_stopped_app_takes_no_links_and_a_shipment_while_stopped_gives_nothing(): void
    {
        $referral = $this->inside('rfa', function (): Referral {
            $code = $this->codeOf($this->member('taro@example.com'));
            $referral = $this->linkedFriend($code, 'hanako@example.com', '198.51.100.1');
            $order = $this->order('hanako@example.com', phone: '07000000001', line1: '3');
            $this->pay($order);
            app(PluginStates::class)->set('referral', false, 'test');
            $this->shipAll($order);

            return $referral;
        });

        $this->inside('rfa', function () use ($referral): void {
            $this->assertSame([Referral::REFUSED, 'disabled'], [$referral->refresh()->status, $referral->refresh()->reason]);
            $this->assertSame(0, PointEntry::query()->count());
        });
        // 止めた店では紹介のリンクも無い
        $this->send('GET', '/p/referral/r/'.str_repeat('a', 10))->assertNotFound();
        $this->send('GET', '/p/referral/invite')->assertNotFound();
    }

    public function test_two_shipment_notices_at_once_give_the_points_only_once(): void
    {
        $this->inside('rfa', function (): void {
            $code = $this->codeOf($this->member('taro@example.com'));
            $referral = $this->linkedFriend($code, 'hanako@example.com', '198.51.100.1');
            $order = $this->order('hanako@example.com', phone: '07000000001', line1: '3');
            $this->pay($order);

            // 発送の知らせを受け損ねた（受け手を外して発送）
            $this->withoutReferralShipping(fn () => $this->shipAll($order));
            $this->assertSame(Referral::ORDERED, $referral->refresh()->status);

            // 別の接続が連鎖のロックを持っている間（もう 1 つの知らせを処理中）は、待って先に進まない
            config(['database.connections.tenant_other' => config('database.connections.tenant')]);
            $other = DB::connection('tenant_other');
            $other->beginTransaction();

            try {
                $other->select('SELECT pg_advisory_xact_lock(?)', [0x5E_C0_E7]);
                DB::statement("SET lock_timeout = '300ms'");

                try {
                    app(Referrals::class)->shipped($order->id, true);
                    $this->fail('連鎖のロックを待たずに付けた');
                } catch (QueryException $e) {
                    $this->assertSame('55P03', $e->getCode());
                }
            } finally {
                $other->rollBack();
                DB::statement('SET lock_timeout = 0');
                DB::purge('tenant_other');
            }

            $this->assertSame(0, PointEntry::query()->count());

            // 同じ知らせが 2 度・毎時の拾いとも重なっても 1 回だけ
            app(Referrals::class)->shipped($order->id, true);
            app(Referrals::class)->shipped($order->id, true);
            $this->artisan('referral:run')->assertSuccessful();
            $this->assertSame(Referral::REWARDED, $referral->refresh()->status);
            $this->assertSame(2, PointEntry::query()->count());
            $this->assertSame(1, DomainEvent::query()->where('type', 'referral.rewarded')->count());
        });
    }

    public function test_many_friends_from_one_connection_wait_for_the_shop_to_decide(): void
    {
        $this->inside('rfa', function (): void {
            $code = $this->codeOf($this->member('taro@example.com'));
            $page = new ReferralAdminPage;
            $held = [];

            // 同じ接続元から 3 人: 3 人目から確かめ待ち（1・2 人目は付く）
            for ($i = 1; $i <= 4; $i++) {
                $held[$i] = $this->linkedFriend($code, "friend{$i}@example.com", '203.0.113.50');
                $this->shipFirst("friend{$i}@example.com", $i);
            }

            $this->assertSame(Referral::REWARDED, $held[2]->refresh()->status);
            $this->assertSame([Referral::REVIEW, 'same_source'], [$held[3]->refresh()->status, $held[3]->refresh()->reason]);
            $this->assertSame(Referral::REVIEW, $held[4]->refresh()->status);
            $this->assertSame(1000, app(PointService::class)->balance($held[1]->referrer_account_id));

            // App の画面: 確かめ待ちの数・ホームのやること・付けたポイントの合計。会員は ID の末尾だけ
            $filters = collect($page->filters())->keyBy('key');
            $this->assertSame(2, $filters['review']->count);
            $this->assertSame('ポイントを付けた（計 2,000 ポイント）', $filters['rewarded']->label);
            $this->assertSame(2, collect($page->attention())->firstWhere('filter', 'review')?->count);
            $row = $page->rows('review', 1, 20)->rows[0];
            $this->assertSame('customer', $row->cells['friend']['kind']);
            $this->assertStringStartsWith('会員 ', $row->cells['friend']['label']);

            // 1 件の画面の操作: 付ける（直前の再確認の印）・付けない
            $record = $page->record($held[3]->id);
            $this->assertNotNull($record);
            $this->assertSame(['approve', 'decline'], array_map(static fn ($a): string => $a->key, $record->actions));
            $this->assertTrue($record->actions[0]->danger);
            $page->act($held[3]->id, 'approve', 'operator:op1');
            $this->assertSame([Referral::REWARDED, 500, 500], [$held[3]->refresh()->status, $held[3]->refresh()->referrer_points, $held[3]->refresh()->friend_points]);
            $this->assertSame('operator:op1', DomainEvent::query()->where('type', 'referral.approved')->sole()->actor);
            $page->act($held[4]->id, 'decline', 'operator:op1');
            $this->assertSame(Referral::DECLINED, $held[4]->refresh()->status);
            $this->assertSame([], $page->record($held[4]->id)?->actions);

            // もう決めたものは押せない
            $this->expectException(AdminActionRefused::class);
            app(Referrals::class)->approve($held[4]->id, 'operator:op1');
        });
    }

    public function test_a_code_from_another_shop_and_a_tampered_cookie_link_nobody(): void
    {
        $this->tenant('rfb', 'ほかの店');
        $other = $this->inside('rfb', function (): string {
            app(PluginStates::class)->set('referral', true, 'test');

            return $this->codeOf($this->member('taro@example.com'));
        });

        // ほかの店の印: 開いても印を置かない。印を持ち込んでも結ばない
        $this->assertNull($this->send('GET', "/p/referral/r/{$other}")->getCookie(ReferralCookie::NAME));
        $this->cookies[ReferralCookie::NAME] = $other.'.'.CarbonImmutable::now()->getTimestamp();
        $this->join('hanako@example.com', '198.51.100.20');

        // 暗号化していない（書き換えた）印は読めない
        $mine = $this->inside('rfa', fn (): string => $this->codeOf($this->member('jiro@example.com')));
        $this->cookies = [];
        $this->defaultCookies = [];
        $this->withUnencryptedCookie(ReferralCookie::NAME, $mine.'.'.CarbonImmutable::now()->getTimestamp());
        $this->joinRaw('saburo@example.com', '198.51.100.21');

        $this->assertSame(0, $this->inside('rfa', fn (): int => Referral::query()->count()));
        $this->assertSame(0, $this->inside('rfb', fn (): int => Referral::query()->count()));
    }

    public function test_referral_events_carry_no_personal_information(): void
    {
        $code = $this->inside('rfa', fn (): string => $this->codeOf($this->member('taro@example.com')));
        $this->send('GET', "/p/referral/r/{$code}");
        $this->join('hanako@example.com', '198.51.100.30');

        $this->inside('rfa', function () use ($code): void {
            $order = $this->order('hanako@example.com', phone: '07012340000', line1: '7-7');
            $this->pay($order);
            $this->shipAll($order);
            $this->assertSame(Referral::REWARDED, Referral::query()->sole()->status);

            $events = DomainEvent::query()->where(static fn ($q) => $q->where('type', 'like', 'referral.%')->orWhere('type', 'like', 'points.%'))->get();
            $this->assertGreaterThanOrEqual(5, $events->count());
            $text = json_encode($events->map(static fn (DomainEvent $e): array => [$e->payload, $e->actor])->all(), JSON_UNESCAPED_UNICODE) ?: '';

            foreach (['hanako', 'taro', 'example.com', '07012340000', '198.51.100.30', '渋谷', '山田', $code] as $needle) {
                $this->assertStringNotContainsString($needle, $text);
            }

            // 表にも平文の接続元は無い
            $this->assertStringNotContainsString('198.51.100', json_encode(DB::table('referrals')->get()) ?: '');
        });
    }

    public function test_a_shop_that_does_not_use_points_takes_no_referrals_and_says_so(): void
    {
        $code = $this->inside('rfa', function (): string {
            $code = $this->codeOf($this->member('taro@example.com'));
            app(PointSettings::class)->update(['enabled' => false, 'rate_bp' => 0], 'test');
            $attention = collect((new ReferralAdminPage)->attention())->firstWhere('filter', 'all');
            $this->assertSame(1, $attention?->count);

            return $code;
        });

        $this->assertNull($this->send('GET', "/p/referral/r/{$code}")->getCookie(ReferralCookie::NAME));
        $this->join('hanako@example.com', '198.51.100.40');
        $this->send('GET', '/p/referral/invite')->assertOk()->assertSee('今は友だちの紹介を受け付けていません');
        $this->assertSame(0, $this->inside('rfa', fn (): int => Referral::query()->count()));
    }

    public function test_the_core_grant_takes_only_known_causes_and_members_and_the_ledger_refuses_unknown_causes(): void
    {
        $this->inside('rfa', function (): void {
            $taro = $this->member('taro@example.com');
            $points = app(PointService::class);
            $this->assertSame('referral', $points->grant($taro->id, 300, 'referral', 'system:referral')?->cause);
            $this->assertSame(300, $points->balance($taro->id));
            $this->assertEquals(['points' => 300, 'balance' => 300, 'cause' => 'referral'], DomainEvent::query()->where('type', 'points.adjusted')->sole()->payload);

            // 会員でない台帳には付けない
            $guest = app(CustomerAccounts::class)->forEmail('guest@example.com', 'test');
            $this->assertNull($points->grant($guest->id, 300, 'referral', 'system:referral'));

            try {
                $points->grant($taro->id, 300, 'gift', 'system:referral');
                $this->fail('知らない理由で付けた');
            } catch (LogicException) {
            }

            // DB も知らない理由を断る
            try {
                DB::table('point_entries')->insert(['id' => strtolower((string) Str::ulid()), 'customer_account_id' => $taro->id, 'kind' => 'adjust', 'points' => 1, 'cause' => 'gift', 'occurred_at' => now()]);
                $this->fail('DB が知らない理由を通した');
            } catch (QueryException $e) {
                $this->assertStringContainsString('point_entries_cause_known', $e->getMessage());
            }
        });
    }

    // --- 補助 ---

    private function prepare(): string
    {
        app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
        app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 10000]);
        // 注文で付くポイントは 0 にして、紹介の分だけを数える
        app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 0], 'test');
        app(PluginStates::class)->set('referral', true, 'test');
        $knit = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
        app(StockService::class)->receive($knit, Location::query()->firstOrFail(), 200);

        return $knit->id;
    }

    private function member(string $email): CustomerAccount
    {
        $account = app(CustomerAccounts::class)->forEmail($email, 'test');
        CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);

        return CustomerAccount::query()->findOrFail($account->id);
    }

    private function codeOf(CustomerAccount $account): string
    {
        $code = app(ReferralCodes::class)->forMember($account, 'test');
        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/\A'.ReferralCodes::PATTERN.'\z/', $code);

        return $code;
    }

    /**
     * 紹介のリンクから来て会員になった人（会員になる所はサービスで。HTTP の道は別のテスト）
     */
    private function linkedFriend(string $code, string $email, string $from): Referral
    {
        $friend = $this->member($email);
        $referral = app(Referrals::class)->link($friend->id, $code, $from, CarbonImmutable::now());
        $this->assertNotNull($referral, "{$email} が結ばれない");

        return $referral;
    }

    /**
     * 最初の注文を払って発送する（電話と番地は人ごとに変える）
     */
    private function shipFirst(string $email, int $n): Transaction
    {
        $order = $this->order($email, phone: sprintf('0700000%04d', $n), line1: "{$n}-{$n}");
        $this->pay($order);
        $this->shipAll($order);

        return $order;
    }

    private function order(string $email, string $phone, string $line1): Transaction
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->knit)->value('id'), 1);
        $input = CheckoutInput::fromArray(['buyer' => ['email' => $email, 'name' => '山田 花子', 'phone' => $phone, 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => $line1, 'line2' => null], 'recipient' => null]);
        $this->assertNotNull($input);
        $fingerprint = CheckoutSummary::fingerprintOf($carts->view($cart, $input->destination(), $email, $input->pointsRequested()));

        return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);
    }

    /**
     * 台帳と買う人を別に持つ注文（核の取引を直に開く）
     */
    private function rawOrder(CustomerAccount $account, string $buyerEmail, string $phone): Transaction
    {
        $listing = Listing::query()->findOrFail($this->knit);
        $buyer = Party::create(['kind' => PartyKind::Customer, 'name' => '買う人', 'email' => $buyerEmail, 'phone' => $phone, 'address' => ['zip' => '9990000', 'prefecture' => '北海道', 'city' => '札幌市', 'line1' => '8']]);

        return app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), $buyer, [LineDraft::fromListing($listing, 1, 4400, Location::query()->firstOrFail())], actor: 'test', customerAccount: $account);
    }

    private function pay(Transaction $sale): void
    {
        app(PaymentService::class)->startCheckout($sale, 'x', 'y');
        $ref = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole()->provider_ref;
        $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$sale->id, 'checkout.session.completed', $ref, $sale->id, (int) $sale->total_amount, 'jpy', 'pi_'.$sale->id));
    }

    private function shipAll(Transaction $sale): void
    {
        foreach (Fulfillment::query()->where('transaction_id', $sale->id)->get() as $fulfillment) {
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), $fulfillment->id, 'yamato', '1234', 'operator:test');
        }
    }

    /**
     * 発送の知らせを受け手に届けずに動かす（受け手が何もしなかった時の代わり。送り出し役は「流した」にして先へ進む）
     *
     * @param  Closure(): void  $work
     */
    private function withoutReferralShipping(Closure $work): void
    {
        EventRelay::muting(['referral.shipped'], $work);
    }

    /**
     * メールのコードで会員になる（この要求に紹介の印の Cookie があれば一緒に送る）。会員の台帳の ID を返す
     */
    private function join(string $email, string $ip): string
    {
        $this->joinRaw($email, $ip);

        return $this->inside('rfa', fn (): string => CustomerAccount::query()->whereBlind('email', $email)->sole()->id);
    }

    private function joinRaw(string $email, string $ip): void
    {
        $count = count(Mail::sent(LoginCodeMail::class));
        $this->send('POST', '/account/login', ['email' => $email], $ip)->assertRedirect('http://rfa.localhost/account/login/code');
        $mails = Mail::sent(LoginCodeMail::class);
        $this->assertCount($count + 1, $mails);
        $this->send('POST', '/account/login/code', ['code' => $mails->last()->code], $ip)->assertRedirect('http://rfa.localhost/account');
    }

    /** 紹介の印の Cookie の最後に受け取った値 */
    private ?string $last = null;

    private function lastCookie(string $name): ?string
    {
        return $name === ReferralCookie::NAME ? $this->last : null;
    }

    /**
     * 要求を送る。会員の印と紹介の印の Cookie を持ち回る（本番と同じく、要求ごとにログインの仕組みを作り直す）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = [], ?string $ip = null): TestResponse
    {
        $this->defaultCookies = [];

        foreach ($this->cookies as $name => $value) {
            $this->withCookie($name, $value);
        }

        // 人の速さで（接続元ごとの 1 分に 10 回の制限に、テスト自身が当たらないように）
        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://rfa.localhost{$path}";
        $server = $ip === null ? [] : ['REMOTE_ADDR' => $ip];
        $response = $method === 'GET' ? $this->withServerVariables($server)->get($url) : $this->withServerVariables($server)->post($url, $data);
        tenancy()->end();

        foreach ([MemberCookie::NAME, ReferralCookie::NAME] as $name) {
            $cookie = $response->getCookie($name);

            if ($cookie === null) {
                continue;
            }

            $value = (string) $cookie->getValue();

            if ($name === ReferralCookie::NAME) {
                $this->last = $value;
            }

            if ($value === '') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }
}
