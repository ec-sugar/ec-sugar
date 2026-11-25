<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Media\Models\ShopMedia;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Party\Services\CustomerSessions;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Orders\OrderReader;
use Dom\HTMLDocument;
use EcSugar\PluginOrderPrint\Models\OrderPrintReceipt;
use EcSugar\PluginOrderPrint\ReceiptOrderLink;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「納品書・領収書」。管理画面は注文の書類の口（核）で納品書（まとめて）と領収書、お客さんはご注文の確認とマイページから払い済みの注文の領収書。
 * 使わない店では何も出ない。払っていない注文の領収書は出ない。ほかの店の注文・署名の違うリンクは断る。2 回目から「再発行」。
 * 金額は「¥5,400」の形、品名や宛名は文字として出る。印刷のページはスクリプトを動かさない CSP。届け先を見る管理画面は運用の段階からで、見た記録が残る
 */
final class OrderPrintAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string DOCS = '/api/tenants/opa/apps/order-print/documents';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->tenant('opa', 'みどり雑貨');
        $this->tenant('opb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_an_unused_app_shows_no_buttons_pages_or_links(): void
    {
        $this->enrolledOperator();
        $sale = $this->order('opa', paid: true);

        $this->send('GET', '/api/tenants/opa/order-documents')->assertOk()->assertExactJson(['documents' => []]);
        $this->send('GET', self::DOCS."/delivery_note?ids={$sale}")->assertNotFound();
        $this->assertStringNotContainsString('order-link-order-print', $this->orderPage('opa', $sale));

        $this->use('opa');
        $this->send('GET', '/api/tenants/opa/order-documents')->assertOk()->assertExactJson(['documents' => [
            ['plugin' => 'order-print', 'key' => 'delivery_note', 'label' => '納品書', 'bulk' => true],
            ['plugin' => 'order-print', 'key' => 'receipt', 'label' => '領収書', 'bulk' => false],
        ]]);
        $this->assertStringContainsString('order-link-order-print', $this->orderPage('opa', $sale));
    }

    public function test_staff_print_delivery_notes_for_several_orders_on_one_page(): void
    {
        $this->use('opa');
        $this->inside('opa', fn () => app(ShopSettingsService::class)->setLegal(['items' => [
            ['label' => '販売業者', 'value' => '<i>みどり雑貨</i> 株式会社'],
            ['label' => '所在地', 'value' => '東京都渋谷区神宮前 1-1'],
        ]], 'test'));
        [$owner] = $this->enrolledOperator();
        $first = $this->order('opa', paid: true);
        $second = $this->order('opa', paid: true, name: '佐藤 太郎');

        $response = $this->send('GET', self::DOCS.'/delivery_note?ids='.strtoupper($first).','.$second)->assertOk();
        $html = (string) $response->getContent();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        // 2 件が渡した順に 1 枚ずつ。届け先・品名（文字として）・単価・送料・合計・税率ごとの内訳・発行者
        $this->assertSame([strtoupper($first), strtoupper($second)], array_map(static fn ($n) => $n->textContent, iterator_to_array($dom->querySelectorAll('[data-test="delivery-note"] [data-test="order-number"]'))));
        foreach (['山田 花子 様', '佐藤 太郎 様', '〒150-0001', '東京都渋谷区神宮前 1-2-3 2 階', '&lt;b&gt;ニット&lt;/b&gt;', '¥4,400', '送料', '¥500', '¥9,300', '10% 対象', '（うち消費税 ¥845）', '&lt;i&gt;みどり雑貨&lt;/i&gt; 株式会社', '東京都渋谷区神宮前 1-1'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertNull($dom->querySelector('b, i, script'));
        $this->assertStringNotContainsString('円', (string) $dom->querySelector('[data-test="totals"]')?->textContent);

        // 印刷の CSP: スクリプトは動かさず、見た目はこのページの nonce の <style> だけ。保存させない
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $nonce = (string) $dom->querySelector('style')?->getAttribute('nonce');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $nonce);
        $this->assertStringContainsString("script-src 'none'", $csp);
        $this->assertStringContainsString("style-src 'nonce-{$nonce}'", $csp);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // 届け先を見たことは、注文の個人情報の表示と同じく監査ログに残る
        $viewed = AuditEvent::query()->where('type', 'operator.personal_data_viewed')->latest('sequence')->firstOrFail();
        $this->assertSame('order_document', $viewed->data['subject']);
        $this->assertSame([$first, $second], $viewed->data['transaction_ids']);
        $this->assertSame($owner->id, $viewed->operator_id);
    }

    /**
     * 軽減税率（ADR 0063）: 率ごとの対価と消費税額は核の内訳（率ごとに 1 回だけ切り捨て）、軽減の品に「※」、率ごとに分けた値引きには率を添える
     */
    public function test_a_mixed_rate_order_shows_each_rate_and_marks_the_reduced_items(): void
    {
        $this->use('opa');
        $this->enrolledOperator();
        $sale = $this->inside('opa', function (): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $cookie = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'クッキー', 'unit_amount' => 1080, 'status' => 'active', 'tax_class' => 'reduced']);
            $tote = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'トート', 'unit_amount' => 1100, 'status' => 'active']);
            app(StockService::class)->receive($cookie, $location, 5);
            app(StockService::class)->receive($tote, $location, 5);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [
                LineDraft::fromListing($cookie, 2, $cookie->taxRateBp(), $location),
                LineDraft::fromListing($tote, 1, $tote->taxRateBp(), $location),
                LineDraft::adjustment('discount', 'クーポン（SWEETS）', -100, 800),
                LineDraft::adjustment('discount', 'クーポン（SWEETS）', -50, 1000),
                LineDraft::adjustment('shipping', '送料', 500, 1000),
            ], actor: 'test');
            CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);

            return $sale->id;
        });

        $html = (string) $this->send('GET', self::DOCS."/delivery_note?ids={$sale}")->assertOk()->getContent();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $items = array_map(static fn ($n): string => trim((string) $n->querySelector('td')?->textContent), iterator_to_array($dom->querySelectorAll('[data-test="line"]')));
        $this->assertSame(['クッキー ※', 'トート'], $items);
        $taxes = array_map(static fn ($n): string => trim((string) preg_replace('/\s+/u', ' ', (string) $n->textContent)), iterator_to_array($dom->querySelectorAll('[data-test="tax-row"]')));
        // 8%: 2,160 − 100 = 2,060（うち 152）、10%: 1,100 − 50 + 500 = 1,550（うち 140）
        $this->assertSame(['8%※ 対象¥2,060（うち消費税 ¥152）', '10% 対象¥1,550（うち消費税 ¥140）'], $taxes);
        foreach (['クーポン（SWEETS）（8% 対象）', 'クーポン（SWEETS）（10% 対象）', '※ は軽減税率（8%）の対象です。', '¥3,610'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
    }

    public function test_staff_issue_a_receipt_and_the_second_one_is_marked_reissued(): void
    {
        $this->use('opa', ['registration_number' => 'T1234567890123', 'proviso' => 'お品代として（雑貨）']);
        [$owner] = $this->enrolledOperator();
        $sale = $this->order('opa', paid: true);
        $url = self::DOCS."/receipt?ids={$sale}";

        // 開くと宛名の欄（最初の値は注文者の名前）と但し書き（設定の既定）。まだ数えない
        $form = (string) $this->send('GET', $url)->assertOk()->getContent();
        $this->assertStringContainsString('value="山田 花子"', $form);
        $this->assertStringContainsString('value="お品代として（雑貨）"', $form);
        $this->assertStringNotContainsString('data-test="receipt"', $form);
        $this->assertSame(0, $this->inside('opa', fn () => OrderPrintReceipt::query()->count()));

        $html = (string) $this->send('POST', $url, ['to' => '株式会社 <b>みどり</b>', 'proviso' => '文具代として'])->assertOk()->getContent();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $this->assertSame('株式会社 <b>みどり</b> 様', $dom->querySelector('[data-test="receipt-to-name"]')?->textContent);
        $this->assertSame('文具代として', $dom->querySelector('[data-test="receipt-proviso-text"]')?->textContent);
        $this->assertStringContainsString('¥9,300-', (string) $dom->querySelector('[data-test="receipt-amount"]')?->textContent);
        $this->assertSame('登録番号 T1234567890123', trim((string) $dom->querySelector('[data-test="registration-number"]')?->textContent));
        $this->assertCount(1, $dom->querySelectorAll('[data-test="tax-row"]'));
        $this->assertNull($dom->querySelector('[data-test="reissue"]'));
        $this->assertNull($dom->querySelector('[data-test="receipt"] b'));

        // 2 回目から「再発行」。回数は App の表に、記録は誰がしたかと回数だけ（宛名は残さない）
        $again = HTMLDocument::createFromString((string) $this->send('POST', $url, ['to' => '', 'proviso' => '文具代として'])->assertOk()->getContent(), LIBXML_NOERROR);
        $this->assertSame('再発行', $again->querySelector('[data-test="reissue"]')?->textContent);
        $this->assertSame(2, $this->inside('opa', fn () => OrderPrintReceipt::query()->sole()->issued_count));
        $events = $this->inside('opa', fn () => DomainEvent::query()->where('type', 'order_print.receipt_issued')->orderBy('sequence')->get());
        $this->assertSame([['number' => 1], ['number' => 2]], $events->pluck('payload')->all());
        $this->assertSame([$owner->actor(), $owner->actor()], $events->pluck('actor')->all());

        // 長すぎる・改行のある宛名、空の但し書きは断り、数えない
        foreach ([['to' => str_repeat('あ', 41), 'proviso' => 'x'], ['to' => "一\n二", 'proviso' => 'x'], ['to' => 'a', 'proviso' => ''], ['to' => ['a'], 'proviso' => 'x']] as $bad) {
            $this->send('POST', $url, $bad)->assertStatus(422)->assertSee('data-test="receipt-error"', false);
        }
        $this->assertSame(2, $this->inside('opa', fn () => OrderPrintReceipt::query()->sole()->issued_count));
    }

    public function test_unpaid_cancelled_and_free_orders_get_no_receipt(): void
    {
        $this->use('opa');
        $this->enrolledOperator();
        $unpaid = $this->order('opa', paid: false);
        $cancelled = $this->order('opa', paid: false);
        $this->inside('opa', fn () => app(TransactionService::class)->transition(Transaction::query()->findOrFail($cancelled), Phase::Cancel, 'test'));
        // 代金引換で発送したが、まだ入金を確かめていない注文（ADR 0074。対応の状況は「発送済み」）
        $cod = $this->order('opa', paid: false);
        $this->inside('opa', function () use ($cod): void {
            $sale = Transaction::query()->findOrFail($cod);
            PaymentAttempt::create(['transaction_id' => $cod, 'provider' => 'direct', 'provider_ref' => 'direct-'.$cod, 'checkout_url' => '', 'amount' => (int) $sale->total_amount,
                'currency' => 'JPY', 'status' => 'awaiting', 'expires_at' => now(), 'kind' => 'direct', 'methods' => 'cod', 'method' => 'cod']);
            app(TransactionService::class)->transition($sale, Phase::Payment, 'test');
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($cod), (string) Fulfillment::query()->where('transaction_id', $cod)->value('id'), 'yamato', null, 'test');
            $this->assertSame('shipped', app(SaleOrderService::class)->status(Transaction::query()->findOrFail($cod))->value);
        });

        foreach ([$unpaid, $cancelled, $cod] as $id) {
            $this->send('GET', self::DOCS."/receipt?ids={$id}")->assertStatus(409)->assertSee('data-test="print-refused"', false);
            $this->send('POST', self::DOCS."/receipt?ids={$id}", ['to' => 'a', 'proviso' => 'b'])->assertStatus(409);
            $this->assertStringNotContainsString('order-link-order-print', $this->orderPage('opa', $id));
        }

        // 納品書は払う前でも出せる（発送の準備のため）
        $this->send('GET', self::DOCS."/delivery_note?ids={$unpaid}")->assertOk();
        $this->assertSame(0, $this->inside('opa', fn () => OrderPrintReceipt::query()->count()));
    }

    public function test_only_operators_of_the_shop_open_the_pages_and_only_its_orders(): void
    {
        $this->use('opa');
        $this->use('opb');
        $sale = $this->order('opa', paid: true);
        $foreign = $this->order('opb', paid: true);

        [$reader] = $this->enrolledOperator('reader@example.com', 'staff');
        app(OperatorAccess::class)->grant($reader, 'opa', 'read', 'test');
        // 閲覧の段階は、ボタンの一覧は見えるが、届け先や名前の載るページは開けない
        $this->send('GET', '/api/tenants/opa/order-documents')->assertOk()->assertJsonCount(2, 'documents');
        $this->send('GET', self::DOCS."/delivery_note?ids={$sale}")->assertForbidden();
        $this->send('POST', self::DOCS."/receipt?ids={$sale}", ['to' => 'a', 'proviso' => 'b'])->assertForbidden();
        // 与えられていない店は 404
        $this->send('GET', "/api/tenants/opb/apps/order-print/documents/delivery_note?ids={$foreign}")->assertNotFound();

        // ログインし直す（オーナーで）
        $this->send('POST', '/auth/logout')->assertSuccessful();
        $this->enrolledOperator('owner@example.com');
        // ほかの店の注文・無い ID・管理画面で作った物販でない取引は 404。形の違う ID・領収書のまとめは 422
        $this->send('GET', self::DOCS."/delivery_note?ids={$sale},{$foreign}")->assertNotFound();
        $this->send('GET', self::DOCS.'/delivery_note?ids=01aaaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound();
        $this->send('GET', self::DOCS.'/no_such?ids='.$sale)->assertNotFound();
        $this->send('GET', self::DOCS."/delivery_note?ids={$sale}'--")->assertUnprocessable();
        $this->send('GET', self::DOCS.'/delivery_note')->assertUnprocessable();
        $this->send('GET', self::DOCS."/receipt?ids={$sale},".$this->order('opa', paid: true))->assertUnprocessable();
        $this->send('GET', self::DOCS.'/delivery_note?ids='.implode(',', array_fill(0, 50, strtoupper($sale))))->assertOk();
    }

    public function test_customers_open_a_receipt_from_the_signed_order_page_link(): void
    {
        $this->use('opa');
        $this->use('opb');
        $sale = $this->order('opa', paid: true);

        // ご注文の確認の画面（署名つきのリンクで開いた人）に「領収書」。4 つのテーマのどれでも
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            Tenant::query()->findOrFail('opa')->forceFill(['theme' => $theme])->save();
            $this->assertNotNull(HTMLDocument::createFromString($this->orderPage('opa', $sale), LIBXML_NOERROR)->querySelector('a[data-test="order-link-order-print"][target="_blank"]'), $theme);
        }
        $link = $this->receiptLink('opa', $sale);

        // 開くと宛名の欄（空。名前を出さない）。但し書きの欄は無い
        $form = $this->shop('opa', 'GET', $link)->assertOk();
        $this->assertStringContainsString('no-referrer', (string) $form->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("script-src 'none'", (string) $form->headers->get('Content-Security-Policy'));
        $html = (string) $form->getContent();
        $this->assertStringContainsString('name="to" value=""', $html);
        $this->assertStringNotContainsString('山田', $html);
        $this->assertStringNotContainsString('name="proviso"', $html);

        // 出す（送られた但し書きは読まない）。2 回目から「再発行」
        $first = HTMLDocument::createFromString((string) $this->shop('opa', 'POST', $link, ['to' => '<script>alert(1)</script>', 'proviso' => '偽の但し書き'])->assertOk()->getContent(), LIBXML_NOERROR);
        $this->assertSame('<script>alert(1)</script> 様', $first->querySelector('[data-test="receipt-to-name"]')?->textContent);
        $this->assertSame('お品代として', $first->querySelector('[data-test="receipt-proviso-text"]')?->textContent);
        $this->assertNull($first->querySelector('body script'));
        $this->assertNull($first->querySelector('[data-test="reissue"]'));
        $second = HTMLDocument::createFromString((string) $this->shop('opa', 'POST', $link, ['to' => ''])->assertOk()->getContent(), LIBXML_NOERROR);
        $this->assertNotNull($second->querySelector('[data-test="reissue"]'));
        $this->assertSame(['storefront', 'storefront'], $this->inside('opa', fn () => DomainEvent::query()->where('type', 'order_print.receipt_issued')->pluck('actor')->all()));
    }

    public function test_tampered_expired_and_foreign_receipt_links_are_refused(): void
    {
        $this->use('opa');
        $this->use('opb');
        $sale = $this->order('opa', paid: true);
        $other = $this->order('opa', paid: true);
        $link = $this->receiptLink('opa', $sale);
        $late = $this->receiptLink('opa', $other);

        foreach ([
            '署名の書き換え' => (string) preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $link),
            '別の注文に付け替え' => str_replace($sale, $other, $link),
            '店の名前の書き換え' => str_replace('shop=opa', 'shop=opb', $link),
            '署名なし' => "/p/order-print/receipt/{$sale}",
        ] as $case => $path) {
            $this->shop('opa', 'GET', $path)->assertForbidden();
            $this->shop('opa', 'POST', $path, ['to' => 'a'])->assertForbidden();
            $this->assertSame(0, $this->inside('opa', fn () => OrderPrintReceipt::query()->count()), $case);
        }

        // 同じリンクをほかの店のホストで（注文はその店に無い）
        $this->shop('opb', 'GET', $link)->assertForbidden();
        // 払った後に取り消した注文は、リンクがあっても出さない
        $this->inside('opa', fn () => Transaction::query()->findOrFail($sale)->forceFill(['status' => 'refunded'])->save());
        $this->shop('opa', 'POST', $link, ['to' => 'a'])->assertStatus(409);
        // 期限切れのリンクは断る。ご注文の画面を開き直すと、新しいリンクが出る
        $this->travel(ReceiptOrderLink::LINK_HOURS + 1)->hours();
        $this->shop('opa', 'GET', $late)->assertForbidden();
        $this->shop('opa', 'GET', $this->receiptLink('opa', $other))->assertOk();
    }

    public function test_the_logo_is_a_photo_of_the_shop_and_shows_only_when_turned_on(): void
    {
        $logo = $this->inside('opa', fn (): string => ShopMedia::query()->create(['alt' => 'ロゴ', 'width' => 600, 'height' => 200, 'checksum' => str_repeat('a', 64), 'bytes' => 1000])->id);
        $foreign = $this->inside('opb', fn (): string => ShopMedia::query()->create(['alt' => 'ほかの店', 'width' => 600, 'height' => 200, 'checksum' => str_repeat('b', 64), 'bytes' => 1000])->id);

        // 写真の欄は、この店の写真の ID だけ（ほかの店の写真・形の違う値は断る）
        foreach ([$foreign, 'logo.png', true] as $bad) {
            try {
                $this->use('opa', ['logo' => $bad]);
                $this->fail('断るはずの写真: '.var_export($bad, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('values.logo', $e->errors());
            }
        }

        $this->use('opa', ['logo' => strtoupper($logo)]);
        $this->assertSame($logo, $this->inside('opa', fn () => app(PluginSettings::class)->publicValues('order-print')['logo'] ?? null));
        $this->enrolledOperator();
        $sale = $this->order('opa', paid: true);
        $logoOf = static fn (string $html): ?string => HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-test="issuer-logo"]')?->getAttribute('src');

        // 「ロゴを出す」が切のうちは出さない。入にすると、そのホストで読める写真の経路で出す
        $this->assertNull($logoOf((string) $this->send('GET', self::DOCS."/delivery_note?ids={$sale}")->getContent()));
        $this->use('opa', ['show_logo' => true]);
        $this->assertSame("/api/tenants/opa/shop-media/{$logo}/medium", $logoOf((string) $this->send('GET', self::DOCS."/delivery_note?ids={$sale}")->getContent()));
        $this->assertSame("/media/shop/{$logo}/medium", $logoOf((string) $this->shop('opa', 'POST', $this->receiptLink('opa', $sale), ['to' => 'a'])->assertOk()->getContent()));

        // 写真を消していたら出さない（書類は開ける）
        $this->inside('opa', fn () => ShopMedia::query()->whereKey($logo)->delete());
        $this->assertNull($logoOf((string) $this->send('GET', self::DOCS."/delivery_note?ids={$sale}")->assertOk()->getContent()));
    }

    public function test_members_see_the_receipt_link_in_their_order_history(): void
    {
        $this->use('opa');
        $paid = $this->order('opa', paid: true);
        $unpaid = $this->order('opa', paid: false);
        // メールのコードでログインした会員（コードの儀式は会員のテストが確かめている）
        $token = $this->inside('opa', function (): string {
            $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'test');
            $account->forceFill(['member_since' => now()])->save();

            return app(CustomerSessions::class)->start($account);
        });

        $this->withCookie(MemberCookie::NAME, $token);
        $html = (string) $this->get('http://opa.localhost/account')->assertOk()->getContent();
        tenancy()->end();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $links = iterator_to_array($dom->querySelectorAll('[data-test="order-link-order-print"]'));
        $this->assertCount(1, $links);
        $href = (string) $links[0]->getAttribute('href');
        $this->assertStringContainsString("/p/order-print/receipt/{$paid}?", $href);
        $this->assertStringNotContainsString($unpaid, $href);
        $this->shop('opa', 'GET', $href)->assertOk()->assertSee('data-test="receipt-form"', false);
    }

    /**
     * App を使う（設定も入れる）
     *
     * @param  array<string, bool|string>  $settings
     */
    private function use(string $shop, array $settings = []): void
    {
        $this->inside($shop, function () use ($settings): void {
            app(PluginStates::class)->set('order-print', true, 'test');

            if ($settings !== []) {
                app(PluginSettings::class)->update('order-print', $settings, 'test');
            }
        });
    }

    /**
     * ショップの注文（ニット 2 着 ¥4,400・送料 ¥500。届け先つき）。paid なら支払いを記録して入金済みに
     */
    private function order(string $shop, bool $paid, string $name = '山田 花子'): string
    {
        return $this->inside($shop, function () use ($paid, $name): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '<b>ニット</b>', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 5);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => $name, 'email' => 'hanako@example.com']);
            $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'test');
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [
                LineDraft::fromListing($listing, 2, 1000, $location),
                LineDraft::adjustment('shipping', '送料', 500, 1000),
            ], actor: 'test', customerAccount: $account);
            $line = $sale->lines()->where('line_type', LineType::Item)->firstOrFail();
            app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => 2], $location, [
                'name' => $name, 'zip' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => '2 階',
            ], 'test');
            CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);

            if ($paid) {
                app(LedgerService::class)->recordPayment($sale, $sale->total_amount, PaymentRoute::Platform, 'pi_'.$sale->id, 'test');
                app(TransactionService::class)->transition($sale, Phase::Payment, 'test');
            }

            return $sale->id;
        });
    }

    /**
     * ご注文の確認の画面（確認のメールの署名つきのリンクで開く）
     */
    private function orderPage(string $shop, string $sale): string
    {
        $link = $this->inside($shop, fn (): string => OrderReader::link(Transaction::query()->findOrFail($sale), "http://{$shop}.localhost"));

        return (string) $this->shop($shop, 'GET', (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY))->assertOk()->getContent();
    }

    /**
     * ご注文の確認の画面に出る「領収書」のリンク
     */
    private function receiptLink(string $shop, string $sale): string
    {
        $dom = HTMLDocument::createFromString($this->orderPage($shop, $sale), LIBXML_NOERROR);
        $href = (string) $dom->querySelector('[data-test="order-link-order-print"]')?->getAttribute('href');
        $this->assertStringStartsWith("/p/order-print/receipt/{$sale}?", $href);

        return $href;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $shop, string $method, string $path, array $data = []): TestResponse
    {
        $url = "http://{$shop}.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();

        return $response;
    }
}
