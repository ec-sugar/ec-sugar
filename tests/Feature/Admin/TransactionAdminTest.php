<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\OrderStatus;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の取引の閲覧。一覧と詳細に個人情報を入れず、個人情報は見た事実を記録する API でだけ返す。
 */
final class TransactionAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    // 応答のどこにも出てはいけない個人情報
    private const array PERSONAL = ['山田', 'yamada@example.com', '090-1234-5678', '千代田区', '9999-8888-7777'];

    private Operator $operator;

    private int $auditBaseline = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->auditBaseline = (int) AuditEvent::query()->max('sequence');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_list_counts_filters_sorts_and_caps_the_page_size_without_personal_data(): void
    {
        $this->tenant('txa');
        [$withCustomer, $withoutCustomer, $cancelled] = $this->sales('txa');
        $this->signIn();

        $list = $this->send('GET', '/api/tenants/txa/transactions')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('counts', ['all' => 3, 'open' => 2, 'completed' => 0, 'cancelled' => 1, 'refunded' => 0]);
        $this->assertNoPersonalData((string) $list->getContent());
        $this->assertEquals([$withCustomer => true, $withoutCustomer => false, $cancelled => false], array_column((array) $list->json('transactions'), 'has_customer', 'id'));
        $this->assertSame('物販', $this->send('GET', "/api/tenants/txa/transactions/{$withCustomer}")->json('transaction.kind_label'));

        // 絞り込み（状態・フェーズ・ID の先頭）と並べ替え
        $this->assertSame([$cancelled], $this->send('GET', '/api/tenants/txa/transactions?status=cancelled')->json('transactions.*.id'));
        $this->assertSame([$withCustomer], $this->send('GET', '/api/tenants/txa/transactions?phase=fulfillment')->json('transactions.*.id'));
        $this->assertSame([$withoutCustomer], $this->send('GET', '/api/tenants/txa/transactions?q='.strtoupper(substr($withoutCustomer, 0, 26)))->json('transactions.*.id'));
        $this->assertSame([$withCustomer, $cancelled, $withoutCustomer], $this->send('GET', '/api/tenants/txa/transactions?sort=-total_amount')->json('transactions.*.id'));

        foreach (['per_page=101', 'per_page=0', 'sort=id', 'sort='.rawurlencode('created_at;drop'), 'status=lost', 'phase=nope', 'q='.str_repeat('a', 101), 'q[]=a', 'seller_id=1'] as $query) {
            $this->send('GET', "/api/tenants/txa/transactions?{$query}")->assertStatus(422);
        }
    }

    /**
     * 注文の一覧の絞り込み: 対応の状況（入金待ち・入金済み…）で絞り、言葉（注文番号の先頭・商品名）で探す。
     * 絞り込みの状況は一覧に出す状況と必ずそろい、ほかの店の注文は混ざらない
     */
    public function test_the_list_filters_by_order_status_and_finds_orders_by_product_name(): void
    {
        $this->tenant('txo');
        $this->tenant('txo2');
        [$shipped, $awaiting, $cancelled] = $this->sales('txo');
        [$paid, $confirmed] = $this->paidAndFreeSales('txo');
        [$otherShop] = $this->paidAndFreeSales('txo2', 'そば猪口の箱');
        $this->signIn();

        $all = $this->send('GET', '/api/tenants/txo/transactions')->assertOk()
            ->assertJsonPath('order_counts', ['all' => 5, 'awaiting_payment' => 1, 'paid' => 1, 'confirmed' => 1, 'cod_pending' => 0, 'on_site_pending' => 0, 'shipped' => 1, 'completed' => 0, 'cancelled' => 1, 'refunded' => 0]);
        $listed = array_column((array) $all->json('transactions'), 'order_status', 'id');
        $this->assertEqualsCanonicalizing([$shipped => 'shipped', $awaiting => 'awaiting_payment', $cancelled => 'cancelled', $paid => 'paid', $confirmed => 'confirmed'], $listed);

        // 絞り込みの状況は、一覧に出す状況（SaleOrderService::status）とそろう
        foreach (OrderStatus::cases() as $status) {
            $ids = (array) $this->send('GET', "/api/tenants/txo/transactions?order_status={$status->value}")->assertOk()->json('transactions.*.id');
            $expected = array_keys(array_filter($listed, static fn (?string $value): bool => $value === $status->value));
            sort($ids);
            sort($expected);
            $this->assertSame($expected, $ids, $status->value);
        }

        // 言葉で探す: 商品名の一部・注文番号の先頭。LIKE の記号は文字として扱う。状況と重ねられる
        $this->assertSame([$paid], $this->send('GET', '/api/tenants/txo/transactions?q='.rawurlencode('マグ'))->json('transactions.*.id'));
        $this->assertCount(3, (array) $this->send('GET', '/api/tenants/txo/transactions?q='.rawurlencode('そば猪口'))->json('transactions'));
        $this->send('GET', '/api/tenants/txo/transactions?q='.rawurlencode('そば猪口').'&order_status=cancelled')->assertJsonPath('transactions.*.id', [$cancelled])
            ->assertJsonPath('order_counts.all', 3)->assertJsonPath('order_counts.cancelled', 1)->assertJsonPath('order_counts.paid', 0);
        $this->assertSame([$confirmed], $this->send('GET', '/api/tenants/txo/transactions?q='.strtoupper(substr($confirmed, 0, 12)))->json('transactions.*.id'));
        $this->send('GET', '/api/tenants/txo/transactions?q='.rawurlencode('%'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', '/api/tenants/txo/transactions?q='.rawurlencode('_'))->assertOk()->assertJsonPath('meta.total', 0);

        foreach (['order_status=lost', 'order_status[]=paid', 'q='.str_repeat('あ', 101)] as $query) {
            $this->send('GET', "/api/tenants/txo/transactions?{$query}")->assertStatus(422);
        }

        // ほかの店の注文は、同じ言葉でも混ざらない
        $this->send('GET', '/api/tenants/txo2/transactions?q='.rawurlencode('そば猪口'))->assertJsonPath('transactions.*.id', [$otherShop])
            ->assertJsonPath('order_counts.all', 1);
    }

    public function test_staff_get_order_filters_only_for_shops_they_can_enter(): void
    {
        $this->tenant('txs');
        $this->tenant('txs2');
        $this->paidAndFreeSales('txs');
        [$otherShop] = $this->paidAndFreeSales('txs2');

        // その店に入れないスタッフには、絞り込みの答えも返さない（ログインしていなければ 401）
        $this->send('GET', '/api/tenants/txs/transactions?order_status=paid')->assertUnauthorized();
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'txs2', 'read', 'test');
        $this->reconfirm($staff, $authenticator);
        $this->send('GET', '/api/tenants/txs2/transactions?order_status=paid')->assertOk()->assertJsonPath('transactions.*.id', [$otherShop]);
        $this->send('GET', '/api/tenants/txs/transactions?order_status=paid')->assertNotFound();
        $this->send('GET', '/api/tenants/txs/transactions?q='.rawurlencode('マグ'))->assertNotFound();
    }

    public function test_the_detail_shows_lines_ledger_and_events_without_personal_data(): void
    {
        $this->tenant('txd');
        [$sale] = $this->sales('txd');
        $this->signIn();

        $detail = $this->send('GET', "/api/tenants/txd/transactions/{$sale}")->assertOk();
        $this->assertNoPersonalData((string) $detail->getContent());

        $detail->assertJsonPath('transaction.phase', 'fulfillment')
            ->assertJsonPath('transaction.path', ['agreement', 'payment', 'fulfillment', 'acceptance', 'settlement'])
            ->assertJsonPath('transaction.has_customer', true)
            ->assertJsonPath('transaction.total_amount', 3000)
            ->assertJsonPath('transaction.lines.0.description', '古伊万里 そば猪口')
            ->assertJsonPath('transaction.lines.0.tax_rate_bp', 1000)
            ->assertJsonPath('transaction.tax_amount', 272)
            ->assertJsonPath('transaction.fulfillments.0.carrier', 'yamato')
            ->assertJsonPath('transaction.fulfillments.0.has_address', true);

        // 仕訳は貸借が合い、残高の合計は 0
        $this->assertSame(0, array_sum($detail->json('transaction.balances')));
        $this->assertContains('charge', $detail->json('transaction.journals.*.type'));
        $this->assertContains('payment', $detail->json('transaction.journals.*.type'));

        foreach ($detail->json('transaction.journals') as $journal) {
            $this->assertSame(0, array_sum(array_column($journal['postings'], 'amount')), $journal['type']);
        }

        // 記録（イベント）は順番どおり、この取引の分だけ
        $events = $detail->json('transaction.events');
        $this->assertSame('transaction.opened', $events[0]['type']);
        $this->assertContains('fulfillment.created', array_column($events, 'type'));
        $sequences = array_column($events, 'sequence');
        $sorted = $sequences;
        sort($sorted);
        $this->assertSame($sorted, $sequences);
        $this->assertSame(12, strlen($events[0]['hash']));
    }

    public function test_personal_data_is_returned_only_when_asked_and_the_viewing_is_recorded(): void
    {
        $this->tenant('txp');
        [$withCustomer, $withoutCustomer] = $this->sales('txp');
        $this->signIn();

        $personal = $this->send('GET', "/api/tenants/txp/transactions/{$withCustomer}/personal")->assertOk()
            ->assertJsonPath('personal.customer.name', '山田 太郎')
            ->assertJsonPath('personal.customer.email', 'yamada@example.com')
            ->assertJsonPath('personal.fulfillments.0.address.line1', '千代田区千代田 1-1')
            ->assertJsonPath('personal.fulfillments.0.tracking_number', '9999-8888-7777');
        $this->assertSame('no-store, private', $personal->headers->get('Cache-Control'));

        $this->send('GET', "/api/tenants/txp/transactions/{$withoutCustomer}/personal")->assertOk()
            ->assertJsonPath('personal.customer', null)
            ->assertJsonPath('personal.fulfillments', []);

        // 見た事実が、誰が・どのテナントの・どの取引を、で残る（中身の個人情報は残さない）
        $viewed = AuditEvent::query()->where('sequence', '>', $this->auditBaseline)->where('type', 'operator.personal_data_viewed')->orderBy('sequence')->get();
        $this->assertSame([$withCustomer, $withoutCustomer], $viewed->map(static fn (AuditEvent $e): mixed => $e->data['transaction_id'] ?? null)->all());
        $this->assertSame(['txp'], $viewed->pluck('tenant_id')->unique()->values()->all());
        $this->assertSame([$this->operator->id], $viewed->pluck('operator_id')->unique()->values()->all());
        $this->assertNoPersonalData((string) json_encode($viewed->pluck('data')->all(), JSON_UNESCAPED_UNICODE));
    }

    public function test_other_tenants_transactions_are_out_of_reach(): void
    {
        $this->tenant('txa2');
        $this->tenant('txb2');
        [$sale] = $this->sales('txa2');
        $this->signIn();

        foreach (["/api/tenants/txb2/transactions/{$sale}", "/api/tenants/txb2/transactions/{$sale}/personal"] as $path) {
            $this->send('GET', $path)->assertNotFound();
        }

        $this->send('GET', '/api/tenants/txb2/transactions')->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', '/api/tenants/txa2/transactions/'.strtolower((string) Str::ulid()))->assertNotFound();
        $this->send('GET', '/api/tenants/txa2/transactions/not-a-ulid')->assertNotFound();

        // 届かなかった個人情報の閲覧は記録されない（見ていないので）
        $this->assertSame(0, AuditEvent::query()->where('sequence', '>', $this->auditBaseline)->where('type', 'operator.personal_data_viewed')->count());
    }

    /**
     * 3 つの取引: 顧客と配送先のある物販（受渡まで）、顧客の無い物販、取り消した物販。
     *
     * @return list<string> 取引の ID
     */
    private function sales(string $tenant): array
    {
        return $this->inside($tenant, function (): array {
            $seller = Seller::query()->firstOrFail();
            $store = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '古伊万里 そば猪口', 'unit_amount' => 1500, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($listing, $store, 10);
            $service = app(TransactionService::class);
            $ledger = app(LedgerService::class);

            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 太郎', 'email' => 'yamada@example.com', 'phone' => '090-1234-5678', 'address' => ['zip' => '100-0001', 'line1' => '千代田区千代田 1-1']]);
            $first = $service->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 2, 1000, $store)], actor: 'system');
            $first = $service->transition($first, Phase::Payment);
            $ledger->recordPayment($first, 3000, PaymentRoute::Platform, 'pi_test');
            $first = $service->transition($first, Phase::Fulfillment);
            $line = $first->lines()->where('line_type', LineType::Item)->firstOrFail();
            $shipment = app(FulfillmentService::class)->create($first, FulfillmentMethod::Shipping, [$line->id => 2], $store, ['name' => '山田 太郎', 'zip' => '100-0001', 'line1' => '千代田区千代田 1-1']);
            app(FulfillmentService::class)->ship($shipment, 'yamato', '9999-8888-7777');

            $second = $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, $store)]);

            $third = $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, $store)]);
            $service->transition($third, Phase::Cancel);

            return [$first->id, $second->id, Transaction::query()->findOrFail($third->id)->id];
        });
    }

    /**
     * 入金済み（Payment フェーズ・1 円以上）と受付済み（合計 0 円）の物販。商品名は別にする（言葉で探す）
     *
     * @return list<string> 取引の ID（入金済み・受付済み）
     */
    private function paidAndFreeSales(string $tenant, string $title = '有田焼 マグカップ'): array
    {
        return $this->inside($tenant, function () use ($title): array {
            $seller = Seller::query()->firstOrFail();
            $store = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => $title, 'unit_amount' => 2000, 'status' => ListingStatus::Active]);
            $free = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '無料の見本', 'unit_amount' => 0, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($listing, $store, 10);
            app(StockService::class)->receive($free, $store, 10);
            $service = app(TransactionService::class);

            $paid = $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, $store)]);
            $paid = $service->transition($paid, Phase::Payment);
            $confirmed = $service->open('sale', $seller, null, [LineDraft::fromListing($free, 1, 1000, $store)]);
            $confirmed = $service->transition($confirmed, Phase::Payment);

            return [$paid->id, $confirmed->id];
        });
    }

    /**
     * 応答の JSON は日本語を \uXXXX の形で送るので、生の文字列では日本語の名前や住所を見つけられない。
     * 一度読み解いて、日本語のまま書き直してから探す
     */
    private function assertNoPersonalData(string $content): void
    {
        $decoded = json_decode($content, true);
        $text = $decoded === null ? $content : (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach (self::PERSONAL as $value) {
            $this->assertStringNotContainsString($value, $text);
        }
    }

    private function signIn(): void
    {
        [$this->operator] = $this->enrolledOperator();
    }
}
