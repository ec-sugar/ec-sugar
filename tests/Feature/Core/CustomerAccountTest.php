<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Crypto\BlindIndex;
use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PlaceOrder;
use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * お客さんの台帳（ADR 0044 の 1・2）。注文はメールの完全一致で 1 人の台帳につながる。
 * 名前や電話の一致ではまとめない。注文の時の名前・住所は注文ごとの Party に残る。店をまたがない。今までの注文は移行でつなぐ。
 */
final class CustomerAccountTest extends TestCase
{
    use DatabaseTruncation;

    private const string MIGRATION = 'database/migrations/tenant/2026_11_01_000200_create_customer_accounts_table.php';

    private Tenant $tenant;

    private string $cap;

    private int $orders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'cus', 'name' => '店']);
        $this->cap = $this->inside(fn (): string => $this->stock());
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Tenant::query()->whereIn('id', ['cus', 'cus2'])->get()->each->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_orders_with_the_same_email_go_to_one_account(): void
    {
        $this->inside(function (): void {
            $first = $this->order('hanako@example.com', '山田 花子');
            // 大文字小文字の違いは同じメール（前後の空白は入力の検査が先に取る。台帳の引きでも同じにそろえる）
            $second = $this->order('Hanako@Example.COM', '山田 花子');
            $this->assertSame($first->customer_account_id, app(CustomerAccounts::class)->forEmail("  HANAKO@example.com\t", 'storefront')->id);
            $other = $this->order('taro@example.com', '山田 太郎');

            $this->assertNotNull($first->customer_account_id);
            $this->assertSame($first->customer_account_id, $second->customer_account_id);
            $this->assertNotSame($first->customer_account_id, $other->customer_account_id);
            $this->assertSame(2, CustomerAccount::query()->count());

            // 台帳のメールは暗号化して持つ（列は読めない、モデルを通すと読める）。最初に打たれた形のまま
            $account = CustomerAccount::query()->findOrFail($first->customer_account_id);
            $this->assertSame('hanako@example.com', $account->email);
            $this->assertStringStartsWith(TenantCipher::PREFIX, (string) DB::table('customer_accounts')->where('id', $account->id)->value('email'));
            $this->assertNull($account->member_since);

            // 作った記録はイベントに 1 回ずつ、個人情報を入れない。注文の開きにも台帳の ID が載る
            $opened = DomainEvent::query()->where('type', 'customer_account.opened')->get();
            $this->assertCount(2, $opened);
            foreach ($opened as $event) {
                $this->assertSame([], (array) $event->payload);
                $this->assertSame('storefront', $event->actor);
            }
            $payload = (array) DomainEvent::query()->where('type', 'transaction.opened')->where('aggregate_id', $second->id)->sole()->payload;
            $this->assertSame($first->customer_account_id, $payload['customer_account_id']);
        });
    }

    public function test_the_same_name_and_phone_with_another_email_is_not_merged(): void
    {
        $this->inside(function (): void {
            // 同姓同名・同じ電話でも、メールが違えば別の台帳（自動でまとめるのはメールだけ。取り違えると他人の注文が見える）
            $a = $this->order('hanako@example.com', '山田 花子');
            $b = $this->order('hanako.work@example.com', '山田 花子');

            $this->assertNotSame($a->customer_account_id, $b->customer_account_id);

            // 注文の時の名前と住所は、それぞれの注文の Party にそのまま（台帳に写さない）
            $this->assertSame(2, Party::query()->where('kind', PartyKind::Customer)->count());
            $this->assertFalse(in_array('name', (new CustomerAccount)->getFillable(), true));
            $this->assertSame('山田 花子', Party::query()->findOrFail($a->customer_id)->name);
        });
    }

    public function test_one_email_is_one_row_even_without_the_service(): void
    {
        $this->inside(function (): void {
            app(CustomerAccounts::class)->forEmail('hanako@example.com', 'storefront');

            // アプリの引きをすり抜けても、DB の一意の制約が 2 行目を拒む（同時の注文への 2 枚目の守り）
            $this->expectException(QueryException::class);
            DB::table('customer_accounts')->insert([
                'id' => strtolower((string) Str::ulid()),
                'email' => 'x',
                'email_index' => BlindIndex::of('email', 'HANAKO@example.com'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_an_empty_email_is_refused(): void
    {
        $this->inside(function (): void {
            foreach (['', '   ', str_repeat('a', 250).'@x.jp'] as $email) {
                try {
                    app(CustomerAccounts::class)->forEmail($email, 'storefront');
                    $this->fail('空・長すぎるメールで台帳を作った');
                } catch (\InvalidArgumentException) {
                }
            }

            $this->assertSame(0, CustomerAccount::query()->count());
        });
    }

    public function test_accounts_do_not_cross_shops(): void
    {
        $other = Tenant::create(['id' => 'cus2', 'name' => '別の店']);
        $here = $this->inside(fn (): string => (string) $this->order('hanako@example.com', '山田 花子')->customer_account_id);

        tenancy()->initialize($other);

        try {
            // 同じメールでも、別の店ではその店の台帳に別の 1 人（店ごとの DB と鍵。索引の値も店ごとに違う）
            $this->assertNull(CustomerAccount::query()->find($here));
            $there = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'storefront');
            $this->assertNotSame($here, $there->id);
            $this->assertSame(1, CustomerAccount::query()->count());
            $thereIndex = $there->email_index;
        } finally {
            tenancy()->end();
        }

        $this->inside(function () use ($here, $thereIndex): void {
            $this->assertSame(1, CustomerAccount::query()->count());
            $this->assertNotSame($thereIndex, CustomerAccount::query()->findOrFail($here)->email_index);
        });
    }

    public function test_the_migration_links_earlier_orders_by_email(): void
    {
        $this->inside(function (): void {
            // 台帳の前の注文: 同じメール（大文字小文字違い）が 2 件、別のメールが 1 件、メールの無いお客さん 1 件、お客さんの無い取引 1 件
            $seller = Seller::query()->firstOrFail();
            $listing = Listing::query()->findOrFail($this->cap);
            $line = fn (): LineDraft => LineDraft::fromVariant($listing, ListingVariant::query()->where('listing_id', $listing->id)->firstOrFail(), 1, 1000, Location::query()->orderBy('id')->firstOrFail());
            $open = fn (?Party $customer): string => app(TransactionService::class)->open('sale', $seller, $customer, [$line()], actor: 'cli:test')->id;
            $party = fn (?string $email, string $name): Party => Party::create(['kind' => PartyKind::Customer, 'name' => $name, 'email' => $email]);
            $a1 = $open($party('hanako@example.com', '山田 花子'));
            $this->travel(1)->minutes();
            $a2 = $open($party('HANAKO@example.com', '山田 はなこ'));
            $b = $open($party('taro@example.com', '山田 太郎'));
            $none = $open($party(null, '店頭の人'));
            $bare = $open(null);

            // 台帳の無い形へ戻してから、移行をかけ直す
            $migration = require base_path(self::MIGRATION);
            $this->assertInstanceOf(Migration::class, $migration);
            $this->assertTrue(method_exists($migration, 'up') && method_exists($migration, 'down'));
            // 後の移行（会員のログイン・届け先）の表は台帳に頼るので、新しい順に下げて、後で上げ直す
            // 台帳より後の店の移行を全部（古い順）。足すたびに一覧を直さなくてよいように、名前の順で拾う
            // プラグインの表の移行（ADR 0050 の 2）も、名前（日付）の順で混ぜる
            $files = [...(glob(base_path('database/migrations/tenant/*.php')) ?: []), ...(glob(base_path('plugins/*/database/migrations/*.php')) ?: [])];
            usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));
            $later = array_map(static fn (string $file): Migration => require $file, array_values(array_filter($files, static fn (string $file): bool => strcmp(basename($file), basename(self::MIGRATION)) > 0)));
            foreach (array_reverse($later) as $step) {
                $this->assertTrue(method_exists($step, 'down'));
                $step->down();
            }
            $migration->down();
            $this->assertFalse(Schema::hasTable('customer_accounts'));

            $migration->up();
            foreach ($later as $step) {
                $this->assertTrue(method_exists($step, 'up'));
                $step->up();
            }

            $linked = DB::table('transactions')->pluck('customer_account_id', 'id');
            $this->assertNotNull($linked[$a1]);
            $this->assertSame($linked[$a1], $linked[$a2]);
            $this->assertNotNull($linked[$b]);
            $this->assertNotSame($linked[$a1], $linked[$b]);
            $this->assertNull($linked[$none]);
            $this->assertNull($linked[$bare]);
            $this->assertSame(2, CustomerAccount::query()->count());

            // 写した台帳: メールは最後の注文の打たれた形、作った時刻は最初の注文、会員ではない。後の注文も同じ台帳に入る
            $account = CustomerAccount::query()->findOrFail($linked[$a1]);
            $this->assertSame('HANAKO@example.com', $account->email);
            $this->assertSame(Transaction::query()->findOrFail($a1)->created_at?->getTimestamp(), $account->created_at->getTimestamp());
            $this->assertNull($account->member_since);
            $this->assertSame($account->id, $this->order('hanako@example.com', '山田 花子')->customer_account_id);
        });
    }

    private function order(string $email, string $name): Transaction
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->cap)->value('id'), 1);
        $input = CheckoutInput::fromArray(['buyer' => ['email' => $email, 'name' => $name, 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => null], 'recipient' => null]);
        $this->assertNotNull($input);
        $this->orders++;

        return app(PlaceOrder::class)->place($cart, $input, str_repeat((string) $this->orders, 43), CheckoutSummary::fingerprintOf($carts->view($cart)));
    }

    private function stock(): string
    {
        $seller = Seller::query()->value('id');
        $cap = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'キャップ', 'unit_amount' => 3300, 'status' => 'active']);
        app(StockService::class)->receive($cap, Location::query()->orderBy('id')->firstOrFail(), 20);
        app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);

        return $cap->id;
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
