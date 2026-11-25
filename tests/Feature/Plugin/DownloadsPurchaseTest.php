<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventRelay;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\AgeChecks;
use App\Core\Order\ReturnRequest;
use App\Core\Order\Services\DirectPayments;
use App\Core\Order\Services\OrderReturns;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Enums\ReturnReason;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Plugin\ListingFileUpload;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use Carbon\CarbonImmutable;
use Closure;
use EcSugar\PluginAgeCheck\AgeCheckListings;
use EcSugar\PluginDownloads\DownloadAdminPage;
use EcSugar\PluginDownloads\DownloadFiles;
use EcSugar\PluginDownloads\DownloadGrants;
use EcSugar\PluginDownloads\DownloadLinks;
use EcSugar\PluginDownloads\DownloadRefused;
use EcSugar\PluginDownloads\Mail\DownloadMail;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeDownloads;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * App「ダウンロード販売」をお客さんが買って受け取る（ADR 0084）。ふだんのカート（ゲスト）で、送料・お届け先・在庫なし・受渡は digital。
 * 払いが確かになったらリンクを作り、digital の受渡を渡し終え、ご案内のメール。物と一緒のカートは受渡を分ける。
 * 開けるのは照会を通した人か会員の本人だけ（署名つきのリンクだけでは開けない）。回数と期限はちょうどで断り、同時に押しても錠で 1 つずつ。
 * 返金・取消で止まる。ほかの注文・ほかの店・書き換えた署名では受け取れない
 */
final class DownloadsPurchaseTest extends TestCase
{
    use DatabaseTruncation;

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private Tenant $tenant;

    private FilesystemAdapter $disk;

    private ?string $token = null;

    private ?string $session = null;

    private string $ebook;

    private string $mug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Asia/Tokyo'));
        $this->disk = FakeDownloads::fake();
        $this->tenant = Tenant::create(['id' => 'dlp', 'name' => '写真の店']);
        $this->tenant->domains()->create(['domain' => 'dlp.localhost']);
        [$this->ebook, $this->mug] = $this->inside(function (): array {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            // コンビニ払いを使う店でも、ダウンロードの品のある注文にはコンビニ払いを出さない
            app(PaymentMethodSettings::class)->update(['konbini' => true, 'paypay' => false, 'konbini_days' => 3], 'test');
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 20000]);
            app(PluginStates::class)->set('downloads', true, 'test');
            app(PluginSettings::class)->update('downloads', ['limit' => '2'], 'test');
            $seller = Seller::query()->value('id');
            $ebook = Listing::create(['seller_id' => $seller, 'kind' => 'download', 'title' => '写真集（PDF）', 'unit_amount' => 1500, 'status' => 'active']);
            $mug = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'マグカップ', 'unit_amount' => 2000, 'status' => 'active']);
            app(StockService::class)->receive($mug, Location::query()->orderBy('id')->firstOrFail(), 5);

            foreach (['写真集.pdf', 'おまけ.pdf'] as $index => $name) {
                $this->disk->put("uploads/dlp/{$index}.part", self::PDF.$name);
                app(DownloadFiles::class)->store($ebook, new ListingFileUpload("uploads/dlp/{$index}.part", $name, 'pdf', 'application/pdf', strlen(self::PDF.$name), hash('sha256', self::PDF.$name)), 'test');
            }

            return [$ebook->id, $mug->id];
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        CarbonImmutable::setTestNow();
        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_guest_buys_a_download_without_a_ship_to_and_gets_links_when_paid(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1'])->assertRedirect();
        // 1 回の注文で 1 点まで
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1']);
        $this->inside(fn () => $this->assertSame(1, (int) DB::table('cart_lines')->sum('quantity')));

        // お届け先を聞かない（お届け先の欄も「ご依頼主と同じ」も無い。送られてきても使わない）
        $form = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ship-to', $form);
        $this->send('POST', '/checkout', ['buyer' => self::buyer('hanako@example.com'), 'same_as_buyer' => '0',
            'recipient' => ['name' => '', 'phone' => 'x'], 'delivery' => ['date' => '2099-01-01']])->assertRedirect('http://dlp.localhost/checkout/confirm');
        $confirm = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-test="confirm-recipient"', $confirm);
        $this->assertStringContainsString('¥1,500', $confirm);
        $this->toStripe($confirm);

        $order = $this->inside(function (): Transaction {
            $order = Transaction::query()->sole();
            // 送料なし・在庫を押さえない・受渡は digital（お届け先も拠点も無い）
            $this->assertSame(1500, (int) $order->total_amount);
            $this->assertSame(['item'], $order->lines()->pluck('line_type')->map(static fn ($t) => $t instanceof \BackedEnum ? $t->value : $t)->all());
            $fulfillment = Fulfillment::query()->where('transaction_id', $order->id)->sole();
            $this->assertSame([FulfillmentMethod::Digital, null, null], [$fulfillment->method, $fulfillment->address, $fulfillment->location_id]);
            $this->assertSame(0, (int) InventoryStock::query()->sum('reserved'));
            // コンビニ払いは出さない（カードだけ）
            $this->assertSame('card', PaymentAttempt::query()->where('transaction_id', $order->id)->sole()->methods);
            $this->assertSame(0, DownloadGrant::query()->count());

            return $order;
        });

        $this->pay($order);

        $this->inside(function () use ($order): void {
            // ファイルごとにリンク（期限 30 日・回数は App の設定の 2 回）。digital の受渡を渡し終えて注文は精算まで
            $grants = DownloadGrant::query()->where('transaction_id', $order->id)->get();
            $this->assertCount(2, $grants);
            $this->assertSame([2, 2], $grants->pluck('max_count')->all());
            $this->assertTrue($grants->every(static fn (DownloadGrant $g): bool => $g->expires_at->equalTo(CarbonImmutable::now()->addDays(30))));
            $this->assertSame(FulfillmentStatus::Completed, Fulfillment::query()->where('transaction_id', $order->id)->sole()->status);
            $fresh = Transaction::query()->findOrFail($order->id);
            $this->assertSame([TransactionStatus::Completed, Phase::Settlement], [$fresh->status, $fresh->phase]);
            $this->assertSame(0, DomainEvent::query()->where('type', 'fulfillment.shipped')->count());
            // 2 回目の知らせでは作り直さない
            $this->assertFalse(app(DownloadGrants::class)->paid($order->id));
            $this->assertSame(2, DownloadGrant::query()->count());
            // 記録に個人情報・ファイルの名前を入れない
            $payloads = DomainEvent::query()->pluck('payload')->map(fn ($p) => json_encode($p, JSON_UNESCAPED_UNICODE))->implode("\n");
            foreach (['hanako', '山田', '写真集.pdf', 'おまけ'] as $secret) {
                $this->assertStringNotContainsString($secret, $payloads);
            }
        });

        // ご案内のメール（署名つきのリンク。ファイルの名前は載せない）
        Mail::assertSent(DownloadMail::class, function (DownloadMail $mail) use ($order): bool {
            return $mail->hasTo('hanako@example.com') && $mail->facts['files'] === 2 && $mail->facts['limit'] === 2
                && str_starts_with($mail->facts['url'], 'http://dlp.localhost/p/downloads/orders/'.$order->id.'?') && str_contains($mail->facts['url'], 'signature=');
        });
    }

    /**
     * 送らない品だけの注文でも、年齢の確かめの答え（App「年齢確認」。ADR 0077）は確かめの画面と確定まで持ち越す
     * （お届け先を外したコピーが答えを落とすと、年齢の要るダウンロードの品は確定できなくなる。取り込みで見つけた）
     */
    public function test_a_download_that_needs_an_age_keeps_the_answer_through_the_confirmation_and_the_order(): void
    {
        $this->inside(function (): void {
            app(PluginStates::class)->set('age-check', true, 'test');
            app(AgeCheckListings::class)->save(Listing::query()->findOrFail($this->ebook), true, 20, 'operator:test');
        });

        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', ['buyer' => self::buyer('hanako@example.com'), 'age' => ['birthdate' => '1990-04-01', 'consent' => '1']])
            ->assertRedirect('http://dlp.localhost/checkout/confirm');
        $confirm = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-test="confirm-recipient"', $confirm);
        $this->toStripe($confirm);

        $this->inside(function (): void {
            $order = Transaction::query()->sole();
            $this->assertSame(1, DomainEvent::query()->where('type', AgeChecks::VERIFIED)->where('aggregate_id', $order->id)->count());
        });
    }

    public function test_only_the_person_who_looked_up_the_order_or_the_member_opens_the_links(): void
    {
        $order = $this->buy('hanako@example.com');
        $url = $this->mailUrl($order);

        // メールのリンクだけでは開けない（転送された人）。注文があるかは教えない
        $this->send('GET', $url)->assertRedirect('http://dlp.localhost/orders');
        // 照会を通すと開ける
        $this->lookup($order, 'hanako@example.com');
        $page = (string) $this->send('GET', $url)->assertOk()->getContent();
        $this->assertStringContainsString('写真集.pdf', $page);
        $this->assertStringContainsString('残り 2 回 / 2 回', $page);
        // 署名の無い・書き換えた道筋では開けない（会員でない人）
        $this->send('GET', "/p/downloads/orders/{$order->id}")->assertRedirect('http://dlp.localhost/orders');
        $this->send('GET', str_replace('signature=', 'signature=0', $url))->assertRedirect('http://dlp.localhost/orders');
        $this->send('GET', (string) preg_replace('/expires=\d+/', 'expires=4102444800', $url))->assertRedirect('http://dlp.localhost/orders');

        // ほかの注文のリンクは、照会を通した注文の道筋からも取れない
        $this->token = null;
        $other = $this->buy('taro@example.com');
        $otherGrant = $this->inside(fn () => (string) DownloadGrant::query()->where('transaction_id', $other->id)->value('id'));
        $action = $this->fetchAction($page);
        // 署名の合う、この注文の道筋に、ほかの注文のリンクの ID を入れても断る（署名を作り直しても、リンクがこの注文のものかを見る）
        $forged = $this->inside(fn () => DownloadLinks::fetch($order->id, $otherGrant, CarbonImmutable::now()->addHour()));
        $this->send('POST', $forged)->assertRedirect();
        $this->assertStringContainsString('見つかりませんでした', (string) $this->send('GET', $url)->getContent());
        $this->inside(fn () => $this->assertSame(0, (int) DownloadGrant::query()->sum('count')));

        // ほかの店では、この店の注文もリンクも無いのと同じ
        $shop = Tenant::create(['id' => 'dlq', 'name' => 'ほかの店']);
        $shop->domains()->create(['domain' => 'dlq.localhost']);

        try {
            tenancy()->initialize($shop);
            app(PluginStates::class)->set('downloads', true, 'test');
            tenancy()->end();
            $path = (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
            $this->assertSame(302, $this->get("http://dlq.localhost{$path}")->getStatusCode());
            tenancy()->end();
            $file = $this->get('http://dlq.localhost'.$this->fileUrl($action));
            $this->assertContains($file->getStatusCode(), [403, 404]);
            tenancy()->end();
        } finally {
            $shop->delete();
        }
    }

    public function test_the_count_and_the_deadline_are_kept_exactly_and_the_file_is_served_as_an_attachment(): void
    {
        $order = $this->buy('hanako@example.com');
        $this->lookup($order, 'hanako@example.com');
        $action = $this->fetchAction((string) $this->send('GET', $this->mailUrl($order))->getContent());

        // 1 回目: 短い期限の署名つきの道筋へ。ファイルは添付で、型の推測をさせない
        $first = $this->send('POST', $action)->assertStatus(303);
        $file = $this->send('GET', (string) parse_url((string) $first->headers->get('Location'), PHP_URL_PATH).'?'.parse_url((string) $first->headers->get('Location'), PHP_URL_QUERY));
        $file->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $file->headers->get('Content-Disposition'));
        $this->assertStringContainsString(strtolower("filename=download.pdf; filename*=utf-8''".rawurlencode('写真集.pdf')), strtolower((string) $file->headers->get('Content-Disposition')));
        $this->assertSame('nosniff', $file->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/pdf', $file->headers->get('Content-Type'));
        $this->assertSame(self::PDF.'写真集.pdf', $file->streamedContent());

        // 2 回目まで（上限ちょうど）は通り、3 回目は断る
        $this->send('POST', $action)->assertStatus(303);
        $this->send('POST', $action)->assertRedirect();
        $this->assertStringContainsString('回数（2 回）を使い切りました', (string) $this->send('GET', $this->mailUrl($order))->getContent());
        $this->inside(fn () => $this->assertSame(2, DownloadGrant::query()->where('transaction_id', $order->id)->orderBy('created_at')->orderBy('id')->firstOrFail()->count));

        // 渡す道筋は押したセッションだけ（同じリンクの、ほかのセッションの印の道筋は断る）
        $other = $this->inside(fn () => DownloadLinks::file((string) DownloadGrant::query()->where('transaction_id', $order->id)->orderBy('created_at')->orderBy('id')->value('id'), 'another-session-id'));
        $this->send('GET', $other)->assertForbidden();

        // 渡す道筋は 5 分で切れる
        $this->travel(6)->minutes();
        $this->send('GET', (string) parse_url((string) $first->headers->get('Location'), PHP_URL_PATH).'?'.parse_url((string) $first->headers->get('Location'), PHP_URL_QUERY))->assertForbidden();

        // 期限: ちょうどの 1 秒前は通り、ちょうどからは断る（2 つ目のファイル）
        [$second, $expires] = $this->inside(function () use ($order): array {
            $grant = DownloadGrant::query()->where('transaction_id', $order->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

            return [$grant, $grant->expires_at];
        });
        $this->travelTo($expires->subSecond());
        $this->inside(fn () => app(DownloadGrants::class)->fetch($second->id, CarbonImmutable::now()));
        $this->travelTo($expires);
        $this->inside(function () use ($second): void {
            try {
                app(DownloadGrants::class)->fetch($second->id, CarbonImmutable::now());
                $this->fail('期限ちょうどで渡した');
            } catch (DownloadRefused $e) {
                $this->assertStringContainsString('期限が過ぎました', $e->getMessage());
            }

            // 店の人が回数を戻し、期限を延ばすと、また受け取れる（記録に残る）
            $this->assertSame(2, app(DownloadGrants::class)->reset($second->transaction_id, 'operator:test'));
            $this->assertSame(2, app(DownloadGrants::class)->extend($second->transaction_id, 'operator:test', CarbonImmutable::now()));
            app(DownloadGrants::class)->fetch($second->id, CarbonImmutable::now());
            $this->assertSame(['download.reset', 'download.extended'], DomainEvent::query()->whereIn('type', ['download.reset', 'download.extended'])->orderBy('id')->pluck('type')->all());
        });
    }

    public function test_two_presses_at_once_wait_for_the_lock_and_the_database_keeps_the_count_within_the_limit(): void
    {
        $order = $this->buy('hanako@example.com');

        $this->inside(function () use ($order): void {
            $grant = DownloadGrant::query()->where('transaction_id', $order->id)->orderBy('id')->firstOrFail();

            // ほかの要求が連鎖のロックを持っている間は待つ（同じ残りを 2 つが同時に読んで両方が通る、が起きない）
            config(['database.connections.tenant_other' => config('database.connections.tenant')]);
            $other = DB::connection('tenant_other');
            $other->beginTransaction();

            try {
                $other->select('SELECT pg_advisory_xact_lock(?)', [0x5E_C0_E7]);
                DB::statement("SET lock_timeout = '300ms'");

                try {
                    app(DownloadGrants::class)->fetch($grant->id, CarbonImmutable::now());
                    $this->fail('ロックを待たずに数えた');
                } catch (QueryException $e) {
                    $this->assertSame('55P03', $e->getCode());
                }
            } finally {
                $other->rollBack();
                DB::statement('SET lock_timeout = 0');
                DB::purge('tenant_other');
            }

            app(DownloadGrants::class)->fetch($grant->id, CarbonImmutable::now());
            app(DownloadGrants::class)->fetch($grant->id, CarbonImmutable::now());

            try {
                app(DownloadGrants::class)->fetch($grant->id, CarbonImmutable::now());
                $this->fail('上限を越えて渡した');
            } catch (DownloadRefused) {
            }

            // アプリの確かめをすり抜けても、DB が上限を越えさせない
            try {
                DB::transaction(static fn () => DB::table('downloads_grants')->where('id', $grant->id)->update(['count' => 3]));
                $this->fail('DB が上限を越えた回数を受けた');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        });
    }

    public function test_a_refund_or_cancellation_stops_the_links(): void
    {
        $order = $this->buy('hanako@example.com');
        $this->lookup($order, 'hanako@example.com');
        $action = $this->fetchAction((string) $this->send('GET', $this->mailUrl($order))->getContent());

        // 店の人が止める → 受け取れない → 再開できる
        $this->inside(fn () => app(DownloadGrants::class)->stop($order->id, 'operator:test', CarbonImmutable::now()));
        $this->send('POST', $action)->assertRedirect();
        $this->inside(fn () => $this->assertSame(0, (int) DownloadGrant::query()->sum('count')));
        $this->inside(fn () => $this->assertSame(2, app(DownloadGrants::class)->resume($order->id, 'operator:test')));
        $this->send('POST', $action)->assertStatus(303);

        // 本物の返品の返金（決済代行で全額。ダウンロードだけの注文は渡すと精算まで進むので、取消でなく返品の道）で全部止まり、店の人も再開できない
        $this->inside(function () use ($order): void {
            $sale = Transaction::query()->findOrFail($order->id);
            $this->assertFalse(app(SaleOrderService::class)->cancellable($sale));
            $position = (int) $sale->lines()->where('line_type', 'item')->value('position');
            $refund = app(OrderReturns::class)->record($sale, new ReturnRequest([$position => ['quantity' => 1, 'restock' => false, 'addons' => false]], false, 0, ReturnReason::Returned, 'provider'), null, 'operator:test');
            $this->assertSame([1500, 'succeeded'], [$refund?->amount, $refund?->status]);
            $this->assertTrue(DownloadGrant::query()->where('transaction_id', $order->id)->get()->every(static fn (DownloadGrant $g): bool => $g->stop_reason === 'refund'));
            $this->assertSame(0, app(DownloadGrants::class)->resume($order->id, 'operator:test'));
        });
        $this->send('POST', $action)->assertRedirect();
        $this->assertStringContainsString('返金', (string) $this->send('GET', $this->mailUrl($order))->getContent());
    }

    public function test_a_partial_return_stops_only_the_download_lines_returned_and_an_amount_only_refund_stops_nothing(): void
    {
        $order = $this->mixed();

        $this->inside(function () use ($order): void {
            // 送る品を発送して完了（返品できる段へ）
            $sale = Transaction::query()->findOrFail($order->id);
            $shipping = (string) Fulfillment::query()->where('transaction_id', $sale->id)->where('method', 'shipping')->value('id');
            app(SaleOrderService::class)->complete(app(SaleOrderService::class)->ship($sale, $shipping, 'yamato', '1234', 'operator:test'), 'operator:test');
            $sale = Transaction::query()->findOrFail($order->id);
            $mug = (int) $sale->lines()->where('listing_id', $this->mug)->value('position');
            $ebook = (int) $sale->lines()->where('listing_id', $this->ebook)->value('position');
            $returns = app(OrderReturns::class);

            // マグカップを 1 つ返品・額だけの一部返金: ダウンロードは止めない
            $returns->record($sale, new ReturnRequest([$mug => ['quantity' => 1, 'restock' => false, 'addons' => false]], false, 0, ReturnReason::Returned, 'provider'), null, 'operator:test');
            $returns->record(Transaction::query()->findOrFail($sale->id), new ReturnRequest([], false, 300, ReturnReason::Goodwill, 'provider'), null, 'operator:test');
            $this->assertSame(0, DownloadGrant::query()->where('transaction_id', $sale->id)->whereNotNull('stopped_at')->count());

            // ダウンロードの行を返品: その商品のリンクを止める（全額ではない）
            $returns->record(Transaction::query()->findOrFail($sale->id), new ReturnRequest([$ebook => ['quantity' => 1, 'restock' => false, 'addons' => false]], false, 0, ReturnReason::Defective, 'provider'), null, 'operator:test');
            $this->assertSame(['refund', 'refund'], DownloadGrant::query()->where('transaction_id', $sale->id)->pluck('stop_reason')->all());
        });
    }

    public function test_returning_one_of_two_downloads_stops_only_its_links(): void
    {
        $music = $this->inside(function (): string {
            $music = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'download', 'title' => '音源', 'unit_amount' => 800, 'status' => 'active']);
            $this->disk->put('uploads/dlp/m.part', self::PDF.'m');
            app(DownloadFiles::class)->store($music, new ListingFileUpload('uploads/dlp/m.part', '音源.pdf', 'pdf', 'application/pdf', strlen(self::PDF.'m'), hash('sha256', self::PDF.'m')), 'test');

            return $music->id;
        });
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/cart/items', ['listing_id' => $music, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', ['buyer' => self::buyer('hanako@example.com')])->assertRedirect('http://dlp.localhost/checkout/confirm');
        $this->toStripe((string) $this->send('GET', '/checkout/confirm')->getContent());
        $order = $this->inside(fn (): Transaction => Transaction::query()->sole());
        $this->pay($order);

        $this->inside(function () use ($order, $music): void {
            $sale = Transaction::query()->findOrFail($order->id);
            $position = (int) $sale->lines()->where('listing_id', $music)->value('position');
            app(OrderReturns::class)->record($sale, new ReturnRequest([$position => ['quantity' => 1, 'restock' => false, 'addons' => false]], false, 0, ReturnReason::Returned, 'provider'), null, 'operator:test');
            $musicFiles = DownloadFile::query()->where('listing_id', $music)->pluck('id')->all();
            $grants = DownloadGrant::query()->where('transaction_id', $sale->id)->get();
            // 返した音源のリンクだけ止め、写真集（2 つ）は使える
            $this->assertSame([1, 2], [$grants->whereIn('file_id', $musicFiles)->whereNotNull('stopped_at')->count(), $grants->whereNotIn('file_id', $musicFiles)->whereNull('stopped_at')->count()]);
        });
    }

    public function test_cancelling_a_paid_mixed_order_before_shipping_refunds_and_stops_the_links(): void
    {
        $order = $this->mixed();

        $this->inside(function () use ($order): void {
            $sale = Transaction::query()->findOrFail($order->id);
            // digital の受渡を渡し終えていても、送る品の発送の前なら取り消せる（全額を返金）
            $this->assertTrue(app(SaleOrderService::class)->cancellable($sale));
            $done = app(SaleOrderService::class)->cancel($sale, 'operator:test');
            $this->assertSame([6050, TransactionStatus::Cancelled], [$done['refunded'], $done['transaction']->status]);
            $this->assertSame(['cancel', 'cancel'], DownloadGrant::query()->where('transaction_id', $sale->id)->pluck('stop_reason')->all());
            // 渡し終えた digital の受渡は済みのまま、取消の記録に数が残る。送る品の受渡は取り消し、押さえた在庫は戻る
            $this->assertSame(['digital' => 'completed', 'shipping' => 'cancelled'], Fulfillment::query()->where('transaction_id', $sale->id)->get()
                ->mapWithKeys(static fn (Fulfillment $f): array => [$f->method->value => $f->status->value])->sortKeys()->all());
            $this->assertSame(1, DomainEvent::query()->where('type', 'order.cancelled')->sole()->payload['digital_delivered'] ?? null);
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $this->mug)->value('reserved'));
        });
    }

    public function test_a_bank_transfer_order_gets_links_when_the_shop_confirms_and_loses_them_when_the_refund_is_recorded(): void
    {
        $this->inside(fn () => app(DirectMethodSettings::class)->update(['bank_transfer' => true, 'bank_days' => 7, 'cod' => false, 'cod_fees' => DirectMethodSettings::DEFAULT_COD_FEES, 'cod_limit' => 300000,
            'bank_account' => ['bank' => 'シュガー銀行', 'branch' => '渋谷支店', 'type' => 'ordinary', 'number' => '7654321', 'holder' => 'カ）シュガーテスト']], 'operator:test'));
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', ['buyer' => self::buyer('hanako@example.com'), 'payment' => 'bank_transfer'])->assertRedirect('http://dlp.localhost/checkout/confirm');
        $confirm = (string) $this->send('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''])->assertRedirect('http://dlp.localhost/checkout/complete');

        $this->inside(function (): void {
            $sale = Transaction::query()->sole();
            // 振込を待つ間はリンクを作らない
            $this->assertSame(0, DownloadGrant::query()->count());
            app(DirectPayments::class)->confirm($sale, 1500, false, 'operator:test');
            $this->assertSame(2, DownloadGrant::query()->whereNull('stopped_at')->count());

            // 店の人が自分で返金して記録する（全額）: 止まる
            app(DirectPayments::class)->recordRefund(Transaction::query()->findOrFail($sale->id), 1500, 'operator:test');
            $this->assertSame(['refund', 'refund'], DownloadGrant::query()->pluck('stop_reason')->all());
        });
    }

    public function test_a_product_without_files_cannot_be_bought_and_an_order_left_without_files_waits_until_files_are_placed(): void
    {
        // ファイルの無い商品はカートに入らない（商品の画面は売り切れ）
        $empty = $this->inside(fn (): string => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'download', 'title' => '音源', 'unit_amount' => 800, 'status' => 'active'])->id);
        $this->send('POST', '/cart/items', ['listing_id' => $empty, 'quantity' => '1']);
        $this->inside(fn () => $this->assertSame(0, (int) DB::table('cart_lines')->count()));

        // 払う前にファイルを消された（振込を待つ間など）: 払ってもリンクを作れないので、受渡は済みにせず「ファイル待ち」に出す
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', ['buyer' => self::buyer('hanako@example.com')])->assertRedirect('http://dlp.localhost/checkout/confirm');
        $this->toStripe((string) $this->send('GET', '/checkout/confirm')->getContent());
        $order = $this->inside(function (): Transaction {
            foreach (DownloadFile::query()->get() as $file) {
                $file->forceFill(['removed_at' => CarbonImmutable::now()])->save();
            }

            return Transaction::query()->sole();
        });
        $this->pay($order);

        $this->inside(function () use ($order): void {
            $this->assertSame(0, DownloadGrant::query()->count());
            $this->assertSame(FulfillmentStatus::Pending, Fulfillment::query()->where('transaction_id', $order->id)->value('status'));
            $this->assertSame([$order->id], DownloadGrants::waiting()->pluck('id')->all());
            $this->assertSame(1, collect(app(DownloadAdminPage::class)->attention())->firstWhere('filter', 'waiting')?->count);
            // 見回りはファイルが無い間は何もしない
            $this->assertSame([], app(DownloadGrants::class)->catchUp());

            // ファイルを置き直すと、見回りがリンクを作って渡し終える
            $this->disk->put('uploads/dlp/new.part', self::PDF.'new');
            app(DownloadFiles::class)->store(Listing::query()->findOrFail($this->ebook), new ListingFileUpload('uploads/dlp/new.part', '写真集（新版）.pdf', 'pdf', 'application/pdf', strlen(self::PDF.'new'), hash('sha256', self::PDF.'new')), 'test');
            $this->assertSame([$order->id], app(DownloadGrants::class)->catchUp());
            $this->assertSame(1, DownloadGrant::query()->where('transaction_id', $order->id)->count());
            $this->assertSame(FulfillmentStatus::Completed, Fulfillment::query()->where('transaction_id', $order->id)->value('status'));
            $this->assertSame([], DownloadGrants::waiting()->pluck('id')->all());
        });
    }

    public function test_when_the_app_is_turned_off_nobody_can_buy_but_paid_customers_still_download(): void
    {
        $order = $this->buy('hanako@example.com');
        $this->lookup($order, 'hanako@example.com');
        $this->inside(fn () => app(PluginStates::class)->set('downloads', false, 'test'));

        // 新しく買えない（商品の画面は売り切れ・カートに入らない）
        $this->token = null;
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1']);
        $this->inside(fn () => $this->assertSame(0, (int) DB::table('cart_lines')->count()));
        $this->assertStringContainsString('売り切れ', (string) $this->send('GET', "/products/{$this->ebook}")->getContent());

        // もう払ったお客さんは、ダウンロードの画面を開いてファイルを受け取れる
        $page = (string) $this->send('GET', $this->mailUrl($order))->assertOk()->getContent();
        $this->send('GET', $this->fileUrl($this->fetchAction($page)))->assertOk();
    }

    public function test_the_on_delivery_notice_of_cash_on_delivery_does_not_hand_over_downloads(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', ['buyer' => self::buyer('hanako@example.com')])->assertRedirect('http://dlp.localhost/checkout/confirm');
        $this->toStripe((string) $this->send('GET', '/checkout/confirm')->getContent());

        $this->inside(function (): void {
            $sale = Transaction::query()->sole();
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole();
            EventRelay::muting(['downloads.grant'], fn () => app(PaymentNotices::class)->confirmPaid('stripe', 'evt_x', 'checkout.session.completed', $attempt->provider_ref, $sale->id, 1500, 'jpy', 'pi_x'));
            // 代引きの「発送できる」の知らせ（お金が届く前）では渡さない
            event(new PaymentConfirmed($sale->id, onDelivery: true));
            $this->assertSame(0, DownloadGrant::query()->count());
            // 代引きの入金を待つ注文（代引きの支払いの記録だけ足す）には paid() も作らない
            PaymentAttempt::create(['transaction_id' => $sale->id, 'provider' => 'direct', 'provider_ref' => 'direct-'.$sale->id, 'checkout_url' => '', 'amount' => 1500, 'currency' => 'JPY',
                'status' => 'awaiting', 'expires_at' => CarbonImmutable::now()->addDays(7), 'kind' => 'direct', 'methods' => 'cod', 'method' => 'cod', 'pay_by' => CarbonImmutable::now()->addDays(7)]);
            $this->assertFalse(app(DownloadGrants::class)->paid($sale->id));
            $this->assertSame(0, DownloadGrant::query()->count());
        });
    }

    public function test_the_order_page_links_to_the_downloads_and_does_not_list_them_as_shipments(): void
    {
        $order = $this->buy('hanako@example.com');
        $this->lookup($order, 'hanako@example.com');
        $page = (string) $this->send('GET', "/orders/{$order->id}")->assertOk()->getContent();
        // App のご注文のリンク「ダウンロード」（署名つき）。渡し終えた digital の受渡は「発送」に出さない
        $this->assertMatchesRegularExpression('#href="[^"]*/p/downloads/orders/'.$order->id.'\?[^"]*signature=[^"]+"[^>]*>\s*ダウンロード#u', $page);
        $this->assertStringNotContainsString('id="shipments"', $page);
    }

    public function test_a_mixed_cart_ships_the_goods_and_hands_over_the_download_separately(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/cart/items', ['listing_id' => $this->mug, 'quantity' => '2'])->assertRedirect();
        // 送る品があるのでお届け先を聞く
        $this->assertStringContainsString('data-ship-to', (string) $this->send('GET', '/checkout')->getContent());
        $this->send('POST', '/checkout', ['buyer' => self::buyer('hanako@example.com'), 'same_as_buyer' => '1'])->assertRedirect('http://dlp.localhost/checkout/confirm');
        $this->toStripe((string) $this->send('GET', '/checkout/confirm')->getContent());

        $order = $this->inside(function (): Transaction {
            $order = Transaction::query()->sole();
            // 送料は送る品の分だけ（1,500 + 2,000 × 2 + 550）。受渡は送る品と digital に分かれる
            $this->assertSame(6050, (int) $order->total_amount);
            $methods = Fulfillment::query()->where('transaction_id', $order->id)->pluck('method')->map(static fn (FulfillmentMethod $m): string => $m->value)->sort()->values()->all();
            $this->assertSame(['digital', 'shipping'], $methods);
            $this->assertNull(Fulfillment::query()->where('transaction_id', $order->id)->where('method', 'digital')->value('address'));
            $this->assertSame(2, (int) InventoryStock::query()->where('listing_id', $this->mug)->value('reserved'));

            return $order;
        });

        // 払われた（App が渡す前。払われた知らせを止めて）: digital の受渡は発送できない（払いが確かになった時に App が渡す）
        $this->inside(function () use ($order): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $order->id)->sole();
            EventRelay::muting(['downloads.grant'], fn () => app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$order->id, 'checkout.session.completed', $attempt->provider_ref, $order->id, (int) $order->total_amount, 'jpy', 'pi_'.$order->id));
            $this->assertSame(FulfillmentStatus::Pending, Fulfillment::query()->where('transaction_id', $order->id)->where('method', 'digital')->value('status'));
            $digital = (string) Fulfillment::query()->where('transaction_id', $order->id)->where('method', 'digital')->value('id');

            try {
                app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($order->id), $digital, 'yamato', '1234', 'operator:test');
                $this->fail('送らない品の配送を発送した');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('発送しない商品', $e->getMessage());
            }
        });
        $this->inside(fn () => event(new PaymentConfirmed($order->id)));

        $this->inside(function () use ($order): void {
            // digital だけ渡し終え、送る品はこれから（注文は「入金済み」のまま）
            $this->assertSame(2, DownloadGrant::query()->where('transaction_id', $order->id)->count());
            $this->assertSame(['digital' => 'completed', 'shipping' => 'pending'], Fulfillment::query()->where('transaction_id', $order->id)->get()
                ->mapWithKeys(static fn (Fulfillment $f): array => [$f->method->value => $f->status->value])->sortKeys()->all());
            $fresh = Transaction::query()->findOrFail($order->id);
            $this->assertSame(Phase::Payment, $fresh->phase);

            // 送る品の発送・完了はいつもどおり
            $shipping = (string) Fulfillment::query()->where('transaction_id', $order->id)->where('method', 'shipping')->value('id');
            $shipped = app(SaleOrderService::class)->ship($fresh, $shipping, 'yamato', '1234', 'operator:test');
            $done = app(SaleOrderService::class)->complete($shipped, 'operator:test');
            $this->assertSame([TransactionStatus::Completed, Phase::Settlement], [$done->status, $done->phase]);
        });
    }

    /**
     * ゲストがダウンロードの品とマグカップ 2 つを買って払う（ダウンロードは渡し終え、送る品はこれから）
     */
    private function mixed(): Transaction
    {
        $this->token = null;
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/cart/items', ['listing_id' => $this->mug, 'quantity' => '2'])->assertRedirect();
        $this->send('POST', '/checkout', ['buyer' => self::buyer('hanako@example.com'), 'same_as_buyer' => '1'])->assertRedirect('http://dlp.localhost/checkout/confirm');
        $this->toStripe((string) $this->send('GET', '/checkout/confirm')->getContent());
        $order = $this->inside(fn (): Transaction => Transaction::query()->latest('id')->firstOrFail());
        $this->pay($order);

        return $order;
    }

    /**
     * ゲストがダウンロードの品だけを買って払う
     */
    private function buy(string $email): Transaction
    {
        $this->token = null;
        $this->send('POST', '/cart/items', ['listing_id' => $this->ebook, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', ['buyer' => self::buyer($email)])->assertRedirect('http://dlp.localhost/checkout/confirm');
        $this->toStripe((string) $this->send('GET', '/checkout/confirm')->getContent());
        $order = $this->inside(fn (): Transaction => Transaction::query()->latest('id')->firstOrFail());
        $this->pay($order);

        return $order;
    }

    private function pay(Transaction $order): void
    {
        $this->inside(function () use ($order): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $order->id)->sole();
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$order->id, 'checkout.session.completed', $attempt->provider_ref, $order->id, (int) $order->total_amount, 'jpy', 'pi_'.$order->id));
        });
        $this->inside(fn () => event(new PaymentConfirmed($order->id)));
    }

    private function toStripe(string $confirm): void
    {
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $response = $this->send('POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? '']);
        $response->assertStatus(303);
        $this->assertStringStartsWith('https://checkout.stripe.com/', (string) $response->headers->get('Location'));
    }

    /**
     * ご案内のメールのリンク（道筋と問い合わせだけ）
     */
    private function mailUrl(Transaction $order): string
    {
        $url = null;
        Mail::assertSent(DownloadMail::class, function (DownloadMail $mail) use ($order, &$url): bool {
            if (str_contains($mail->facts['url'], $order->id)) {
                $url = $mail->facts['url'];
            }

            return true;
        });
        $this->assertIsString($url);

        return (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    private function lookup(Transaction $order, string $email): void
    {
        $this->send('POST', '/orders', ['order_number' => strtoupper($order->id), 'email' => $email])->assertRedirect("http://dlp.localhost/orders/{$order->id}");
    }

    /**
     * 画面の最初の「ダウンロード」の押す先
     */
    private function fetchAction(string $page): string
    {
        preg_match('#action="([^"]*/p/downloads/orders/[^"]+)"#', $page, $match);
        $this->assertNotEmpty($match[1] ?? null, 'ダウンロードのボタンが無い');
        $url = html_entity_decode($match[1]);

        return (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    private function fileUrl(string $action): string
    {
        $location = (string) $this->send('POST', $action)->headers->get('Location');

        return (string) parse_url($location, PHP_URL_PATH).'?'.parse_url($location, PHP_URL_QUERY);
    }

    /**
     * @return array<string, string>
     */
    private static function buyer(string $email): array
    {
        return ['email' => $email, 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];
    }

    /**
     * Cookie（カートの合い言葉とセッション）を持ち越して送る。ブラウザと同じくセッションの ID を持ち越す（ファイルを渡す道筋は押したセッションに結びつくため）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        if ($this->session !== null) {
            $this->withCookie((string) config('session.cookie'), $this->session);
        }

        $url = "http://dlp.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        $session = $response->getCookie((string) config('session.cookie'));

        if ($session !== null) {
            $this->session = (string) $session->getValue();
        }

        return $response;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(Closure $work): mixed
    {
        tenancy()->initialize($this->tenant);

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }
}
