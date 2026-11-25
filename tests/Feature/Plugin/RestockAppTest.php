<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Crypto\BlindIndex;
use App\Core\Inventory\Enums\AdjustmentReason;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\VariantService;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Carbon\CarbonImmutable;
use EcSugar\PluginRestock\Mail\RestockMail;
use EcSugar\PluginRestock\Models\RestockRequest;
use EcSugar\PluginRestock\RestockNotifier;
use EcSugar\PluginRestock\RestockRequests;
use EcSugar\PluginRestock\RestockStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「再入荷のお知らせ」。売り切れの種類にメールを登録（ゲストは確かめのメールのリンクで有効、会員はすぐ）→ 入荷したら 1 回だけ知らせる。
 * 確かめていない登録には送らない・取り消し・二重の登録・ほかの店・回数の上限・暗号の列・入荷の時の競合（2 回送らない）・管理画面は数だけ
 */
final class RestockAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private ?string $member = null;

    private string $mug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->configureAdminHost();
        $this->tenant('rsa');
        $this->mug = $this->inside('rsa', function (): string {
            app(PluginStates::class)->set('restock', true, 'test');

            return $this->listing('白いマグカップ');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_product_page_offers_it_only_for_sold_out_products_and_only_when_the_app_is_used(): void
    {
        // 売り切れの商品: 「売り切れ」の下に「入荷したら知らせる」（売り切れの時にも出す印のある買い方だけ。定期便は出さない）。
        // 定期便の App が入っていない木（公開用の 1.0。ADR 0098）では、定期便の所だけ確かめない
        $subscription = $this->hasApp('subscription');

        if ($subscription) {
            // 定期便の約束を作る所は SubscriptionPlanParts（許す一覧の Subscription* で公開の木に入らない）
            if (! class_exists(SubscriptionPlanParts::class)) {
                $this->missingPart('tests/Feature/Plugin/SubscriptionPlanParts.php');
            }

            $plans = new SubscriptionPlanParts;
            $this->inside('rsa', function () use ($plans): void {
                app(PluginStates::class)->set('subscription', true, 'test');
                $plans->monthly($this->mug);
            });
        }

        $html = $this->page("/products/{$this->mug}");
        $this->assertStringContainsString('data-test="purchase-option-restock"', $html);
        $this->assertStringContainsString("/p/restock/{$this->mug}", $html);
        $this->assertStringNotContainsString('purchase-option-subscription', $html);

        // 4 つの公式テーマのどれでも、売り切れの枝に置いてある
        foreach (['kinari', 'fashion', 'botanica', 'default'] as $theme) {
            $this->tenants[0]->forceFill(['theme' => $theme])->save();
            $this->assertStringContainsString('data-test="purchase-option-restock"', $this->page("/products/{$this->mug}"), $theme);
        }

        // 在庫があれば出さない（定期便は出る）
        $this->inside('rsa', fn () => app(StockService::class)->receive(Listing::query()->findOrFail($this->mug), Location::query()->firstOrFail(), 3));
        $html = $this->page("/products/{$this->mug}");
        $this->assertStringNotContainsString('purchase-option-restock', $html);

        if ($subscription) {
            $this->assertStringContainsString('purchase-option-subscription', $html);
        }

        $this->shop('GET', "/p/restock/{$this->mug}")->assertOk()->assertSee('この商品はいま買えます');

        // 使わない店では出さず、画面も 404
        $this->inside('rsa', function (): void {
            app(PluginStates::class)->set('restock', false, 'test');
            app(StockService::class)->adjust(Listing::query()->findOrFail($this->mug), Location::query()->firstOrFail(), -3);
        });
        $this->assertStringNotContainsString('purchase-option-restock', $this->page("/products/{$this->mug}"));
        $this->shop('GET', "/p/restock/{$this->mug}")->assertNotFound();
    }

    public function test_a_guest_confirms_by_the_mail_link_and_is_told_once_when_the_stock_comes_back(): void
    {
        $this->shop('GET', "/p/restock/{$this->mug}")->assertOk()->assertSee('data-test="restock-email"', false);
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $this->sole($this->mug), 'email' => ' Hanako@Example.com '])
            ->assertRedirect("http://rsa.localhost/p/restock/{$this->mug}/sent");
        $this->shop('GET', "/p/restock/{$this->mug}/sent")->assertOk()->assertSee('確認メールをお送りしました');

        $confirm = $this->lastMail('confirm');
        $this->assertTrue($confirm->hasTo('Hanako@Example.com'));
        $this->assertSame('白いマグカップ', $confirm->facts['title']);
        $path = (string) parse_url((string) $confirm->facts['confirm'], PHP_URL_PATH);
        $this->assertMatchesRegularExpression('#\A/p/restock/confirm/[A-Za-z0-9_-]{43}\z#', $path);
        // 合い言葉は送ったら表から消え、ハッシュだけが残る
        $row = $this->inside('rsa', fn () => RestockRequest::query()->sole());
        $this->assertSame(RestockStatus::Pending, $row->status);
        $this->assertNull($row->mail_token);
        $this->assertSame(hash('sha256', basename($path)), $row->token_hash);

        // 確かめる前に入荷しても、送らない（入荷の知らせでも、定期の見直しでも）
        $this->receive($this->mug, 2);
        $variant = $this->sole($this->mug);
        $this->assertSame(0, $this->inside('rsa', fn (): int => app(RestockNotifier::class)->notify($variant)));
        $this->assertSame(0, $this->mails('restocked'));
        $this->inside('rsa', fn () => app(StockService::class)->adjust(Listing::query()->findOrFail($this->mug), Location::query()->firstOrFail(), -2));

        // リンクを開いただけでは確かめない（メールのリンクを先に開く仕組みで勝手に有効にしない）。押すと有効
        $this->shop('GET', $path)->assertOk()->assertSee('data-test="restock-confirm"', false)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(RestockStatus::Pending, $this->inside('rsa', fn () => RestockRequest::query()->sole()->status));
        $this->shop('POST', $path)->assertOk()->assertSee('登録が完了しました');
        $this->assertSame(RestockStatus::Waiting, $this->inside('rsa', fn () => RestockRequest::query()->sole()->status));
        $this->shop('POST', $path)->assertOk()->assertSee('すでに完了しています');

        // 入荷（入庫）で 1 回だけ知らせる。知らせた登録からはメールが消える。もう一度入荷しても送らない
        $this->receive($this->mug, 1);
        $this->assertSame(1, $this->mails('restocked'));
        $restocked = $this->lastMail('restocked');
        $this->assertTrue($restocked->hasTo('Hanako@Example.com'));
        $this->assertSame("http://rsa.localhost/products/{$this->mug}", $restocked->facts['product']);
        $this->assertStringContainsString('宣伝のメールではありません', $restocked->render());
        $row = $this->inside('rsa', fn () => RestockRequest::query()->sole());
        $this->assertSame(RestockStatus::Notified, $row->status);
        $this->assertNull($row->email);
        $this->assertNull($row->email_index);
        $this->receive($this->mug, 1);
        $variant = $this->sole($this->mug);
        $this->assertSame(0, $this->inside('rsa', fn (): int => app(RestockNotifier::class)->notify($variant)));
        $this->assertSame(1, $this->mails('restocked'));
        $this->shop('GET', $path)->assertOk()->assertSee('すでにお送りしました');
    }

    public function test_the_email_is_stored_encrypted_and_found_only_by_the_blind_index(): void
    {
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $this->sole($this->mug), 'email' => 'hanako@example.com']);

        $this->inside('rsa', function (): void {
            $raw = (array) DB::table('restock_requests')->sole();
            $this->assertStringNotContainsString('hanako', (string) json_encode($raw));
            $this->assertSame(BlindIndex::of('email', 'HANAKO@example.com'), $raw['email_index']);
            $this->assertSame('hanako@example.com', RestockRequest::query()->sole()->email);
            // 記録（イベント）には何も積まない（公開の受付から誰でも作れる行のため）
            $this->assertSame(0, DB::table('domain_events')->where('type', 'like', 'restock.%')->count());
        });
    }

    public function test_cancelling_by_the_link_ends_only_that_registration(): void
    {
        $bag = $this->inside('rsa', fn (): string => $this->listing('帆布のトートバッグ'));
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $this->sole($this->mug), 'email' => 'hanako@example.com']);
        $mugCancel = (string) parse_url((string) $this->lastMail('confirm')->facts['cancel'], PHP_URL_PATH);
        $this->shop('POST', "/p/restock/{$bag}", ['variant_id' => $this->sole($bag), 'email' => 'hanako@example.com']);
        $bagConfirm = (string) parse_url((string) $this->lastMail('confirm')->facts['confirm'], PHP_URL_PATH);
        $this->shop('POST', $bagConfirm)->assertOk();

        // 取り消しのリンクの画面は、押すまで変えない。押すとその登録だけを終え、メールを消す
        $this->shop('GET', $mugCancel)->assertOk()->assertSee('data-test="restock-cancel"', false);
        $this->shop('POST', $mugCancel)->assertOk()->assertSee('取り消しました');
        $rows = $this->inside('rsa', fn () => RestockRequest::query()->get()->keyBy('listing_id'));
        $this->assertSame(RestockStatus::Cancelled, $rows[$this->mug]->status);
        $this->assertNull($rows[$this->mug]->email);
        $this->assertSame(RestockStatus::Waiting, $rows[$bag]->status);
        $this->shop('POST', $mugCancel)->assertOk()->assertSee('すでに取り消されています');
        // 取り消した登録の確かめのリンクも、もう使えない
        $this->shop('POST', str_replace('/cancel/', '/confirm/', $mugCancel))->assertOk()->assertSee('すでに取り消されています');

        // 推測した・知らない合い言葉は何も変えない（形の違うものは経路に合わない）
        $this->shop('POST', '/p/restock/cancel/'.str_repeat('A', 43))->assertOk()->assertSee('このリンクは使えません');
        $this->assertContains($this->shop('POST', '/p/restock/cancel/short')->status(), [404, 405]);
        $this->assertSame(RestockStatus::Waiting, $this->inside('rsa', fn () => RestockRequest::query()->where('listing_id', $bag)->sole()->status));

        // 入荷しても、取り消した登録には送らない
        $this->receive($this->mug, 1);
        $this->receive($bag, 1);
        $this->assertSame(1, $this->mails('restocked'));
        $this->assertSame('帆布のトートバッグ', $this->lastMail('restocked')->facts['title']);
    }

    public function test_registering_twice_keeps_one_registration_and_a_new_link_replaces_the_old(): void
    {
        $variant = $this->sole($this->mug);
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => 'hanako@example.com']);
        $first = (string) parse_url((string) $this->lastMail('confirm')->facts['confirm'], PHP_URL_PATH);
        // 大文字小文字の違いは同じ人。確かめ待ちなら新しいリンクを送り直し、前のリンクは使えなくなる
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => 'HANAKO@example.com'], '198.51.100.7');
        $second = (string) parse_url((string) $this->lastMail('confirm')->facts['confirm'], PHP_URL_PATH);
        $this->assertNotSame($first, $second);
        $this->assertSame(1, $this->inside('rsa', fn () => RestockRequest::query()->count()));
        $this->shop('POST', $first)->assertOk()->assertSee('このリンクは使えません');
        $this->shop('POST', $second)->assertOk()->assertSee('登録が完了しました');

        // 有効な登録があるときは、同じ答えで何も送らない（登録があるかを、ほかの人が確かめられない）
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => 'hanako@example.com'], '198.51.100.8')
            ->assertRedirect("http://rsa.localhost/p/restock/{$this->mug}/sent");
        $this->assertSame(2, $this->mails('confirm'));
        $this->assertSame(1, $this->inside('rsa', fn () => RestockRequest::query()->count()));

        // 同じ時の 2 つ目は、DB の部分の一意の索引が拒む（アプリの引きをすり抜けても）
        $this->inside('rsa', function () use ($variant): void {
            $this->expectException(UniqueConstraintViolationException::class);
            RestockRequest::query()->create(['listing_id' => $this->mug, 'variant_id' => $variant, 'email' => 'Hanako@example.com', 'token_hash' => str_repeat('a', 64), 'status' => RestockStatus::Waiting, 'confirmed_at' => now()]);
        });
    }

    public function test_variants_only_the_sold_out_kinds_can_be_registered(): void
    {
        $apron = $this->inside('rsa', function (): string {
            $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => 'エプロン', 'unit_amount' => 4800, 'status' => ListingStatus::Active]);
            $listing = app(VariantService::class)->define($listing, $listing->version, [['name' => '色', 'values' => ['墨', 'からし']]]);
            $mustard = ListingVariant::query()->where('listing_id', $listing->id)->orderBy('position')->get()[1];
            app(StockService::class)->receive($mustard, Location::query()->firstOrFail(), 5);

            return $listing->id;
        });
        [$ink, $mustard] = $this->inside('rsa', fn (): array => ListingVariant::query()->where('listing_id', $apron)->orderBy('position')->pluck('id')->all());

        // 一部だけ売り切れ: 「カートに入れる」の下に出る。画面には売り切れの種類だけ
        $this->assertStringContainsString('purchase-option-restock', $this->page("/products/{$apron}"));
        $form = (string) $this->shop('GET', "/p/restock/{$apron}")->assertOk()->getContent();
        $this->assertStringContainsString('種類: 墨', $form);
        $this->assertStringNotContainsString('からし', $form);

        // 在庫のある種類・ほかの商品の種類は登録できない
        $this->shop('POST', "/p/restock/{$apron}", ['variant_id' => $mustard, 'email' => 'hanako@example.com'])->assertSessionHas('cart_error', 'この種類はいま買えます。商品ページからカートに入れてください。');
        $this->shop('POST', "/p/restock/{$apron}", ['variant_id' => $this->sole($this->mug), 'email' => 'hanako@example.com'])->assertSessionHas('cart_error', '種類を選び直してください。');
        $this->shop('POST', "/p/restock/{$apron}", ['variant_id' => $ink, 'email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->assertSame(0, $this->inside('rsa', fn () => RestockRequest::query()->count()));

        $this->shop('POST', "/p/restock/{$apron}", ['variant_id' => $ink, 'email' => 'hanako@example.com']);
        $this->assertSame('墨', $this->lastMail('confirm')->facts['variant']);
        $this->shop('POST', (string) parse_url((string) $this->lastMail('confirm')->facts['confirm'], PHP_URL_PATH));

        // 在庫の調整（増やす向き）でも知らせる。ほかの種類が増えても知らせない
        $this->inside('rsa', fn () => app(InventoryOperations::class)->receive(ListingVariant::query()->findOrFail($mustard), Location::query()->firstOrFail(), 1, 'test'));
        $this->assertSame(0, $this->mails('restocked'));
        $this->inside('rsa', fn () => app(InventoryOperations::class)->adjust(ListingVariant::query()->findOrFail($ink), Location::query()->firstOrFail(), 2, AdjustmentReason::Correction, 'test'));
        $this->assertSame(1, $this->mails('restocked'));
    }

    public function test_members_register_without_typing_the_email_and_leaving_cancels_it(): void
    {
        $this->shop('POST', '/account/login', ['email' => 'hanako@example.com']);
        $this->shop('POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code]);

        $form = (string) $this->shop('GET', "/p/restock/{$this->mug}")->assertOk()->getContent();
        $this->assertStringContainsString('data-test="restock-member"', $form);
        $this->assertStringNotContainsString('data-test="restock-email"', $form);

        // 会員はすぐ有効（確かめのメールは要らない）。登録の知らせと取り消しのリンクを送る
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $this->sole($this->mug)])->assertRedirect("http://rsa.localhost/p/restock/{$this->mug}/sent");
        $this->shop('GET', "/p/restock/{$this->mug}/sent")->assertSee('登録しました');
        $this->assertSame(0, $this->mails('confirm'));
        $this->assertNotNull($this->lastMail('registered')->facts['cancel']);
        $row = $this->inside('rsa', fn () => RestockRequest::query()->sole());
        $this->assertSame(RestockStatus::Waiting, $row->status);
        $this->assertNotNull($row->customer_account_id);
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $this->sole($this->mug)]);
        $this->shop('GET', "/p/restock/{$this->mug}/sent")->assertSee('すでに登録済みです');

        // 退会すると、その会員の登録も取り消す（メールを消す）
        $this->inside('rsa', fn () => app(CustomerAccounts::class)->leave(CustomerAccount::query()->sole(), 'test'));
        $row = $this->inside('rsa', fn () => RestockRequest::query()->sole());
        $this->assertSame(RestockStatus::Cancelled, $row->status);
        $this->assertNull($row->email);
        $this->receive($this->mug, 1);
        $this->assertSame(0, $this->mails('restocked'));
    }

    public function test_sending_is_limited_per_address_and_per_source_with_the_same_answer(): void
    {
        $variant = $this->sole($this->mug);

        // メール × 接続元は 1 時間に 5 回まで。超えても同じ答えで、送らないだけ
        for ($i = 0; $i < RestockRequests::PAIR_HOURLY + 1; $i++) {
            $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => 'hanako@example.com'], '203.0.113.1')
                ->assertRedirect("http://rsa.localhost/p/restock/{$this->mug}/sent");
        }

        $this->assertSame(RestockRequests::PAIR_HOURLY, $this->mails('confirm'));

        // 同じメールへは、接続元を変えても 1 日に 10 通まで（ほかの人のアドレスにメールを送りつけ続けられない）
        for ($i = 0; $i < RestockRequests::EMAIL_DAILY; $i++) {
            $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => 'hanako@example.com'], "198.51.100.{$i}");
        }

        $this->assertSame(RestockRequests::EMAIL_DAILY, $this->mails('confirm'));

        // 1 つの接続元は 1 時間に 20 回まで（メールを変えても）
        $before = $this->mails('confirm');

        for ($i = 0; $i < RestockRequests::SOURCE_HOURLY + 2; $i++) {
            $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => "guest{$i}@example.com"], '192.0.2.9');
        }

        $this->assertSame(RestockRequests::SOURCE_HOURLY, $this->mails('confirm') - $before);
    }

    public function test_another_shop_cannot_read_or_change_the_registrations(): void
    {
        $this->tenant('rsb');
        $theirs = $this->inside('rsb', function (): string {
            app(PluginStates::class)->set('restock', true, 'test');

            return $this->listing('B の店のマグ');
        });
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $this->sole($this->mug), 'email' => 'hanako@example.com']);
        $path = (string) parse_url((string) $this->lastMail('confirm')->facts['confirm'], PHP_URL_PATH);

        // A の店の合い言葉は B の店では使えない。A の店の商品・種類も B の店では無いのと同じ
        $this->shop('POST', $path, [], null, 'rsb')->assertOk()->assertSee('このリンクは使えません');
        $this->shop('POST', str_replace('/confirm/', '/cancel/', $path), [], null, 'rsb')->assertOk()->assertSee('このリンクは使えません');
        $this->shop('GET', "/p/restock/{$this->mug}", [], null, 'rsb')->assertOk()->assertSee('受け付けていません');
        $this->shop('POST', "/p/restock/{$theirs}", ['variant_id' => $this->sole($this->mug), 'email' => 'x@example.com'], null, 'rsb')->assertSessionHas('cart_error');
        $this->assertSame(RestockStatus::Pending, $this->inside('rsa', fn () => RestockRequest::query()->sole()->status));
        $this->assertSame(0, $this->inside('rsb', fn () => RestockRequest::query()->count()));

        // B の店の管理画面にも出ない
        $this->enrolledOperator();
        $this->send('GET', '/api/tenants/rsb/apps/restock/records?filter=all')->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', '/api/tenants/rsa/apps/restock/records?filter=all')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_two_runs_at_the_same_time_never_tell_one_registration_twice(): void
    {
        $variant = $this->sole($this->mug);
        $ids = $this->inside('rsa', function () use ($variant): array {
            $ids = [];

            foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $i => $email) {
                $ids[] = RestockRequest::query()->create(['listing_id' => $this->mug, 'variant_id' => $variant, 'email' => $email, 'token_hash' => str_repeat((string) $i, 64), 'status' => RestockStatus::Waiting, 'confirmed_at' => now()->subMinutes(10 - $i)])->id;
            }

            // 入荷を、記録を残さない形で（在庫の操作の知らせは来ない）
            app(StockService::class)->receive(Listing::query()->findOrFail($this->mug), Location::query()->firstOrFail(), 5);

            return $ids;
        });

        $this->inside('rsa', function () use ($variant, $ids): void {
            // もう 1 本の処理が、いちばん古い登録を「知らせた」にして、まだ確定していない
            config(['database.connections.tenant_other' => config('database.connections.tenant')]);
            $other = DB::connection('tenant_other');
            $other->beginTransaction();

            try {
                $other->update("UPDATE restock_requests SET status = 'notified', notified_at = now() WHERE id = ?", [$ids[0]]);
                // こちらの処理は、押さえられた行を待たずに飛ばし、残りの 2 つだけを取る
                DB::statement("SET lock_timeout = '2s'");
                $this->assertSame(2, app(RestockNotifier::class)->notify($variant));
                $other->commit();
            } finally {
                if ($other->transactionLevel() > 0) {
                    $other->rollBack();
                }

                DB::statement('SET lock_timeout = 0');
                DB::purge('tenant_other');
            }

            // 同じ処理がもう一度動いても、もう送らない
            $this->assertSame(0, app(RestockNotifier::class)->notify($variant));
            $this->assertSame([], app(RestockNotifier::class)->claim($variant, 10, CarbonImmutable::now()));
        });

        // こちらが送ったのは押さえられていない 2 つだけ（古い順）
        $this->assertSame(2, $this->mails('restocked'));
        $sent = Mail::sent(RestockMail::class, static fn (RestockMail $m): bool => $m->kind === 'restocked');
        $this->assertTrue($sent->contains(static fn (RestockMail $m): bool => $m->hasTo('b@example.com')));
        $this->assertTrue($sent->contains(static fn (RestockMail $m): bool => $m->hasTo('c@example.com')));
        $this->assertFalse($sent->contains(static fn (RestockMail $m): bool => $m->hasTo('a@example.com')));
    }

    public function test_the_hourly_run_tells_the_ones_the_events_missed_and_drops_expired_ones(): void
    {
        $variant = $this->sole($this->mug);
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => 'old@example.com']);
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => 'hanako@example.com'], '198.51.100.2');
        $this->shop('POST', (string) parse_url((string) $this->lastMail('confirm')->facts['confirm'], PHP_URL_PATH));

        // 記録を残さない入荷（取引の外の直し・取りこぼし）は、1 時間ごとの処理が拾う。確かめの期限の切れた登録は消す
        $this->inside('rsa', fn () => app(StockService::class)->receive(Listing::query()->findOrFail($this->mug), Location::query()->firstOrFail(), 1));
        $this->assertSame(0, $this->mails('restocked'));
        $this->travel(RestockRequests::CONFIRM_HOURS + 1)->hours();
        $this->artisan('restock:run')->assertSuccessful();
        $this->assertSame(1, $this->mails('restocked'));
        $this->assertTrue($this->lastMail('restocked')->hasTo('hanako@example.com'));
        $this->assertSame(['notified'], $this->inside('rsa', fn () => RestockRequest::query()->pluck('status')->map(static fn ($s) => $s->value)->all()));
    }

    public function test_the_admin_page_shows_counts_only_and_the_setting_adds_the_shop_message(): void
    {
        $this->inside('rsa', fn () => app(PluginSettings::class)->update('restock', ['confirm_message' => 'いつもありがとうございます。'], 'test'));
        $variant = $this->sole($this->mug);
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => 'hanako@example.com']);
        $this->assertStringStartsWith('いつもありがとうございます。', $this->lastMail('confirm')->render());
        $this->shop('POST', (string) parse_url((string) $this->lastMail('confirm')->facts['confirm'], PHP_URL_PATH));
        $this->shop('POST', "/p/restock/{$this->mug}", ['variant_id' => $variant, 'email' => 'taro@example.com'], '198.51.100.3');

        $this->enrolledOperator();
        $list = $this->send('GET', '/api/tenants/rsa/apps/restock/records')->assertOk();
        $list->assertJsonPath('rows.0.id', $variant)->assertJsonPath('rows.0.cells.waiting', 1)->assertJsonPath('rows.0.cells.pending', 1)->assertJsonPath('rows.0.cells.notified', 0);
        $this->assertStringNotContainsString('example.com', (string) $list->getContent());
        $record = $this->send('GET', "/api/tenants/rsa/apps/restock/records/{$variant}")->assertOk();
        $record->assertJsonPath('record.title', '白いマグカップ')->assertJsonPath('record.actions', []);
        $this->assertStringNotContainsString('example.com', (string) $record->getContent());

        $this->receive($this->mug, 1);
        $this->send('GET', '/api/tenants/rsa/apps/restock/records?filter=all')->assertOk()->assertJsonPath('rows.0.cells.notified', 1)->assertJsonPath('rows.0.cells.waiting', 0);
        $this->send('GET', '/api/tenants/rsa/apps/restock/records')->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', '/api/tenants/rsa/apps/restock/records/'.strtolower((string) Str::ulid()))->assertNotFound();
    }

    private function listing(string $title): string
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 1800, 'status' => ListingStatus::Active])->id;
    }

    private function sole(string $listingId): string
    {
        return $this->inside('rsa', fn (): string => (string) Listing::query()->findOrFail($listingId)->soleVariant()?->id);
    }

    /**
     * 人が入庫する（記録が残り、App はその知らせで入荷を知る）
     */
    private function receive(string $listingId, int $quantity): void
    {
        $this->inside('rsa', fn () => app(InventoryOperations::class)->receive(Listing::query()->findOrFail($listingId), Location::query()->firstOrFail(), $quantity, 'test'));
    }

    private function mails(string $kind): int
    {
        return Mail::sent(RestockMail::class, static fn (RestockMail $m): bool => $m->kind === $kind)->count();
    }

    private function lastMail(string $kind): RestockMail
    {
        $mail = Mail::sent(RestockMail::class, static fn (RestockMail $m): bool => $m->kind === $kind)->last();
        $this->assertInstanceOf(RestockMail::class, $mail);

        return $mail;
    }

    private function page(string $path): string
    {
        return (string) $this->shop('GET', $path)->assertOk()->getContent();
    }

    /**
     * ショップへ送る（会員の印を持ち回る。送るたびに 7 秒進める〔人の速さ。1 分の回数の上限にテスト自身が当たらないように〕）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = [], ?string $ip = null, string $shop = 'rsa'): TestResponse
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
