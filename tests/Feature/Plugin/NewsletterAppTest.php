<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\OperatorAccess;
use App\Core\Crypto\BlindIndex;
use App\Core\Event\Models\DomainEvent;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Plugin\Services\AdminPages;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Carbon\CarbonImmutable;
use EcSugar\PluginNewsletter\CampaignStatus;
use EcSugar\PluginNewsletter\Mail\ConfirmMail;
use EcSugar\PluginNewsletter\Mail\NewsletterMail;
use EcSugar\PluginNewsletter\Models\NewsletterCampaign;
use EcSugar\PluginNewsletter\Models\NewsletterDelivery;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use EcSugar\PluginNewsletter\NewsletterSubscribers;
use EcSugar\PluginNewsletter\SubscriberStatus;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「メルマガ・一斉メール」。受付（ゲストは確かめのメールで・会員はすぐ）→ 管理画面で書く・試す・配信（直前の再確認）→ 1 分の上限で少しずつ送る。
 * 法の表示（送り主・住所・問い合わせ先・停止のリンク）とワンクリックの停止のヘッダー・停止の合い言葉はその人だけ・予約・止める・権限・店の境界・暗号の列・使わない店
 */
final class NewsletterAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BASE = '/api/tenants/nla/apps/newsletter/records';

    private const array LEGAL = [
        ['label' => '販売業者', 'value' => '株式会社かくざとう'],
        ['label' => '運営統括責任者', 'value' => '山田 太郎'],
        ['label' => '所在地', 'value' => '東京都渋谷区神宮前 1-2-3'],
        ['label' => '電話番号', 'value' => '03-1234-5678'],
        ['label' => 'メールアドレス', 'value' => 'shop@example.jp'],
    ];

    private ?string $member = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->configureAdminHost();
        $this->tenant('nla', 'かくざとう');
        $this->inside('nla', function (): void {
            app(PluginStates::class)->set('newsletter', true, 'test');
            app(ShopSettingsService::class)->setLegal(['items' => self::LEGAL], 'test');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_a_guest_is_added_only_after_pressing_the_button_in_the_confirmation_mail(): void
    {
        $this->shop('GET', '/p/newsletter')->assertOk()->assertSee('data-test="newsletter-email"', false);
        $this->shop('POST', '/p/newsletter', ['email' => ' Hanako@Example.com '])->assertRedirect('http://nla.localhost/p/newsletter/sent');
        $this->shop('GET', '/p/newsletter/sent')->assertOk()->assertSee('登録の確認メールをお送りしました');

        $mail = $this->lastConfirm();
        $this->assertTrue($mail->hasTo('Hanako@Example.com'));
        $path = (string) parse_url($mail->confirmUrl, PHP_URL_PATH);
        $this->assertMatchesRegularExpression('#\A/p/newsletter/confirm/[A-Za-z0-9_-]{43}\z#', $path);

        // 確かめ待ち。合い言葉は送ったら表から消え、ハッシュだけが残る。同意の日時と経路は残る
        $row = $this->subscriber();
        $this->assertSame(SubscriberStatus::Pending, $row->status);
        $this->assertNull($row->mail_token);
        $this->assertSame(hash('sha256', basename($path)), $row->token_hash);
        $this->assertSame('form', $row->source);
        $this->assertTrue(CarbonImmutable::parse($row->consented_at)->lte(CarbonImmutable::now()));

        // 確かめる前の人には配信しない
        $this->assertSame(0, $this->inside('nla', fn (): int => NewsletterSubscriber::query()->where('status', 'active')->count()));

        // リンクを開いただけでは変えない（メールのリンクを先に開く仕組みで勝手に確かめない）。押すと届けるようになる
        $this->shop('GET', $path)->assertOk()->assertSee('data-test="newsletter-confirm"', false)->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag');
        $this->assertSame(SubscriberStatus::Pending, $this->subscriber()->status);
        $this->shop('POST', $path)->assertOk()->assertSee('届くようになりました');
        $row = $this->subscriber();
        $this->assertSame(SubscriberStatus::Active, $row->status);
        $this->assertNotNull($row->confirmed_at);
        $this->shop('POST', $path)->assertOk()->assertSee('登録はすでに完了しています');

        // 期限を過ぎた確かめのリンクは使えず、片付けで消える
        $this->shop('POST', '/p/newsletter', ['email' => 'taro@example.com'], '198.51.100.7');
        $late = (string) parse_url($this->lastConfirm()->confirmUrl, PHP_URL_PATH);
        $this->travel(NewsletterSubscribers::CONFIRM_HOURS + 1)->hours();
        $this->shop('POST', $late)->assertOk()->assertSee('このリンクは使えません');
        $this->artisan('newsletter:run')->assertSuccessful();
        $this->assertSame(1, $this->inside('nla', fn (): int => NewsletterSubscriber::query()->count()));
    }

    public function test_guests_get_the_same_answer_and_sending_is_limited(): void
    {
        $this->confirmedGuest('hanako@example.com');
        Mail::assertSent(ConfirmMail::class, 1);

        // もう届けている人のアドレスを入れても同じ答えで、何も送らない（購読しているかを、ほかの人が確かめられない）
        $this->shop('POST', '/p/newsletter', ['email' => 'hanako@example.com'], '198.51.100.9')->assertRedirect('http://nla.localhost/p/newsletter/sent');
        Mail::assertSent(ConfirmMail::class, 1);
        $this->assertSame(SubscriberStatus::Active, $this->subscriber()->status);

        // 同じメール × 同じ接続元は 1 時間に 5 回まで（超えても同じ答えで、送らない）
        for ($i = 0; $i < NewsletterSubscribers::PAIR_HOURLY + 2; $i++) {
            $this->shop('POST', '/p/newsletter', ['email' => 'jiro@example.com'], '198.51.100.20')->assertRedirect('http://nla.localhost/p/newsletter/sent');
        }

        Mail::assertSent(ConfirmMail::class, 1 + NewsletterSubscribers::PAIR_HOURLY);

        // 形の違うメールは断る
        $this->shop('POST', '/p/newsletter', ['email' => 'not-an-email'], '198.51.100.30')->assertSessionHasErrors('email');
    }

    public function test_members_choose_in_their_page_without_a_confirmation_mail_and_leaving_stops_it(): void
    {
        $this->login('hanako@example.com');
        // 受付の画面も、会員にはマイページの切り替えを出す
        $this->shop('GET', '/p/newsletter')->assertOk()->assertSee('data-test="newsletter-toggle"', false)->assertDontSee('data-test="newsletter-email"', false);
        $this->assertStringContainsString('/p/newsletter/account', (string) $this->shop('GET', '/account')->getContent());

        $this->shop('POST', '/p/newsletter/account', ['action' => 'subscribe'])->assertRedirect('http://nla.localhost/p/newsletter/account');
        Mail::assertNotSent(ConfirmMail::class);
        $row = $this->subscriber();
        $this->assertSame(SubscriberStatus::Active, $row->status);
        $this->assertSame('account', $row->source);
        $this->assertNotNull($row->customer_account_id);
        $this->shop('GET', '/p/newsletter/account')->assertOk()->assertSee('お送りしています');

        // マイページで止める・また選ぶ
        $this->shop('POST', '/p/newsletter/account', ['action' => 'unsubscribe']);
        $row = $this->subscriber();
        $this->assertSame(SubscriberStatus::Unsubscribed, $row->status);
        $this->assertSame('account', $row->unsubscribed_via);
        $this->shop('POST', '/p/newsletter/account', ['action' => 'subscribe']);
        $this->assertSame(SubscriberStatus::Active, $this->subscriber()->status);

        // 退会すると止める
        $this->inside('nla', fn () => app(CustomerAccounts::class)->leave(CustomerAccount::query()->sole(), 'test'));
        $row = $this->subscriber();
        $this->assertSame(SubscriberStatus::Unsubscribed, $row->status);
        $this->assertSame('leave', $row->unsubscribed_via);

        // ログインしていない人のマイページはログインへ
        $this->member = null;
        $this->shop('GET', '/p/newsletter/account')->assertRedirect();
        $this->shop('POST', '/p/newsletter/account', ['action' => 'subscribe'])->assertRedirect();
    }

    public function test_the_shop_writes_tests_and_sends_with_the_legal_footer_and_one_click_headers_a_little_each_minute(): void
    {
        [$owner, $key] = $this->enrolledOperator('owner@example.com');
        $this->inside('nla', fn () => app(PluginSettings::class)->update('newsletter', ['per_minute' => 'm5'], 'test'));

        foreach (range(1, 7) as $n) {
            $this->confirmedGuest("guest{$n}@example.com", "198.51.100.{$n}");
        }

        // 確かめていない人・止めた人には送らない
        $this->shop('POST', '/p/newsletter', ['email' => 'pending@example.com'], '198.51.100.50');
        $this->inside('nla', function (): void {
            $stopped = NewsletterSubscriber::query()->where('email_index', BlindIndex::of('email', 'guest7@example.com'))->sole();
            $stopped->forceFill(['status' => SubscriberStatus::Unsubscribed, 'unsubscribed_at' => now(), 'unsubscribed_via' => 'link'])->save();
        });

        // 書く（文だけ。< や & はそのまま文字として届く）
        $id = $this->create(['subject' => '冬のセールのお知らせ', 'body' => "いつもありがとうございます。\n<b>A&B</b> の新作が入りました。"]);
        $this->send('GET', self::BASE.'/'.$id)->assertOk()->assertJsonPath('record.facts.5.value', 6)
            ->assertJsonPath('record.facts.7.value', ['label' => 'そろっています', 'tone' => 'good']);

        // 試しに自分へ送る（操作した人のメールへ 1 通。題に【試し】）
        $this->send('POST', self::BASE.'/'.$id.'/actions/test')->assertOk();
        Mail::assertSent(NewsletterMail::class, static fn (NewsletterMail $m): bool => $m->test && $m->hasTo('owner@example.com'));
        $this->assertSame(1, $this->inside('nla', fn (): int => DomainEvent::query()->where('type', 'newsletter.campaign_tested')->count()));

        // 配信は取り消せないので、直前の再確認の前は 423
        $this->send('POST', self::BASE.'/'.$id.'/actions/send_now')->assertStatus(423);
        $this->reconfirm($owner, $key);
        $this->send('POST', self::BASE.'/'.$id.'/actions/send_now')->assertOk()->assertJsonPath('record.facts.0.value.label', '配信中');
        $started = $this->inside('nla', fn () => DomainEvent::query()->where('type', 'newsletter.campaign_started')->sole());
        $this->assertSame($owner->actor(), $started->actor);
        $this->assertEquals(['recipients' => 6, 'scheduled' => false], $started->payload);

        // 配信を始めた後に確かめた人には、この配信は送らない
        $this->travel(1)->seconds();
        $this->confirmedGuest('late@example.com', '198.51.100.60');

        // 1 分に 5 通まで。次の 1 分で残りの 1 通。終わったら配信済み
        $this->artisan('newsletter:run')->assertSuccessful();
        $this->assertSame(5, $this->campaignMails());
        $this->assertSame(CampaignStatus::Sending, $this->campaign($id)->status);
        $this->travel(1)->minutes();
        $this->artisan('newsletter:run')->assertSuccessful();
        $this->assertSame(6, $this->campaignMails());
        $this->assertSame(CampaignStatus::Sent, $this->campaign($id)->status);
        $this->artisan('newsletter:run')->assertSuccessful();
        $this->assertSame(6, $this->campaignMails());
        foreach (['late@example.com', 'guest7@example.com', 'pending@example.com'] as $nobody) {
            Mail::assertNotSent(NewsletterMail::class, static fn (NewsletterMail $m): bool => $m->hasTo($nobody));
        }

        // 法の表示: 送り主の名前・住所・問い合わせ先・停止のリンク。ヘッダーにワンクリックの停止
        $mail = Mail::sent(NewsletterMail::class, static fn (NewsletterMail $m): bool => ! $m->test)->first();
        $this->assertInstanceOf(NewsletterMail::class, $mail);
        $text = $mail->render();
        foreach (['株式会社かくざとう', '東京都渋谷区神宮前 1-2-3', '電話番号: 03-1234-5678', 'メールアドレス: shop@example.jp', '配信の停止', $mail->unsubscribeUrl, '<b>A&B</b>'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('山田 太郎', $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertMatchesRegularExpression('#\Ahttp://nla\.localhost/p/newsletter/unsubscribe/[A-Za-z0-9_-]{43}\z#', $mail->unsubscribeUrl);
        $headers = $mail->headers()->text;
        $this->assertSame('<'.$mail->unsubscribeUrl.'>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertSame('冬のセールのお知らせ', $mail->envelope()->subject);

        // 結果の数。届けた人のメールのアドレスは管理画面のどこにも出ない
        $list = $this->send('GET', self::BASE.'?filter=done')->assertOk();
        $list->assertJsonPath('rows.0.cells.sent', 6)->assertJsonPath('rows.0.cells.failed', 0);
        $record = $this->send('GET', self::BASE.'/'.$id)->assertOk();
        $this->assertStringNotContainsString('@example.com', (string) $list->getContent().$record->getContent());
        $this->assertEquals(['sent' => 6, 'failed' => 0, 'skipped' => 0, 'waiting' => 0, 'unsubscribed' => 0], $this->inside('nla', fn () => DomainEvent::query()->where('type', 'newsletter.campaign_finished')->sole()->payload));
        // 送った記録は送り直さない（キューにも記録にもメールを載せない）
        $this->assertSame(6, $this->inside('nla', fn (): int => NewsletterDelivery::query()->where('status', 'sent')->whereNotNull('token_hash')->count()));
    }

    public function test_a_failed_send_is_logged_with_the_exception_type_only(): void
    {
        [$owner, $key] = $this->enrolledOperator('owner@example.com');
        $this->confirmedGuest('guest1@example.com');
        $id = $this->create(['subject' => 'お知らせ', 'body' => '本文']);
        $this->reconfirm($owner, $key);
        $this->send('POST', self::BASE.'/'.$id.'/actions/send_now')->assertOk();

        // 送る仕組みの例外の文には宛先が入りうる: 記録には例外の種類だけを残し、文（宛先）は残さない（ADR 0010）
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('550 5.1.1 <guest1@example.com>: Recipient address rejected'));
        Exceptions::fake();
        $log = Log::spy();
        $this->artisan('newsletter:run')->assertSuccessful();

        Exceptions::assertNothingReported();
        $log->shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $message === '一斉メールを送れなかった'
            && ($context['error'] ?? null) === \RuntimeException::class && ! str_contains((string) json_encode($context, JSON_UNESCAPED_UNICODE), '@'));
        $this->assertSame(1, $this->inside('nla', fn (): int => NewsletterDelivery::query()->where('status', 'failed')->count()));
    }

    public function test_one_click_and_the_link_stop_only_that_person_and_work_even_while_the_shop_is_preparing(): void
    {
        [$owner, $key] = $this->enrolledOperator();
        $this->reconfirm($owner, $key);
        $this->confirmedGuest('hanako@example.com', '198.51.100.1');
        $this->confirmedGuest('taro@example.com', '198.51.100.2');
        $id = $this->create(['subject' => 'お知らせ', 'body' => '本文']);
        $this->send('POST', self::BASE.'/'.$id.'/actions/send_now')->assertOk();
        $this->artisan('newsletter:run')->assertSuccessful();
        $hanako = $this->unsubscribePath('hanako@example.com');
        $taro = $this->unsubscribePath('taro@example.com');

        // ショップが準備中に戻っても、停止のリンクは使える（法の受信拒否）
        $this->inside('nla', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'test'));

        // 開いただけでは止めない。メールのソフトのワンクリック（POST・List-Unsubscribe=One-Click）で止まる
        $this->shop('GET', $hanako)->assertOk()->assertSee('data-test="newsletter-unsubscribe"', false)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(SubscriberStatus::Active, $this->subscriber('hanako@example.com')->status);
        $this->shop('POST', $hanako, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('配信を停止しました');
        $row = $this->subscriber('hanako@example.com');
        $this->assertSame(SubscriberStatus::Unsubscribed, $row->status);
        $this->assertSame('one_click', $row->unsubscribed_via);
        $this->assertSame(SubscriberStatus::Active, $this->subscriber('taro@example.com')->status);
        $this->shop('POST', $hanako)->assertOk()->assertSee('すでに停止しています');

        // 画面のボタン（link）。推測した合い言葉は何も変えない
        $this->shop('POST', '/p/newsletter/unsubscribe/'.str_repeat('A', 43))->assertOk()->assertSee('このリンクは使えません');
        $this->assertContains($this->shop('POST', '/p/newsletter/unsubscribe/short')->status(), [404, 405]);
        $this->assertSame(SubscriberStatus::Active, $this->subscriber('taro@example.com')->status);
        $this->shop('POST', $taro)->assertOk();
        $this->assertSame('link', $this->subscriber('taro@example.com')->unsubscribed_via);

        // 配信ごとの「止めた人」に数える。止めた人の同意の記録は残し、1 か月を過ぎたらメールを消す
        $this->send('GET', self::BASE.'/'.$id)->assertOk()->assertJsonPath('record.facts.9.value', 2);
        $this->travel(NewsletterSubscribers::RETAIN_DAYS + 1)->days();
        $this->inside('nla', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, 'test'));
        $this->artisan('newsletter:run')->assertSuccessful();
        $this->inside('nla', function (): void {
            $this->assertSame(0, NewsletterSubscriber::query()->whereNotNull('email_index')->count());
            $this->assertSame(2, NewsletterSubscriber::query()->where('status', 'unsubscribed')->count());
        });
    }

    public function test_a_schedule_starts_at_its_time_and_is_held_back_without_the_sender_details(): void
    {
        [$owner, $key] = $this->enrolledOperator();
        $this->reconfirm($owner, $key);
        $this->confirmedGuest('hanako@example.com');
        $at = CarbonImmutable::now('Asia/Tokyo')->addHours(2)->startOfMinute();

        // 日時の形が違えば断る。予約は日時を入れた下書きだけ
        $this->send('POST', self::BASE, ['values' => ['subject' => '予約', 'body' => '本文', 'scheduled_at' => '来週の月曜']])->assertUnprocessable();
        $plain = $this->create(['subject' => '日時なし', 'body' => '本文']);
        $this->assertNotContains('schedule', array_column($this->send('GET', self::BASE.'/'.$plain)->json('record.actions'), 'key'));
        $id = $this->create(['subject' => '予約', 'body' => '本文', 'scheduled_at' => $at->format('Y-m-d H:i')]);
        $this->send('POST', self::BASE.'/'.$id.'/actions/schedule')->assertOk()->assertJsonPath('record.facts.0.value.label', '予約');
        // 予約した配信はもう直せない
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['subject' => '書き換え']])->assertNotFound();

        $this->artisan('newsletter:run')->assertSuccessful();
        Mail::assertNotSent(NewsletterMail::class);
        $this->travel(2)->hours();
        $this->artisan('newsletter:run')->assertSuccessful();
        Mail::assertSent(NewsletterMail::class, 1);
        $this->assertSame('system:newsletter', $this->inside('nla', fn () => DomainEvent::query()->where('type', 'newsletter.campaign_started')->sole()->actor));

        // 送り主の表示（特商法の表記）が足りなければ、配信も予約もできない。予約の日時が来た時に足りなければ下書きに戻し、ホームのやることに出す
        $this->reconfirm($owner, $key);
        $next = $this->create(['subject' => '次の予約', 'body' => '本文', 'scheduled_at' => CarbonImmutable::now('Asia/Tokyo')->addHour()->format('Y-m-d H:i')]);
        $this->send('POST', self::BASE.'/'.$next.'/actions/schedule')->assertOk();
        $this->inside('nla', fn () => app(ShopSettingsService::class)->setLegal(['items' => [['label' => '販売業者', 'value' => '株式会社かくざとう']]], 'test'));
        $this->send('POST', self::BASE.'/'.$plain.'/actions/send_now')->assertStatus(409)->assertJsonPath('user_message', '配信のメールには送り主の表示が要ります（特定電子メール法）。設定の「特商法の表記」に「所在地」「電話番号かメールアドレス」を書いてから送ってください。');
        $this->travel(2)->hours();
        $this->artisan('newsletter:run')->assertSuccessful();
        $this->assertSame(CampaignStatus::Draft, $this->campaign($next)->status);
        Mail::assertSent(NewsletterMail::class, 1);
        $attention = $this->inside('nla', fn (): array => app(AdminPages::class)->attention());
        $this->assertSame([['plugin' => 'newsletter', 'filter' => 'draft', 'count' => 1]], array_map(static fn (array $a): array => ['plugin' => $a['plugin'], 'filter' => $a['filter'], 'count' => $a['count']], $attention));
    }

    public function test_staff_who_only_view_cannot_write_or_send_and_another_shop_sees_nothing(): void
    {
        $this->tenant('nlb');
        $this->inside('nlb', fn () => app(PluginStates::class)->set('newsletter', true, 'test'));
        [$owner, $key] = $this->enrolledOperator();
        $this->reconfirm($owner, $key);
        $this->confirmedGuest('hanako@example.com');
        $id = $this->create(['subject' => 'A の店のお知らせ', 'body' => '本文']);

        // B の店の経路から A の店の配信は無いのと同じ。A の店の停止のリンクは B の店では使えない
        $other = '/api/tenants/nlb/apps/newsletter/records';
        $this->send('GET', $other)->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', "{$other}/{$id}")->assertNotFound();
        $this->send('POST', "{$other}/{$id}/actions/send_now")->assertNotFound();
        $this->send('POST', self::BASE.'/'.$id.'/actions/send_now')->assertOk();
        $this->artisan('newsletter:run')->assertSuccessful();
        $path = $this->unsubscribePath('hanako@example.com');
        $this->shop('POST', $path, [], null, 'nlb')->assertOk()->assertSee('このリンクは使えません');
        $this->assertSame(SubscriberStatus::Active, $this->subscriber('hanako@example.com')->status);

        // 閲覧の段階の人は見られるが、書く・試す・配信はできない
        $draft = $this->create(['subject' => '下書き', 'body' => '本文']);
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'nla', 'read', 'test');
        $this->send('GET', self::BASE.'/'.$draft)->assertOk();
        $this->send('POST', self::BASE, ['values' => ['subject' => 'x', 'body' => 'y']])->assertForbidden();
        $this->send('PUT', self::BASE.'/'.$draft, ['values' => ['subject' => 'x']])->assertForbidden();
        $this->send('POST', self::BASE.'/'.$draft.'/actions/test')->assertForbidden();
        $this->send('POST', self::BASE.'/'.$draft.'/actions/send_now')->assertForbidden();
        $this->assertSame(CampaignStatus::Draft, $this->campaign($draft)->status);
    }

    public function test_the_email_is_encrypted_and_subscribers_leave_no_events_and_an_unused_shop_shows_nothing(): void
    {
        $this->confirmedGuest('hanako@example.com');

        $this->inside('nla', function (): void {
            $raw = (array) DB::table('newsletter_subscribers')->sole();
            $this->assertStringNotContainsString('hanako', (string) json_encode($raw));
            $this->assertSame(BlindIndex::of('email', 'HANAKO@example.com'), $raw['email_index']);
            $this->assertSame(0, DomainEvent::query()->where('type', 'like', 'newsletter.%')->count());
        });

        // 使わない店では、画面も管理画面も無い（表は残る）
        $this->inside('nla', fn () => app(PluginStates::class)->set('newsletter', false, 'test'));
        $this->shop('GET', '/p/newsletter')->assertNotFound();
        $this->enrolledOperator();
        $this->send('GET', self::BASE)->assertNotFound();
        $this->artisan('newsletter:run')->assertSuccessful();
    }

    /**
     * 受付の画面から申し込み、確かめのメールのボタンを押す
     */
    private function confirmedGuest(string $email, string $ip = '198.51.100.1'): void
    {
        $this->shop('POST', '/p/newsletter', ['email' => $email], $ip);
        $mail = Mail::sent(ConfirmMail::class, static fn (ConfirmMail $m): bool => $m->hasTo($email))->last();
        $this->assertInstanceOf(ConfirmMail::class, $mail);
        $this->shop('POST', (string) parse_url($mail->confirmUrl, PHP_URL_PATH), [], $ip)->assertOk();
    }

    private function campaignMails(): int
    {
        return Mail::sent(NewsletterMail::class, static fn (NewsletterMail $m): bool => ! $m->test)->count();
    }

    private function unsubscribePath(string $email): string
    {
        $mail = Mail::sent(NewsletterMail::class, static fn (NewsletterMail $m): bool => $m->hasTo($email))->last();
        $this->assertInstanceOf(NewsletterMail::class, $mail);

        return (string) parse_url($mail->unsubscribeUrl, PHP_URL_PATH);
    }

    private function lastConfirm(): ConfirmMail
    {
        $mail = Mail::sent(ConfirmMail::class)->last();
        $this->assertInstanceOf(ConfirmMail::class, $mail);

        return $mail;
    }

    /**
     * 購読の行を、店の中で読んだ値にする（日時は店の DB の接続で読むので、文字にして外へ出す）
     *
     * @return object{status: SubscriberStatus, source: string, token_hash: ?string, mail_token: ?string, customer_account_id: ?string, unsubscribed_via: ?string, consented_at: string, confirmed_at: ?string}
     */
    private function subscriber(string $email = 'hanako@example.com'): object
    {
        return $this->inside('nla', function () use ($email): object {
            $row = NewsletterSubscriber::query()->where('email_index', BlindIndex::of('email', $email))->sole();

            return (object) [
                'status' => $row->status, 'source' => $row->source, 'token_hash' => $row->token_hash, 'mail_token' => $row->mail_token,
                'customer_account_id' => $row->customer_account_id, 'unsubscribed_via' => $row->unsubscribed_via,
                'consented_at' => $row->consented_at->toIso8601String(), 'confirmed_at' => $row->confirmed_at?->toIso8601String(),
            ];
        });
    }

    private function campaign(string $id): NewsletterCampaign
    {
        return $this->inside('nla', fn () => NewsletterCampaign::query()->findOrFail($id));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function create(array $values): string
    {
        return (string) $this->send('POST', self::BASE, ['values' => $values])->assertCreated()->json('id');
    }

    private function login(string $email): void
    {
        $this->shop('POST', '/account/login', ['email' => $email]);
        $this->shop('POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code]);
    }

    /**
     * ショップへ送る（会員の印を持ち回る。送るたびに 7 秒進める〔人の速さ。1 分の回数の上限にテスト自身が当たらないように〕）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = [], ?string $ip = null, string $shop = 'nla'): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $this->serverVariables = $ip === null ? [] : ['REMOTE_ADDR' => $ip];
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);

        tenancy()->end();
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }
}
