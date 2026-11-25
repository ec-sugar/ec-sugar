<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

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
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Models\PointEntry;
use App\Core\Points\Services\PointSettings;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use Carbon\CarbonImmutable;
use EcSugar\PluginReferral\Models\Referral;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の「友だち紹介」の確かめ待ちの「ポイントを付ける」。ポイントはお金に近い（手で足す口は「お金」の段階と直前の再確認。ADR 0068）ので、
 * 付けるのも「お金」の段階の人だけで、押す前にパスキーでの直前の再確認を求める（ADR 0024 の段階を App の操作で回り込ませない）。
 * 「付けない」はポイントを動かさないので運用の段階のまま（取り消せないので直前の再確認の後）
 */
final class ReferralAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        Mail::fake();
        $this->tenant('rfc');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_giving_points_to_a_held_referral_needs_the_money_level_and_a_fresh_confirmation(): void
    {
        $id = $this->inside('rfc', fn (): string => $this->heldReferral());
        $path = "/api/tenants/rfc/apps/referral/records/{$id}";

        // オーナーには「付ける」がお金の操作の印つきで見える
        $this->enrolledOperator();
        $record = $this->send('GET', $path)->assertOk();
        $this->assertSame([['approve', true], ['decline', false]], array_map(static fn (array $a): array => [$a['key'], $a['money']], $record->json('record.actions')));
        $this->send('POST', '/auth/logout')->assertNoContent();

        // 運用の段階のスタッフは、直前の再確認をしても付けられない（403）。ポイントは動かない
        [$staff, $staffKey] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'rfc', 'operate', 'test');
        $this->reconfirm($staff, $staffKey);
        $this->send('POST', "{$path}/actions/approve")->assertForbidden();
        $this->inside('rfc', function () use ($id): void {
            $this->assertSame(Referral::REVIEW, Referral::query()->findOrFail($id)->status);
            $this->assertSame(0, PointEntry::query()->count());
        });

        // お金の段階でも、直前の再確認が古ければ 423
        $access->grant($staff, 'rfc', 'money', 'test');
        $this->travel(20)->minutes();
        $this->send('POST', "{$path}/actions/approve")->assertStatus(423);
        $this->inside('rfc', fn () => $this->assertSame(0, PointEntry::query()->count()));

        // 再確認の後だけ付く（actor はその人）
        $this->reconfirm($staff, $staffKey);
        $this->send('POST', "{$path}/actions/approve")->assertOk()->assertJsonPath('record.actions', []);
        $this->inside('rfc', function () use ($id, $staff): void {
            $referral = Referral::query()->findOrFail($id);
            $this->assertSame([Referral::REWARDED, 500, 500], [$referral->status, $referral->referrer_points, $referral->friend_points]);
            $this->assertSame(2, PointEntry::query()->count());
            $this->assertSame($staff->actor(), DomainEvent::query()->where('type', 'referral.approved')->sole()->actor);
        });
    }

    public function test_operate_staff_can_still_decline_a_held_referral_after_confirming(): void
    {
        $id = $this->inside('rfc', fn (): string => $this->heldReferral());
        [$staff, $staffKey] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'rfc', 'operate', 'test');
        $this->reconfirm($staff, $staffKey);

        $this->send('POST', "/api/tenants/rfc/apps/referral/records/{$id}/actions/decline")->assertOk();
        $this->inside('rfc', fn () => $this->assertSame(Referral::DECLINED, Referral::query()->findOrFail($id)->status));
    }

    /**
     * 確かめ待ち（同じ接続元）の紹介を 1 件。最初の注文は払われた形（ポイントの道の確かめは ReferralAppTest）
     */
    private function heldReferral(): string
    {
        app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 0], 'test');
        app(PluginStates::class)->set('referral', true, 'test');
        $taro = $this->member('taro@example.com');
        $hanako = $this->member('hanako@example.com');
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
        app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);
        $buyer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '08055556666', 'address' => ['zip' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '2-2-2']]);
        $order = app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), $buyer, [LineDraft::fromListing($listing, 1, 4400, Location::query()->firstOrFail())], actor: 'test', customerAccount: $hanako);

        $referral = new Referral(['referrer_account_id' => $taro->id, 'friend_account_id' => $hanako->id, 'status' => Referral::REVIEW, 'linked_at' => CarbonImmutable::now()]);
        $referral->forceFill(['reason' => 'same_source', 'transaction_id' => $order->id, 'ordered_at' => CarbonImmutable::now()])->save();

        return $referral->id;
    }

    private function member(string $email): CustomerAccount
    {
        $account = app(CustomerAccounts::class)->forEmail($email, 'test');
        CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);

        return CustomerAccount::query()->findOrFail($account->id);
    }
}
