<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 注文の操作: 発送の登録と完了。入金の前は発送しない、二度目は状態で断る、完了で在庫を確定する。
 */
final class OrderOperationsTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private Operator $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('ooa');
        [$this->operator] = $this->enrolledOperator();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_a_paid_order_is_shipped_in_parts_and_completed(): void
    {
        [$sale, $first, $second, $listing] = $this->order('ooa', paid: false);
        $url = "/api/tenants/ooa/transactions/{$sale}";

        // 入金の前は発送できない（画面にも発送の操作を出さない）
        $this->send('GET', $url)->assertOk()->assertJsonPath('transaction.order_status', 'awaiting_payment')->assertJsonPath('transaction.actions.ship', false);
        $this->send('POST', "{$url}/ship", ['fulfillment_id' => $first, 'carrier' => null, 'tracking_number' => null])
            ->assertStatus(409)->assertJsonPath('user_message', '入金の前は発送できません');

        $this->pay('ooa', $sale);
        $this->send('GET', $url)->assertJsonPath('transaction.order_status', 'paid')->assertJsonPath('transaction.actions.ship', true)
            ->assertJsonPath('transaction.paid_at', fn ($v) => is_string($v))
            ->assertJsonPath('transaction.tax_by_rate', [['rate_bp' => 1000, 'gross' => 8800, 'tax' => 800, 'reduced' => false]]);

        // 1 つ目を発送: 取引は受渡へ、まだ 1 つ残るので完了はできない
        $this->send('POST', "{$url}/ship", ['fulfillment_id' => strtoupper($first), 'carrier' => 'yamato', 'tracking_number' => '1234-5678-9012'])->assertOk()
            ->assertJsonPath('transaction.order_status', 'shipped')
            ->assertJsonPath('transaction.phase', 'fulfillment')
            ->assertJsonPath('transaction.actions.ship', true)
            ->assertJsonPath('transaction.actions.complete', false)
            ->assertJsonPath('transaction.shipped_at', fn ($v) => is_string($v));
        $this->send('POST', "{$url}/complete")->assertStatus(409)->assertJsonPath('user_message', 'まだ発送していない配送があります');
        // 同じ受渡をもう一度（二度押し）
        $this->send('POST', "{$url}/ship", ['fulfillment_id' => $first, 'carrier' => 'yamato', 'tracking_number' => null])
            ->assertStatus(409)->assertJsonPath('user_message', 'この配送はもう発送済みです');

        $this->send('POST', "{$url}/ship", ['fulfillment_id' => $second, 'carrier' => null, 'tracking_number' => null])->assertOk()->assertJsonPath('transaction.actions.complete', true);
        $this->send('POST', "{$url}/complete")->assertOk()->assertJsonPath('transaction.order_status', 'completed')->assertJsonPath('transaction.status', 'completed');
        $this->send('POST', "{$url}/complete")->assertStatus(409);

        $this->inside('ooa', function () use ($sale, $first, $listing): void {
            // 完了で在庫を確定（確保が減り、数も減る）
            $stocks = InventoryStock::query()->where('listing_id', $listing)->get();
            $this->assertSame([0, 0], $stocks->pluck('reserved')->map(fn ($n) => (int) $n)->all());
            $this->assertSame(2, (int) $stocks->sum('quantity')); // 2 + 2 から売った 2 点
            $this->assertSame(['yamato', '1234-5678-9012'], [Fulfillment::query()->findOrFail($first)->carrier, Fulfillment::query()->findOrFail($first)->tracking_number]);
            $this->assertSame(Phase::Settlement, Transaction::query()->findOrFail($sale)->phase);
            $this->assertStringStartsWith('operator:', (string) DomainEvent::query()->where('type', 'fulfillment.shipped')->first()?->actor);
        });

        // 一覧にも対応の状況
        $this->send('GET', '/api/tenants/ooa/transactions')->assertJsonPath('transactions.0.order_status', 'completed');
    }

    public function test_malformed_or_foreign_requests_are_refused(): void
    {
        [$sale, $first] = $this->order('ooa', paid: true);
        [$other, $otherFulfillment] = $this->order('ooa', paid: true);
        $url = "/api/tenants/ooa/transactions/{$sale}";

        foreach ([
            'carrier' => ['fulfillment_id' => $first, 'carrier' => 'kuroneko', 'tracking_number' => null],
            'tracking_number' => ['fulfillment_id' => $first, 'carrier' => null, 'tracking_number' => '<script>'],
            'tracking_number ' => ['fulfillment_id' => $first, 'carrier' => null, 'tracking_number' => str_repeat('1', 65)],
            'fulfillment_id' => ['fulfillment_id' => 'not-an-id', 'carrier' => null, 'tracking_number' => null],
            'fulfillment_id ' => ['carrier' => null, 'tracking_number' => null],
        ] as $field => $input) {
            $this->send('POST', "{$url}/ship", $input)->assertStatus(422)->assertJsonValidationErrors(trim($field));
        }

        // 別の注文の受渡を、この注文として発送しない
        $this->send('POST', "{$url}/ship", ['fulfillment_id' => $otherFulfillment, 'carrier' => null, 'tracking_number' => null])
            ->assertStatus(409)->assertJsonPath('user_message', 'この注文の配送ではありません');
        $this->inside('ooa', fn () => $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($other)->phase));

        // 取り消した注文は動かせない。別の店・止めた運営・未ログイン
        $this->inside('ooa', fn () => app(TransactionService::class)->transition(Transaction::query()->findOrFail($sale), Phase::Cancel));
        $this->send('POST', "{$url}/ship", ['fulfillment_id' => $first, 'carrier' => null, 'tracking_number' => null])->assertStatus(409);
        $this->tenant('oob');
        $this->send('POST', "/api/tenants/oob/transactions/{$other}/complete")->assertNotFound();
        $this->operator->update(['disabled_at' => now()]);
        $this->send('POST', "/api/tenants/ooa/transactions/{$other}/ship", ['fulfillment_id' => $otherFulfillment, 'carrier' => null, 'tracking_number' => null])->assertUnauthorized();
        $this->inside('ooa', fn () => $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($other)->phase));
    }

    /**
     * 2 つの拠点から 1 点ずつ送る注文（4,400 × 2）
     *
     * @return array{0: string, 1: string, 2: string, 3: string} 取引・受渡 1・受渡 2・商品
     */
    private function order(string $shop, bool $paid): array
    {
        return $this->inside($shop, function () use ($paid): array {
            $seller = Seller::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $a = Location::query()->orderBy('id')->firstOrFail();
            $b = Location::create(['seller_id' => $seller->id, 'name' => '第二倉庫', 'kind' => 'warehouse']);
            app(StockService::class)->receive($listing, $a, 2);
            app(StockService::class)->receive($listing, $b, 2);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $a), LineDraft::fromListing($listing, 1, 1000, $b)], actor: 'test');
            $fulfillments = [];
            foreach ($sale->lines()->where('line_type', LineType::Item)->orderBy('position')->get() as $line) {
                $location = Location::query()->findOrFail($line->location_id);
                $fulfillments[] = app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => 1], $location, ['name' => '山田 花子'], 'test')->id;
            }

            if ($paid) {
                $this->payInside($sale);
            }

            return [$sale->id, $fulfillments[0], $fulfillments[1], $listing->id];
        });
    }

    private function pay(string $shop, string $sale): void
    {
        $this->inside($shop, fn () => $this->payInside(Transaction::query()->findOrFail($sale)));
    }

    private function payInside(Transaction $sale): void
    {
        app(LedgerService::class)->recordPayment($sale, $sale->total_amount, PaymentRoute::Platform, 'pi_test', 'test');
        app(TransactionService::class)->transition($sale, Phase::Payment, 'test');
    }
}
