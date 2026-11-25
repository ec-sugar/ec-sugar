<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerAddress;
use App\Core\Party\Models\CustomerSession;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Party\Services\CustomerAddresses;
use App\Core\Party\Services\CustomerMerges;
use App\Core\Party\Services\CustomerSessions;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Members\EmailChangedMail;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Members\SendEmailChangedNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 会員の届け先・本人の再確認・退会（ADR 0044 の 3。2026-11-01 に運営が決めた形）。
 * 届け先はいくつも持て、いつもの届け先を注文の入力に入れる。大事な操作の前はその場で新しいコード。退会で印と届け先を消し、注文は残す。
 */
final class MemberAccountTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private ?string $member = null;

    private ?string $cart = null;

    private string $ip = '127.0.0.1';

    /** @var array<string, string> */
    private array $home = ['name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];

    /** @var array<string, string> */
    private array $parents = ['name' => '山田 一郎', 'phone' => '0312345678', 'postal' => '0600000', 'prefecture' => '北海道', 'city' => '札幌市中央区', 'line1' => '北一条 1', 'line2' => '2 階'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->tenant('maa');
        $this->tenant('mab');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_member_keeps_several_addresses_and_one_default(): void
    {
        $this->signIn('maa', 'hanako@example.com');

        // 形の違う届け先は断る（注文の入力と同じ決まり）
        $this->send('maa', 'POST', '/account/addresses', ['address' => [...$this->home, 'phone' => '123']])->assertSessionHasErrors('address.phone');
        $this->send('maa', 'POST', '/account/addresses', ['address' => [...$this->home, 'name' => "山田\n花子"]])->assertSessionHasErrors('address.name');
        $this->send('maa', 'POST', '/account/addresses', ['address' => 'x'])->assertSessionHasErrors('address');

        // 最初の届け先がいつもの届け先。電話と郵便番号は数字だけにそろえる
        $this->send('maa', 'POST', '/account/addresses', ['address' => $this->home])->assertRedirect('http://maa.localhost/account/addresses');
        $this->send('maa', 'POST', '/account/addresses', ['address' => $this->parents])->assertRedirect('http://maa.localhost/account/addresses');
        $html = (string) $this->send('maa', 'GET', '/account/addresses')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'data-test="address-item"'));
        $this->assertSame(1, substr_count($html, 'data-test="address-default"'));
        $this->assertLessThan(strpos($html, '山田 一郎'), strpos($html, '山田 花子'));

        [$home, $parents] = $this->inside('maa', function (): array {
            $rows = CustomerAddress::query()->orderBy('created_at')->orderBy('id')->get();
            $this->assertSame('09012345678', $rows[0]->address['phone']);
            $this->assertSame('1500001', $rows[0]->address['postal']);
            $this->assertNull($rows[0]->address['line2']);
            // 中身は暗号化（列は読めない）。イベントには ID だけ
            $this->assertStringStartsWith(TenantCipher::PREFIX, (string) DB::table('customer_addresses')->where('id', $rows[0]->id)->value('address'));
            foreach (DomainEvent::query()->where('type', 'like', 'customer_address.%')->get() as $event) {
                $this->assertSame(['address_id'], array_keys((array) $event->payload));
            }

            return [$rows[0]->id, $rows[1]->id];
        });

        // いつもの届け先を替える・直す
        $this->send('maa', 'POST', "/account/addresses/{$parents}/default")->assertRedirect('http://maa.localhost/account/addresses');
        $this->send('maa', 'GET', "/account/addresses/{$home}")->assertOk()->assertSee('神宮前 1-2-3');
        $this->send('maa', 'POST', "/account/addresses/{$home}", ['address' => [...$this->home, 'line1' => '神宮前 9-9-9']])->assertRedirect('http://maa.localhost/account/addresses');
        $this->inside('maa', function () use ($home, $parents): void {
            $this->assertSame([$parents], CustomerAddress::query()->where('is_default', true)->pluck('id')->all());
            $this->assertSame('神宮前 9-9-9', CustomerAddress::query()->findOrFail($home)->address['line1']);
        });

        // いつもの届け先を消すと、残りがいつもの届け先に
        $this->send('maa', 'POST', "/account/addresses/{$parents}/delete")->assertRedirect('http://maa.localhost/account/addresses');
        $this->inside('maa', fn () => $this->assertSame([$home], CustomerAddress::query()->where('is_default', true)->pluck('id')->all()));

        // 20 件まで
        $this->inside('maa', function (): void {
            $account = CustomerAccount::query()->sole();
            for ($i = 1; $i < CustomerAddresses::LIMIT; $i++) {
                app(CustomerAddresses::class)->add($account, [...$this->parents, 'phone' => '0312345678', 'postal' => '0600000'], 'storefront');
            }
        });
        $this->send('maa', 'POST', '/account/addresses', ['address' => $this->parents])->assertRedirect('http://maa.localhost/account/addresses');
        $this->assertStringContainsString('20 件まで', (string) $this->send('maa', 'GET', '/account/addresses')->getContent());
        $this->inside('maa', fn () => $this->assertSame(CustomerAddresses::LIMIT, CustomerAddress::query()->count()));
    }

    public function test_another_members_address_is_out_of_reach(): void
    {
        $this->signIn('maa', 'taro@example.com');
        $this->send('maa', 'POST', '/account/addresses', ['address' => $this->parents]);
        $taros = $this->inside('maa', fn (): string => (string) CustomerAddress::query()->value('id'));
        $this->send('maa', 'POST', '/account/logout');

        $this->signIn('maa', 'hanako@example.com');
        // ほかの会員の届け先は、見る・直す・消す・いつもにする、のどれも「見つかりません」で何も変わらない
        $this->send('maa', 'GET', "/account/addresses/{$taros}")->assertRedirect('http://maa.localhost/account/addresses');
        foreach (["/account/addresses/{$taros}" => ['address' => $this->home], "/account/addresses/{$taros}/delete" => [], "/account/addresses/{$taros}/default" => []] as $path => $data) {
            $this->send('maa', 'POST', $path, $data)->assertRedirect('http://maa.localhost/account/addresses');
        }
        $this->assertStringContainsString('見つかりません', (string) $this->send('maa', 'GET', '/account/addresses')->getContent());
        $this->inside('maa', function () use ($taros): void {
            $row = CustomerAddress::query()->findOrFail($taros);
            $this->assertSame('山田 一郎', $row->address['name']);
        });

        // 注文の入力で、ほかの会員の届け先を選ばせても入らない
        $this->putInCart('maa');
        $html = (string) $this->send('maa', 'GET', "/checkout?ship_to={$taros}")->assertOk()->getContent();
        $this->assertStringNotContainsString('札幌市中央区', $html);

        // ログインしていなければ届け先の画面には入れない
        $this->member = null;
        $this->send('maa', 'GET', '/account/addresses')->assertRedirect('http://maa.localhost/account/login');
        $this->send('maa', 'POST', '/account/addresses', ['address' => $this->home])->assertRedirect('http://maa.localhost/account/login');
    }

    public function test_the_checkout_starts_with_the_default_address_and_can_ship_to_another(): void
    {
        $this->signIn('maa', 'hanako@example.com');
        $this->send('maa', 'POST', '/account/addresses', ['address' => $this->home]);
        $this->send('maa', 'POST', '/account/addresses', ['address' => $this->parents]);
        $parents = $this->inside('maa', fn (): string => (string) CustomerAddress::query()->where('is_default', false)->value('id'));
        $this->putInCart('maa');

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('maa')->forceFill(['theme' => $theme])->save();
            // ご依頼主にいつもの届け先と会員のメール。お届け先は「ご依頼主と同じ」
            $html = (string) $this->send('maa', 'GET', '/checkout')->assertOk()->getContent();
            foreach (['data-test="saved-addresses"', 'value="山田 花子"', 'value="hanako@example.com"', 'value="神宮前 1-2-3"'] as $text) {
                $this->assertStringContainsString($text, $html, "{$theme}: {$text}");
            }

            // 実家を選ぶとお届け先に入る
            $html = (string) $this->send('maa', 'GET', "/checkout?ship_to={$parents}")->assertOk()->getContent();
            $this->assertStringContainsString('value="山田 一郎"', $html, $theme);
            $this->assertMatchesRegularExpression('/aria-current="true"[^>]*>山田 一郎/u', $html, $theme);
        }

        // そのまま注文まで進める（届け先は入力として送るので、注文の決まりは変わらない）
        $this->send('maa', 'POST', '/checkout', [
            'buyer' => [...$this->home, 'email' => 'hanako@example.com'],
            'same_as_buyer' => '0',
            'recipient' => $this->parents,
        ])->assertRedirect('http://maa.localhost/checkout/confirm');

        // 会員でないお客さんには何も出さない（ゲスト購入はそのまま）
        $this->member = null;
        $html = (string) $this->send('maa', 'GET', '/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-test="saved-addresses"', $html);
    }

    public function test_leaving_needs_a_fresh_code_and_keeps_the_orders(): void
    {
        $order = $this->order('maa', 'hanako@example.com');
        $this->signIn('maa', 'hanako@example.com');
        $first = (string) $this->member;
        $this->send('maa', 'POST', '/account/addresses', ['address' => $this->home]);
        // ほかの端末でも入っている
        $this->member = null;
        $this->signIn('maa', 'hanako@example.com', '198.51.100.30');
        $second = (string) $this->member;
        $this->member = $first;
        $this->ip = '127.0.0.1';

        // 再確認の前は退会の画面に入れず、送りもしない（画面を開くだけではメールを送らない）
        $mails = count(Mail::sent(LoginCodeMail::class));
        $this->send('maa', 'GET', '/account/leave')->assertRedirect('http://maa.localhost/account/confirm?next=leave');
        $this->send('maa', 'POST', '/account/leave')->assertRedirect('http://maa.localhost/account/confirm?next=leave');
        $this->send('maa', 'GET', '/account/confirm?next=leave')->assertOk()->assertSee('data-test="confirm-send"', false);
        $this->assertCount($mails, Mail::sent(LoginCodeMail::class));

        // 違うコード → 断る。送り直した新しいコード → 退会の画面へ（行き先は決まった名前だけ）
        $code = $this->sendConfirm('https://evil.example/');
        $this->send('maa', 'POST', '/account/confirm', ['code' => $code === '000000' ? '000001' : '000000', 'next' => 'leave'])->assertRedirect('http://maa.localhost/account/confirm?next=leave');
        $this->send('maa', 'POST', '/account/confirm', ['code' => $code, 'next' => 'https://evil.example/'])->assertRedirect('http://maa.localhost/account/leave');
        $this->send('maa', 'GET', '/account/leave')->assertOk()->assertSee('data-test="leave-submit"', false);

        // 確かめたのはこの端末だけ。ほかの端末は確かめ直しが要る
        $this->inside('maa', function () use ($first, $second): void {
            $this->assertTrue(app(CustomerSessions::class)->confirmedRecently($first));
            $this->assertFalse(app(CustomerSessions::class)->confirmedRecently($second));
        });

        // 10 分を過ぎたら、もう一度確かめる
        $this->travel(11)->minutes();
        $this->send('maa', 'POST', '/account/leave')->assertRedirect('http://maa.localhost/account/confirm?next=leave');
        $this->send('maa', 'POST', '/account/confirm', ['code' => $this->sendConfirm('leave'), 'next' => 'leave'])->assertRedirect('http://maa.localhost/account/leave');

        // 退会: 印は全部（ほかの端末も）、届け先は消え、台帳はゲストの形に。注文はそのまま
        $response = $this->send('maa', 'POST', '/account/leave')->assertRedirect('http://maa.localhost');
        $this->assertSame('', (string) $response->getCookie(MemberCookie::NAME)?->getValue());
        foreach ([$first, $second] as $token) {
            $this->member = $token;
            $this->send('maa', 'GET', '/account')->assertRedirect('http://maa.localhost/account/login');
        }
        $this->inside('maa', function () use ($order): void {
            $account = CustomerAccount::query()->sole();
            $this->assertNull($account->member_since);
            $this->assertSame(0, CustomerAddress::query()->count());
            $this->assertSame(0, CustomerSession::query()->whereNull('revoked_at')->count());
            $this->assertSame($account->id, Transaction::query()->findOrFail($order)->customer_account_id);
            $this->assertSame(['addresses_removed' => 1], (array) DomainEvent::query()->where('type', 'customer_account.left')->sole()->payload);
        });

        // もう一度コードで入れば会員に戻る（届け先は空、注文の履歴は見える）
        $this->member = null;
        $this->signIn('maa', 'hanako@example.com');
        $this->send('maa', 'GET', '/account')->assertOk()->assertSee(strtoupper($order));
        $this->assertStringContainsString('data-test="addresses-empty"', (string) $this->send('maa', 'GET', '/account/addresses')->getContent());
    }

    public function test_leaving_in_one_shop_does_not_touch_another(): void
    {
        $this->signIn('mab', 'hanako@example.com');
        $other = (string) $this->member;
        $this->send('mab', 'POST', '/account/addresses', ['address' => $this->home]);

        $this->member = null;
        $this->signIn('maa', 'hanako@example.com');
        $this->send('maa', 'POST', '/account/confirm', ['code' => $this->sendConfirm('leave'), 'next' => 'leave']);
        $this->send('maa', 'POST', '/account/leave')->assertRedirect('http://maa.localhost');

        $this->member = $other;
        $this->send('mab', 'GET', '/account')->assertOk();
        $this->inside('mab', fn () => $this->assertSame(1, CustomerAddress::query()->count()));
    }

    public function test_changing_the_email_proves_the_new_address_and_tells_the_old_one(): void
    {
        $this->order('maa', 'hanako@example.com');
        $this->signIn('maa', 'hanako@example.com');
        $here = (string) $this->member;
        $this->member = null;
        $this->signIn('maa', 'hanako@example.com', '198.51.100.31');
        $elsewhere = (string) $this->member;
        $this->member = $here;
        $this->ip = '127.0.0.1';

        // 再確認の前は変えられない（画面も送るのも）
        $this->send('maa', 'GET', '/account/email')->assertRedirect('http://maa.localhost/account/confirm?next=email');
        $this->send('maa', 'POST', '/account/email', ['email' => 'hana@example.net'])->assertRedirect('http://maa.localhost/account/confirm?next=email');
        $this->send('maa', 'POST', '/account/confirm', ['code' => $this->sendConfirm('email'), 'next' => 'email'])->assertRedirect('http://maa.localhost/account/email');

        // 形の違う・今と同じアドレスは断る
        $this->send('maa', 'POST', '/account/email', ['email' => 'not-an-email'])->assertRedirect('http://maa.localhost/account/email');
        $this->send('maa', 'POST', '/account/email', ['email' => 'HANAKO@example.com'])->assertRedirect('http://maa.localhost/account/email');

        // 新しいアドレスへコードを送る（前のアドレスへは送らない）。違うコードでは変わらない
        $this->send('maa', 'POST', '/account/email', ['email' => 'hana@example.net'])->assertRedirect('http://maa.localhost/account/email');
        $mail = Mail::sent(LoginCodeMail::class)->last();
        $this->assertTrue($mail->hasTo('hana@example.net'));
        $this->send('maa', 'POST', '/account/email/verify', ['code' => $mail->code === '000000' ? '000001' : '000000'])->assertRedirect('http://maa.localhost/account/email');
        $this->inside('maa', fn () => $this->assertSame('hanako@example.com', CustomerAccount::query()->whereNotNull('member_since')->sole()->email));

        // 合えば変わる。前のアドレスへ知らせ（新しいアドレスは載せない）、ほかの端末の印は切れ、この端末はそのまま
        Queue::fake([SendEmailChangedNotice::class]);
        $this->send('maa', 'POST', '/account/email/verify', ['code' => $mail->code])->assertRedirect('http://maa.localhost/account');
        Queue::assertPushed(SendEmailChangedNotice::class, function (SendEmailChangedNotice $job): bool {
            $this->assertStringNotContainsString('hanako', serialize($job));
            $this->inside('maa', function () use ($job): void {
                $job->handle(app(TenantCipher::class));
            });

            return true;
        });
        Mail::assertSent(EmailChangedMail::class, fn (EmailChangedMail $m): bool => $m->hasTo('hanako@example.com') && ! $m->hasTo('hana@example.net'));
        $this->send('maa', 'GET', '/account')->assertOk()->assertSee('hana@example.net');
        $this->member = $elsewhere;
        $this->send('maa', 'GET', '/account')->assertRedirect('http://maa.localhost/account/login');
        $this->inside('maa', function (): void {
            $account = CustomerAccount::query()->sole();
            $this->assertSame('hana@example.net', $account->email);
            $this->assertSame([], (array) DomainEvent::query()->where('type', 'customer_account.email_changed')->sole()->payload);
            // 注文は同じ台帳のまま
            $this->assertSame(1, Transaction::query()->where('customer_account_id', $account->id)->count());
        });

        // 前のアドレスで入ると、別の（新しい）会員になる。今までの注文は見えない
        $this->member = null;
        $this->signIn('maa', 'hanako@example.com', '198.51.100.32');
        $this->assertStringContainsString('data-test="account-orders-empty"', (string) $this->send('maa', 'GET', '/account')->getContent());
    }

    public function test_the_email_cannot_move_onto_another_record(): void
    {
        // 新しいアドレスに、すでにこの店の記録（ゲストの注文）がある: 持ち主を確かめても、勝手にはまとめない
        $this->order('maa', 'taro@example.com');
        $this->signIn('maa', 'hanako@example.com');
        $this->send('maa', 'POST', '/account/confirm', ['code' => $this->sendConfirm('email'), 'next' => 'email']);
        $this->send('maa', 'POST', '/account/email', ['email' => 'taro@example.com']);
        $code = Mail::sent(LoginCodeMail::class)->last()->code;
        $this->send('maa', 'POST', '/account/email/verify', ['code' => $code])->assertRedirect('http://maa.localhost/account/email');
        $this->assertStringContainsString('すでにこのショップのご注文や会員で使われています', (string) $this->send('maa', 'GET', '/account/email')->getContent());
        $this->inside('maa', function (): void {
            $this->assertSame(['hanako@example.com', 'taro@example.com'], CustomerAccount::query()->get()->map(fn (CustomerAccount $a): string => $a->email)->sort()->values()->all());
            $this->assertSame(0, DomainEvent::query()->where('type', 'customer_account.email_changed')->count());
        });
        Mail::assertNotSent(EmailChangedMail::class);
    }

    public function test_the_email_change_needs_a_fresh_confirmation_at_the_last_step_too(): void
    {
        // 再確認から 9 分で新しいアドレスへ送り、2 分後（再確認から 11 分）に入れる: 最後の一歩でも再確認が切れていれば変えない
        $this->signIn('maa', 'hanako@example.com');
        $this->send('maa', 'POST', '/account/confirm', ['code' => $this->sendConfirm('email'), 'next' => 'email']);
        $this->travel(9)->minutes();
        $this->send('maa', 'POST', '/account/email', ['email' => 'hana@example.net'])->assertRedirect('http://maa.localhost/account/email');
        $code = Mail::sent(LoginCodeMail::class)->last()->code;
        $this->travel(2)->minutes();
        $this->send('maa', 'POST', '/account/email/verify', ['code' => $code])->assertRedirect('http://maa.localhost/account/confirm?next=email');
        $this->inside('maa', fn () => $this->assertSame('hanako@example.com', CustomerAccount::query()->sole()->email));
    }

    public function test_orders_of_a_record_the_shop_merged_show_up_in_the_members_history(): void
    {
        // 店の人が「同じ人」とまとめたゲストの記録（仕事用のメール）の注文も、会員の履歴に並ぶ（中身だけ。ADR 0044 の 2）
        $work = $this->order('maa', 'hanako.work@example.com');
        $this->signIn('maa', 'hanako@example.com');
        $this->assertStringNotContainsString(strtoupper($work), (string) $this->send('maa', 'GET', '/account')->getContent());
        $this->inside('maa', function (): void {
            $member = CustomerAccount::query()->whereBlind('email', 'hanako@example.com')->firstOrFail();
            $guest = CustomerAccount::query()->whereBlind('email', 'hanako.work@example.com')->firstOrFail();
            app(CustomerMerges::class)->merge($member, $guest->id, 'operator:test');
        });
        $this->send('maa', 'GET', '/account')->assertOk()->assertSee(strtoupper($work));
    }

    public function test_a_record_the_shop_merged_cannot_sign_in_by_itself(): void
    {
        // まとめられたゲストの記録が後からコードで会員になると、「会員どうしはまとめない」が崩れる（攻撃テスト第 2 回、2026-11-03 の運営の判断で「ログインを断る」）
        $this->order('maa', 'hanako.work@example.com');
        $this->signIn('maa', 'hanako@example.com');
        $guestId = $this->inside('maa', function (): string {
            $member = CustomerAccount::query()->whereBlind('email', 'hanako@example.com')->firstOrFail();
            $guest = CustomerAccount::query()->whereBlind('email', 'hanako.work@example.com')->firstOrFail();
            app(CustomerMerges::class)->merge($member, $guest->id, 'operator:test');

            return $guest->id;
        });
        $this->member = null;

        $this->send('maa', 'POST', '/account/login', ['email' => 'hanako.work@example.com'])->assertRedirect('http://maa.localhost/account/login/code');
        $code = Mail::sent(LoginCodeMail::class)->last()->code;
        $refused = $this->send('maa', 'POST', '/account/login/code', ['code' => $code]);
        $refused->assertRedirect('http://maa.localhost/account/login');
        $this->assertNull($refused->getCookie(MemberCookie::NAME));
        $this->assertNull($this->member);
        $this->send('maa', 'GET', '/account/login')->assertOk()->assertSee('そちらのメールアドレスでログインしてください', false);
        // 同じコードはもう使えない（入れる画面からやり直し）
        $this->send('maa', 'POST', '/account/login/code', ['code' => $code])->assertRedirect('http://maa.localhost/account/login');
        $this->assertNull($this->member);
        $this->inside('maa', function () use ($guestId): void {
            $guest = CustomerAccount::query()->findOrFail($guestId);
            $this->assertNull($guest->member_since);
            $this->assertNotNull($guest->merged_into_id);
            $this->assertSame(0, CustomerSession::query()->where('customer_account_id', $guestId)->count());
            $this->assertFalse(DomainEvent::query()->where('type', 'customer_account.joined')->where('aggregate_id', $guestId)->exists());
        });

        // 店の人が分け直せば、その記録でも入れる（断るのはまとめられている間だけ）
        $this->inside('maa', function () use ($guestId): void {
            $member = CustomerAccount::query()->whereBlind('email', 'hanako@example.com')->firstOrFail();
            app(CustomerMerges::class)->unmerge($member, $guestId, 'operator:test');
        });
        $this->signIn('maa', 'hanako.work@example.com');
    }

    public function test_the_database_keeps_a_merged_record_from_becoming_a_member(): void
    {
        $this->order('maa', 'hanako.work@example.com');
        $this->inside('maa', function (): void {
            $member = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'storefront');
            $member->member_since = CarbonImmutable::now();
            $member->save();
            $guest = CustomerAccount::query()->whereBlind('email', 'hanako.work@example.com')->firstOrFail();
            app(CustomerMerges::class)->merge($member, $guest->id, 'operator:test');

            try {
                DB::transaction(static fn () => CustomerAccount::query()->whereKey($guest->id)->update(['member_since' => now()]));
                $this->fail('まとめられた記録が会員になった');
            } catch (QueryException $e) {
                $this->assertStringContainsString('customer_accounts_merged_not_member', $e->getMessage());
            }
        });
    }

    private function signIn(string $shop, string $email, ?string $ip = null): void
    {
        $this->ip = $ip ?? '127.0.0.1';
        $this->send($shop, 'POST', '/account/login', ['email' => $email])->assertRedirect("http://{$shop}.localhost/account/login/code");
        $code = Mail::sent(LoginCodeMail::class)->last()->code;
        $this->send($shop, 'POST', '/account/login/code', ['code' => $code])->assertRedirect("http://{$shop}.localhost/account");
        $this->assertNotNull($this->member);
    }

    private function sendConfirm(string $next): string
    {
        $count = count(Mail::sent(LoginCodeMail::class));
        $expected = $next === 'email' ? 'email' : 'leave';
        $this->send('maa', 'POST', '/account/confirm/send', ['next' => $next])->assertRedirect("http://maa.localhost/account/confirm?next={$expected}");
        $this->assertCount($count + 1, Mail::sent(LoginCodeMail::class));

        return Mail::sent(LoginCodeMail::class)->last()->code;
    }

    private function putInCart(string $shop): void
    {
        $variant = $this->inside($shop, function (): string {
            $seller = Seller::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 3);

            return $listing->id;
        });
        $this->send($shop, 'POST', '/cart/items', ['listing_id' => $variant, 'quantity' => 1]);
        $this->assertNotNull($this->cart, 'カートに入らなかった');
    }

    private function order(string $shop, string $email): string
    {
        return $this->inside($shop, function () use ($email): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'キャップ', 'unit_amount' => 3300, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => $email]);
            $account = app(CustomerAccounts::class)->forEmail($email, 'storefront');
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'storefront', customerAccount: $account);
            CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);

            return $sale->id;
        });
    }

    /**
     * 会員の印とカートの Cookie を持ち越して送る。要求ごとにログインの仕組みを作り直す（本番の php-fpm と同じ）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $shop, string $method, string $path, array $data = []): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $server = ['REMOTE_ADDR' => $this->ip];
        $response = $method === 'GET' ? $this->withServerVariables($server)->get($url) : $this->withServerVariables($server)->post($url, $data);
        tenancy()->end();

        foreach ([MemberCookie::NAME => 'member', CartCookie::NAME => 'cart'] as $name => $property) {
            $cookie = $response->getCookie($name);
            if ($cookie !== null) {
                $this->{$property} = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
            }
        }

        return $response;
    }
}
