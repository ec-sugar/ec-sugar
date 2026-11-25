<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の「お客さん」（ADR 0044 の 4・2）。一覧と画面は件数と金額だけ（閲覧から）、個人情報は記録に残して運用から。
 * 名前と電話が同じでメールが違う記録を「同じ人かもしれない」と出し、店の人がまとめる・分け直す。注文は書き換えない。会員はまとめられる側にしない。
 */
final class CustomerAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('caa');
        $this->tenant('cab');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_list_and_the_customer_show_counts_and_amounts_without_personal_data(): void
    {
        $this->enrolledOperator();
        $hanako = $this->paidOrder('caa', 'hanako@example.com', '山田 花子', '09012345678', 4400);
        $this->paidOrder('caa', 'HANAKO@example.com', '山田 花子', '09012345678', 3300);
        $this->unpaidOrder('caa', 'hanako@example.com');
        $this->paidOrder('caa', 'taro@example.com', '山田 太郎', '09099999999', 1100);

        $list = $this->send('GET', '/api/tenants/caa/customers')->assertOk();
        $list->assertJsonPath('counts.all', 2)->assertJsonPath('meta.total', 2);
        // 最後の注文の新しい順。数えるのは払われた注文だけ（払われていない注文は数えない）
        $this->assertSame([$this->accountOf('caa', 'taro@example.com'), $hanako], array_column($list->json('customers'), 'id'));
        $first = self::row($list->json('customers'), $hanako);
        $this->assertSame([2, 7700, false], [$first['orders'], $first['sales'], $first['member']]);

        $show = $this->send('GET', "/api/tenants/caa/customers/{$hanako}")->assertOk();
        $show->assertJsonPath('customer.orders', 2)->assertJsonPath('customer.sales', 7700)->assertJsonCount(3, 'customer.transactions');
        $this->assertContains('awaiting_payment', array_column($show->json('customer.transactions'), 'order_status'));

        // 一覧と画面の応答に個人情報は無い（JSON を読み解いてから探す）
        foreach ([$list, $show] as $response) {
            $text = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            foreach (['hanako', '山田', '09012345678', '渋谷'] as $pii) {
                $this->assertStringNotContainsString($pii, $text);
            }
        }

        // 個人情報は personal だけ。見た事実が記録に残る
        $personal = $this->send('GET', "/api/tenants/caa/customers/{$hanako}/personal")->assertOk();
        $personal->assertJsonPath('personal.email', 'hanako@example.com')->assertJsonPath('personal.latest_order.name', '山田 花子');
        $this->assertSame($hanako, AuditEvent::query()->where('type', 'operator.personal_data_viewed')->latest('sequence')->firstOrFail()->data['customer_account_id']);

        // ほかの店の記録は見えない
        $this->send('GET', "/api/tenants/cab/customers/{$hanako}")->assertNotFound();
        $this->send('GET', '/api/tenants/cab/customers')->assertOk()->assertJsonPath('counts.all', 0);
    }

    public function test_staff_need_the_operate_level_for_personal_data_and_merging(): void
    {
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $hanako = $this->paidOrder('caa', 'hanako@example.com', '山田 花子', '09012345678', 4400);
        $work = $this->paidOrder('caa', 'hanako.work@example.com', '山田 花子', '09012345678', 3300);
        $access = app(OperatorAccess::class);

        // 閲覧: 一覧と画面は見られる。個人情報・同じ人かもしれない記録・まとめるはできない
        $access->grant($staff, 'caa', 'read', 'test');
        $this->send('GET', '/api/tenants/caa/customers')->assertOk();
        $this->send('GET', "/api/tenants/caa/customers/{$hanako}")->assertOk();
        $this->send('GET', "/api/tenants/caa/customers/{$hanako}/personal")->assertForbidden();
        $this->send('GET', "/api/tenants/caa/customers/{$hanako}/similar")->assertForbidden();
        $this->send('POST', "/api/tenants/caa/customers/{$hanako}/merge", ['merged_id' => $work])->assertForbidden();
        $this->inside('caa', fn () => $this->assertNull(CustomerAccount::query()->findOrFail($work)->merged_into_id));

        // 運用: できる
        $access->grant($staff, 'caa', 'operate', 'test');
        $this->send('GET', "/api/tenants/caa/customers/{$hanako}/similar")->assertOk()->assertJsonPath('similar.0.id', $work);
        $this->send('POST', "/api/tenants/caa/customers/{$hanako}/merge", ['merged_id' => $work])->assertNoContent();
    }

    public function test_the_same_name_and_phone_is_suggested_and_merging_keeps_the_orders_as_they_are(): void
    {
        $this->enrolledOperator();
        $hanako = $this->paidOrder('caa', 'hanako@example.com', '山田 花子', '090-1234-5678', 4400);
        $work = $this->paidOrder('caa', 'hanako.work@example.com', ' 山田　花子 ', '09012345678', 3300);
        // 同じ名前で違う電話・違う名前で同じ電話は、同じ人かもしれないに出さない
        $this->paidOrder('caa', 'other@example.com', '山田 花子', '0311112222', 1000);
        $this->paidOrder('caa', 'family@example.com', '山田 太郎', '09012345678', 1000);

        $similar = $this->send('GET', "/api/tenants/caa/customers/{$hanako}/similar")->assertOk();
        $this->assertSame([$work], array_column($similar->json('similar'), 'id'));

        $before = $this->inside('caa', fn (): array => Transaction::query()->orderBy('id')->pluck('customer_account_id', 'id')->all());
        $this->send('POST', "/api/tenants/caa/customers/{$hanako}/merge", ['merged_id' => strtoupper($work)])->assertNoContent();

        // 一覧からはまとめられた記録が消え、親の数に足される。注文の台帳の ID は書き換えない
        $list = $this->send('GET', '/api/tenants/caa/customers')->assertOk()->assertJsonPath('counts.all', 3);
        $row = self::row($list->json('customers'), $hanako);
        $this->assertSame([2, 7700, 1], [$row['orders'], $row['sales'], $row['merged_count']]);
        $show = $this->send('GET', "/api/tenants/caa/customers/{$hanako}")->assertOk()->assertJsonCount(2, 'customer.transactions')->assertJsonPath('customer.merged.0.id', $work);
        $this->assertCount(1, array_filter($show->json('customer.transactions'), static fn (array $t): bool => $t['from_merged']));
        $this->inside('caa', function () use ($before, $hanako, $work): void {
            $this->assertSame($before, Transaction::query()->orderBy('id')->pluck('customer_account_id', 'id')->all());
            $this->assertSame(['merged_id' => $work], (array) DomainEvent::query()->where('type', 'customer_account.merged')->sole()->payload);
            $this->assertSame($hanako, DomainEvent::query()->where('type', 'customer_account.merged')->sole()->aggregate_id);
        });
        $this->assertSame($work, AuditEvent::query()->where('type', 'operator.customers_merged')->latest('sequence')->firstOrFail()->data['merged_id']);
        // まとめた後は、まとめた記録のメールも personal で見える。同じ人かもしれないには出ない
        $this->send('GET', "/api/tenants/caa/customers/{$hanako}/personal")->assertJsonPath('personal.merged_emails.0.email', 'hanako.work@example.com');
        $this->send('GET', "/api/tenants/caa/customers/{$hanako}/similar")->assertJsonPath('similar', []);

        // 分け直すと元どおり
        $this->send('POST', "/api/tenants/caa/customers/{$hanako}/unmerge", ['merged_id' => $work])->assertNoContent();
        $this->send('GET', '/api/tenants/caa/customers')->assertJsonPath('counts.all', 4);
        $this->inside('caa', fn () => $this->assertNull(CustomerAccount::query()->findOrFail($work)->merged_into_id));
    }

    public function test_wrong_merges_are_refused(): void
    {
        $this->enrolledOperator();
        $a = $this->paidOrder('caa', 'a@example.com', '山田 花子', '09012345678', 1000);
        $b = $this->paidOrder('caa', 'b@example.com', '山田 花子', '09012345678', 1000);
        $c = $this->paidOrder('caa', 'c@example.com', '山田 花子', '09012345678', 1000);
        $member = $this->paidOrder('caa', 'm@example.com', '山田 花子', '09012345678', 1000);
        $other = $this->paidOrder('cab', 'x@example.com', '山田 花子', '09012345678', 1000);
        $this->inside('caa', fn () => CustomerAccount::query()->whereKey($member)->update(['member_since' => now()]));

        $refused = fn (string $parent, mixed $child, int $status = 422) => $this->send('POST', "/api/tenants/caa/customers/{$parent}/merge", ['merged_id' => $child])->assertStatus($status);
        $refused($a, $a);                                    // 自分自身
        $refused($a, $member);                               // 会員はまとめられる側にしない
        $refused($a, $other);                                // ほかの店の記録（この店には無い）
        $refused($a, '01aaaaaaaaaaaaaaaaaaaaaaaa');           // 無い記録
        $refused($a, ['x']);                                 // 形の違う入力
        $refused($a, "' or 1=1 --");

        // 会員を親にして、ゲストをまとめるのはできる
        $this->send('POST', "/api/tenants/caa/customers/{$member}/merge", ['merged_id' => $a])->assertNoContent();
        // 入れ子にしない: まとめられた記録を親にしない・子を持つ記録をまとめられる側にしない・もうまとめた記録を二重にまとめない
        $refused($a, $b);
        $refused($c, $member);
        // 子を持つゲストの記録も、まとめられる側にしない（d に e をまとめてから、d を c へ）
        $d = $this->paidOrder('caa', 'd@example.com', '山田 花子', '09012345678', 1000);
        $e = $this->paidOrder('caa', 'e@example.com', '山田 花子', '09012345678', 1000);
        $this->send('POST', "/api/tenants/caa/customers/{$d}/merge", ['merged_id' => $e])->assertNoContent();
        $refused($c, $d);
        $refused($b, $a);
        // まとめていない記録を分けようとしても断る
        $this->send('POST', "/api/tenants/caa/customers/{$member}/unmerge", ['merged_id' => $b])->assertStatus(422);

        // DB でも自分自身へのまとめは断る
        $this->inside('caa', function () use ($c): void {
            $this->expectException(QueryException::class);
            DB::table('customer_accounts')->where('id', $c)->update(['merged_into_id' => $c, 'merged_at' => now()]);
        });
    }

    /**
     * 一覧の中の 1 件
     *
     * @return array<string, mixed>
     */
    private static function row(mixed $customers, string $id): array
    {
        self::assertIsArray($customers);

        foreach ($customers as $customer) {
            if (is_array($customer) && ($customer['id'] ?? null) === $id) {
                return $customer;
            }
        }

        self::fail("一覧に {$id} が無い");
    }

    private function paidOrder(string $shop, string $email, string $name, string $phone, int $amount): string
    {
        return $this->inside($shop, function () use ($email, $name, $phone, $amount): string {
            $sale = $this->open($email, $name, $phone, $amount);
            app(TransactionService::class)->transition($sale, Phase::Payment, 'test');

            return (string) $sale->customer_account_id;
        });
    }

    private function unpaidOrder(string $shop, string $email): void
    {
        $this->inside($shop, fn () => $this->open($email, '山田 花子', '09012345678', 999));
    }

    private function open(string $email, string $name, string $phone, int $amount): Transaction
    {
        $seller = Seller::query()->firstOrFail();
        $location = Location::query()->firstOrFail();
        $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => $amount, 'status' => 'active']);
        app(StockService::class)->receive($listing, $location, 3);
        $customer = Party::create(['kind' => PartyKind::Customer, 'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => ['city' => '渋谷区']]);
        $account = app(CustomerAccounts::class)->forEmail($email, 'storefront');

        return app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'storefront', customerAccount: $account);
    }

    private function accountOf(string $shop, string $email): string
    {
        return $this->inside($shop, fn (): string => (string) CustomerAccount::query()->whereBlind('email', $email)->value('id'));
    }
}
