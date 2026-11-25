<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Plugin\Contracts\OrderLinkProvider;
use App\Core\Plugin\OrderLink;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderReader;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App のご注文のリンクの口（1 つの口。2026-11-04 の取り込みで「商品レビュー」の入口と「納品書・領収書」のリンクをまとめた）。
 * 使っている App の分だけが 1 つの並びに出て、片方を止めてももう片方は出る。ほかの App の名前を名乗るリンク・落ちた App は、
 * その App の分だけを出さずにご注文の画面を描く
 */
final class OrderLinksTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private string $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->tenant('olk');
        $this->order = $this->inside('olk', function (): string {
            app(PluginStates::class)->set('reviews', true, 'test');
            app(PluginStates::class)->set('order-print', true, 'test');

            return $this->shippedOrder();
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_links_of_the_apps_in_use_come_out_together_and_one_app_off_leaves_the_other(): void
    {
        // 2 つとも使っている: 発送済みの注文に「領収書」と「レビューを書く」が 1 つの並びに出る（App の名前の順。領収書だけ新しいタブ）
        $page = $this->orderPage();
        $this->assertSame(['order-print', 'reviews'], $this->linkPlugins($page));
        $review = $page->querySelector('[data-order-links] [data-test="order-link-reviews"]');
        $receipt = $page->querySelector('[data-order-links] [data-test="order-link-order-print"]');
        $this->assertStringStartsWith('/p/reviews/', (string) $review?->getAttribute('href'));
        $this->assertNull($review?->getAttribute('target'));
        $this->assertStringStartsWith('/p/order-print/', (string) $receipt?->getAttribute('href'));
        $this->assertSame('_blank', $receipt?->getAttribute('target'));

        // レビューを止めても領収書は出る
        $this->inside('olk', fn () => app(PluginStates::class)->set('reviews', false, 'test'));
        $this->assertSame(['order-print'], $this->linkPlugins($this->orderPage()));

        // 納品書・領収書を止めてレビューを戻すと、レビューだけ
        $this->inside('olk', function (): void {
            app(PluginStates::class)->set('order-print', false, 'test');
            app(PluginStates::class)->set('reviews', true, 'test');
        });
        $this->assertSame(['reviews'], $this->linkPlugins($this->orderPage()));

        // どちらも止めると並びごと描かない
        $this->inside('olk', fn () => app(PluginStates::class)->set('reviews', false, 'test'));
        $this->assertNull($this->orderPage()->querySelector('[data-order-links]'));
    }

    public function test_a_link_naming_another_app_or_a_failing_app_only_drops_its_own_links(): void
    {
        Exceptions::fake();
        // ほかの App（reviews）の経路を、自分の名前でなく reviews を名乗って返す App と、落ちる App
        app(PluginRegistry::class)->register(new PluginDefinition('fake-impostor', '偽の App', '', 1, orderLinks: new class implements OrderLinkProvider
        {
            public function forOrder(Transaction $order): array
            {
                return [new OrderLink('reviews', 'レビューを書く', '/p/reviews/orders/'.$order->id)];
            }
        }));
        app(PluginRegistry::class)->register(new PluginDefinition('fake-broken', '落ちる App', '', 1, orderLinks: new class implements OrderLinkProvider
        {
            public function forOrder(Transaction $order): array
            {
                throw new RuntimeException('落ちた');
            }
        }));
        $this->inside('olk', function (): void {
            app(PluginStates::class)->set('fake-impostor', true, 'test');
            app(PluginStates::class)->set('fake-broken', true, 'test');
        });

        // 本物の 2 つのリンクだけが出る（偽の reviews のリンクは数に入らない）
        $page = $this->orderPage();
        $this->assertSame(['order-print', 'reviews'], $this->linkPlugins($page));
        $this->assertCount(1, $page->querySelectorAll('[data-test="order-link-reviews"]'));
        Exceptions::assertReported(LogicException::class);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_link_goes_only_under_its_own_apps_shop_path(): void
    {
        foreach (['/p/reviews/orders/x', 'https://evil.example/p/fake/x', '//evil.example/p/fake/x', '/p/fake/../reviews/x', 'javascript:alert(1)', '/p/fake/x?a="b'] as $path) {
            try {
                new OrderLink('fake', 'リンク', $path);
                $this->fail("断るはずの行き先: {$path}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue((new OrderLink('fake', 'リンク', '/p/fake/x?expires=1&signature=ab', newTab: true))->newTab);
    }

    private function shippedOrder(): string
    {
        $seller = Seller::query()->firstOrFail();
        $location = Location::query()->firstOrFail();
        $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '帆布のトートバッグ', 'unit_amount' => 4900, 'status' => ListingStatus::Active]);
        app(StockService::class)->receive($listing, $location, 2);
        $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '09012345678', 'address' => ['city' => '渋谷区']]);
        $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 4900, $location)], actor: 'storefront');
        CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);
        $sale = app(TransactionService::class)->transition($sale, Phase::Payment, 'test');
        app(TransactionService::class)->transition($sale, Phase::Fulfillment, 'test');

        return $sale->id;
    }

    /**
     * ご注文の確認（確認のメールの署名つきのリンク）
     */
    private function orderPage(): HTMLDocument
    {
        $link = $this->inside('olk', fn (): string => OrderReader::link(Transaction::query()->findOrFail($this->order), 'http://olk.localhost'));
        $response = $this->get('http://olk.localhost'.parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY))->assertOk();
        tenancy()->end();

        return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
    }

    /**
     * @return list<string>
     */
    private function linkPlugins(HTMLDocument $page): array
    {
        return array_values(array_map(
            static fn ($a): string => substr((string) $a->getAttribute('data-test'), strlen('order-link-')),
            iterator_to_array($page->querySelectorAll('[data-order-links] a')),
        ));
    }
}
