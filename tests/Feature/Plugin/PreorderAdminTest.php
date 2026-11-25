<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PlaceOrder;
use Carbon\CarbonImmutable;
use EcSugar\PluginPreorder\Models\PreorderListing;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 予約販売の App の管理画面（ADR 0073）: 商品の画面の欄で予約販売にし、App の画面で予約の数・上限・発売日を見て発売日を変える。
 * 変えるのは運用の段階から（閲覧の人は見るだけ）。ほかの店の商品と予約は読めず変えられない。発売日の前の予約の注文は「発送する」を出さない
 */
final class PreorderAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private string $figure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        $this->travelTo(new CarbonImmutable('2026-11-10 10:00:00', 'Asia/Tokyo'));
        $this->configureAdminHost();
        $this->tenant('pam');
        $this->figure = $this->inside('pam', function (): string {
            app(PluginStates::class)->set('preorder', true, 'test');

            return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '限定のフィギュア', 'unit_amount' => 8800, 'status' => 'active'])->id;
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_owner_starts_a_preorder_on_the_product_and_changes_the_release_date_on_the_app_page(): void
    {
        [$owner] = $this->enrolledOperator();
        $panel = "/api/tenants/pam/listings/{$this->figure}/apps/preorder";

        // 形の違う値は欄の名前つきで断る（核の形と App の決まりの両方）
        $this->send('PUT', $panel, ['values' => ['enabled' => true, 'release_date' => '11/20']])->assertUnprocessable()->assertJsonValidationErrors(['values.release_date']);
        $this->send('PUT', $panel, ['values' => ['enabled' => true, 'release_date' => '2026-11-09']])->assertUnprocessable()->assertJsonValidationErrors(['values.release_date']);
        $this->send('PUT', $panel, ['values' => ['enabled' => true, 'release_date' => '2026-11-20', 'ends_on' => '2026-11-25']])->assertUnprocessable()->assertJsonValidationErrors(['values.ends_on']);

        $this->send('PUT', $panel, ['values' => ['enabled' => true, 'release_date' => '2026-11-20', 'capacity' => '3']])->assertOk()
            ->assertJsonPath('app.note', '予約 0 点（上限 3 点）・発売日 11月20日。');
        $started = $this->inside('pam', fn () => DomainEvent::query()->where('type', 'preorder.started')->sole());
        $this->assertSame([$owner->actor(), '2026-11-20', 3], [$started->actor, $started->payload['release_date'], $started->payload['capacity']]);

        // App の画面: 受付中の一覧に、予約の数・上限・発売日
        $list = $this->send('GET', '/api/tenants/pam/apps/preorder/records?filter=open')->assertOk();
        $list->assertJsonPath('meta.total', 1)->assertJsonPath('rows.0.cells.release', '2026-11-20')->assertJsonPath('rows.0.cells.capacity', 3)->assertJsonPath('rows.0.cells.taken', 0)
            ->assertJsonPath('rows.0.cells.status.label', '受付中')->assertJsonPath('form', null);
        $id = (string) $list->json('rows.0.id');

        // 直す欄で発売日を変える（App の決まりの誤りは欄の名前で）
        $this->send('PUT', "/api/tenants/pam/apps/preorder/records/{$id}", ['values' => ['release_date' => '2026-11-01']])->assertUnprocessable()->assertJsonValidationErrors(['values.release_date']);
        $this->send('PUT', "/api/tenants/pam/apps/preorder/records/{$id}", ['values' => ['release_date' => '2026-11-27']])->assertOk();
        $this->assertSame('2026-11-27', $this->inside('pam', fn () => PreorderListing::query()->findOrFail($id)->releaseDate()));
        $this->assertSame(1, $this->inside('pam', fn () => DomainEvent::query()->where('type', 'preorder.release_date_changed')->count()));

        // やめる（行は残し、止めた予約販売は直す欄を出さない）
        $this->send('PUT', $panel, ['values' => ['enabled' => false]])->assertOk()->assertJsonPath('app.note', null);
        $this->send('GET', "/api/tenants/pam/apps/preorder/records/{$id}")->assertOk()->assertJsonPath('form', null)->assertJsonPath('record.facts.1.value.label', '予約販売をやめた');
        $this->send('PUT', "/api/tenants/pam/apps/preorder/records/{$id}", ['values' => ['release_date' => '2026-11-28']])->assertNotFound();
    }

    public function test_read_level_staff_only_look_and_another_shop_cannot_reach_the_preorders(): void
    {
        [$owner, $key] = $this->enrolledOperator();
        $this->reconfirm($owner, $key);
        $this->tenant('pan');
        $this->inside('pan', fn () => app(PluginStates::class)->set('preorder', true, 'test'));
        $panel = "/api/tenants/pam/listings/{$this->figure}/apps/preorder";
        $this->send('PUT', $panel, ['values' => ['enabled' => true, 'release_date' => '2026-11-20']])->assertOk();
        $id = (string) $this->send('GET', '/api/tenants/pam/apps/preorder/records')->json('rows.0.id');

        // ほかの店の経路からは、この店の商品も予約も無いのと同じ
        $this->send('PUT', "/api/tenants/pan/listings/{$this->figure}/apps/preorder", ['values' => ['enabled' => true, 'release_date' => '2026-12-20']])->assertNotFound();
        $this->send('GET', "/api/tenants/pan/apps/preorder/records/{$id}")->assertNotFound();
        $this->send('PUT', "/api/tenants/pan/apps/preorder/records/{$id}", ['values' => ['release_date' => '2026-12-20']])->assertNotFound();
        $this->send('GET', '/api/tenants/pan/apps/preorder/records')->assertOk()->assertJsonPath('meta.total', 0);

        // 閲覧の段階の人は見られるが、予約販売にする・発売日を変えるはできない
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'pam', 'read', 'test');
        $this->send('GET', "/api/tenants/pam/apps/preorder/records/{$id}")->assertOk();
        $this->send('PUT', $panel, ['values' => ['enabled' => false]])->assertForbidden();
        $this->send('PUT', "/api/tenants/pam/apps/preorder/records/{$id}", ['values' => ['release_date' => '2026-12-20']])->assertForbidden();
        $this->assertSame(['2026-11-20', true], $this->inside('pam', fn (): array => [PreorderListing::query()->findOrFail($id)->releaseDate(), PreorderListing::query()->findOrFail($id)->active]));

        // App を使うのをやめた店では、App の画面は無い
        $this->inside('pam', fn () => app(PluginStates::class)->set('preorder', false, 'test'));
        $this->send('GET', '/api/tenants/pam/apps/preorder/records')->assertNotFound();
    }

    public function test_the_order_page_hides_shipping_before_the_release_date_and_the_home_lists_it_once_it_comes(): void
    {
        $this->enrolledOperator();
        $this->send('PUT', "/api/tenants/pam/listings/{$this->figure}/apps/preorder", ['values' => ['enabled' => true, 'release_date' => '2026-11-20']])->assertOk();
        $order = $this->inside('pam', function (): string {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 800, 'free_over' => null], 'test');
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->figure)->value('id'), 1);
            $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
            $this->assertNotNull($input);
            $transaction = app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), CheckoutSummary::fingerprintOf($carts->view($cart)));
            app(PaymentService::class)->startCheckout($transaction, 'x', 'y');
            $ref = (string) PaymentAttempt::query()->where('transaction_id', $transaction->id)->value('provider_ref');
            app(PaymentNotices::class)->confirmPaid('stripe', 'evt_1', 'checkout.session.completed', $ref, $transaction->id, (int) $transaction->total_amount, 'jpy', 'pi_1');

            return $transaction->id;
        });

        $detail = $this->send('GET', "/api/tenants/pam/transactions/{$order}")->assertOk();
        $detail->assertJsonPath('transaction.actions.ship', false)->assertJsonPath('transaction.fulfillments.0.ships_on', '2026-11-20')->assertJsonPath('transaction.fulfillments.0.held_for_release', true);
        $fulfillment = (string) $detail->json('transaction.fulfillments.0.id');
        // 押しても断る（文は見せてよいもの）
        $this->send('POST', "/api/tenants/pam/transactions/{$order}/ship", ['fulfillment_id' => $fulfillment, 'carrier' => 'yamato', 'tracking_number' => '1234'])
            ->assertConflict()->assertJsonFragment(['message' => 'この配送は 11月20日 から発送できます（予約販売の商品の発売日より前です）']);
        $this->assertSame([], array_values(array_filter((array) $this->send('GET', '/api/tenants/pam/overview')->json('app_todo'), static fn (array $row): bool => ($row['plugin'] ?? null) === 'preorder')));

        // 品が早く届いた: 発売日を今日に前倒しすると、その場で発送でき、ホームのやることに出る（発売日が来た時と同じ。日が来る場合は PreorderAppTest）
        $id = (string) $this->send('GET', '/api/tenants/pam/apps/preorder/records')->json('rows.0.id');
        $this->send('PUT', "/api/tenants/pam/apps/preorder/records/{$id}", ['values' => ['release_date' => '2026-11-10']])->assertOk();
        $this->send('GET', "/api/tenants/pam/transactions/{$order}")->assertOk()->assertJsonPath('transaction.actions.ship', true)->assertJsonPath('transaction.fulfillments.0.held_for_release', false);
        $todo = array_values(array_filter((array) $this->send('GET', '/api/tenants/pam/overview')->json('app_todo'), static fn (array $row): bool => ($row['plugin'] ?? null) === 'preorder'));
        $this->assertSame([['ready', 1]], array_map(static fn (array $row): array => [$row['filter'], $row['count']], $todo));
        $this->send('POST', "/api/tenants/pam/transactions/{$order}/ship", ['fulfillment_id' => $fulfillment, 'carrier' => 'yamato', 'tracking_number' => '1234'])->assertOk();
        $this->assertSame(1, $this->inside('pam', fn () => Transaction::query()->whereKey($order)->where('phase', 'fulfillment')->count()));
    }
}
