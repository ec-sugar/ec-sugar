<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Models\GiftCardEntry;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 設定 › ギフトカード（ADR 0076）。一覧と帳簿は閲覧から（コードは返さない）、止める・再開は運用から、
 * 手で足す・引くはお金の段階で直前の再確認の後。未使用の残高の合計はお金の段階だけ。どれも中央の監査ログに残す（理由は残さない）
 */
final class GiftCardsAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('gcm');
        $this->tenant('gcn');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_an_owner_sees_the_cards_and_the_outstanding_balance_and_adjusts_after_reconfirming(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        [$card, $code] = $this->issue('gcm', 5000);
        $this->issue('gcm', 3000);
        $url = "/api/tenants/gcm/gift-cards/{$card}";

        $list = $this->send('GET', '/api/tenants/gcm/gift-cards')->assertOk();
        $list->assertJsonPath('total', 2)->assertJsonPath('outstanding.amount', 8000)->assertJsonPath('outstanding.cards', 2)
            ->assertJsonPath('can_stop', true)->assertJsonPath('can_adjust', true);
        // コードもハッシュも返さない（末尾 4 文字だけ）
        $this->assertStringNotContainsString(str_replace('-', '', $code), (string) $list->getContent());
        $this->assertStringNotContainsString('code_hash', (string) $list->getContent());
        // 末尾 4 文字で探せる
        $this->send('GET', '/api/tenants/gcm/gift-cards?last4='.substr($code, -4))->assertOk()->assertJsonPath('cards.0.id', $card);

        // 再確認が先
        $this->send('POST', "{$url}/adjust", ['amount' => 500, 'reason' => 'お詫び'])->assertStatus(423);
        $this->reconfirm($operator, $authenticator);
        $this->send('POST', "{$url}/adjust", ['amount' => 500, 'reason' => '山田さんへのお詫び'])->assertCreated()->assertJsonPath('balance', 5500);
        // 残高より多くは引けない（422）、0・理由なしも断る
        $this->send('POST', "{$url}/adjust", ['amount' => -5501, 'reason' => '直し'])->assertStatus(422);
        $this->send('POST', "{$url}/adjust", ['amount' => 0, 'reason' => '直し'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->send('POST', "{$url}/adjust", ['amount' => 10])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->send('POST', "{$url}/adjust", ['amount' => -1500, 'reason' => '入力の誤り'])->assertCreated()->assertJsonPath('balance', 4000);

        $show = $this->send('GET', $url)->assertOk();
        $show->assertJsonPath('card.balance', 4000)->assertJsonCount(3, 'card.history')
            ->assertJsonPath('card.history.0.kind', 'adjust')->assertJsonPath('card.history.0.amount', -1500)->assertJsonPath('card.history.1.reason', '山田さんへのお詫び')
            ->assertJsonPath('card.history.2.kind', 'issue');

        // 止める・再開（盗難の申し出）
        $this->send('POST', "{$url}/stop")->assertOk()->assertJsonPath('card.state', 'stopped');
        $this->send('GET', '/api/tenants/gcm/gift-cards?state=stopped')->assertOk()->assertJsonPath('total', 1);
        $this->send('POST', "{$url}/resume")->assertOk()->assertJsonPath('card.state', 'active');

        // 中央の監査ログには額だけ（理由は残さない）
        $audit = AuditEvent::query()->where('type', 'operator.gift_card_adjusted')->orderBy('sequence')->get();
        $this->assertSame([500, -1500], $audit->map(static fn (AuditEvent $e): int => $e->data['amount'])->all());
        $this->assertStringNotContainsString('お詫び', (string) json_encode($audit->pluck('data')->all(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, AuditEvent::query()->where('type', 'operator.gift_card_stopped')->count());
        $this->inside('gcm', function (): void {
            $this->assertSame(2, DomainEvent::query()->where('type', 'gift_card.adjusted')->count());
            $this->assertStringStartsWith('operator:', (string) GiftCardEntry::query()->where('kind', 'adjust')->value('actor'));
        });

        // ほかの店からは見えない・変えられない
        $this->send('GET', "/api/tenants/gcn/gift-cards/{$card}")->assertNotFound();
        $this->send('POST', "/api/tenants/gcn/gift-cards/{$card}/adjust", ['amount' => 10, 'reason' => 'x'])->assertNotFound();
        $this->send('POST', "/api/tenants/gcn/gift-cards/{$card}/stop")->assertNotFound();
    }

    public function test_read_staff_only_look_and_operate_staff_stop_but_neither_adjusts(): void
    {
        [$card] = $this->issue('gcm', 3000);
        $this->inside('gcm', fn () => app(GiftCardService::class)->adjust(GiftCard::query()->findOrFail($card), 100, '山田さんへのお詫び', 'test'));
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $url = "/api/tenants/gcm/gift-cards/{$card}";

        // 閲覧: 一覧と額は見えるが、未使用の残高の合計と店の人の理由は見えない。止められない・足せない
        $access->grant($staff, 'gcm', 'read', 'test');
        $this->send('GET', '/api/tenants/gcm/gift-cards')->assertOk()->assertJsonPath('outstanding', null)->assertJsonPath('can_stop', false)->assertJsonPath('can_adjust', false);
        $this->send('GET', $url)->assertOk()->assertJsonPath('card.balance', 3100)->assertJsonPath('card.history.0.reason', null);
        $this->send('POST', "{$url}/stop")->assertForbidden();
        $this->send('POST', "{$url}/adjust", ['amount' => 10, 'reason' => 'x'])->assertForbidden();

        // 運用: 止められるが、足せない（再確認をしても）
        $access->grant($staff, 'gcm', 'operate', 'test');
        $this->reconfirm($staff, $authenticator);
        $this->send('GET', $url)->assertOk()->assertJsonPath('card.history.0.reason', '山田さんへのお詫び');
        $this->send('POST', "{$url}/stop")->assertOk()->assertJsonPath('card.state', 'stopped');
        $this->send('POST', "{$url}/adjust", ['amount' => 10, 'reason' => 'x'])->assertForbidden();

        $this->inside('gcm', fn () => $this->assertSame(3100, app(GiftCardService::class)->balance($card)));
    }

    public function test_the_order_shows_the_card_part_and_only_money_staff_return_a_chosen_amount_to_the_card(): void
    {
        [$operator, $authenticator] = $this->enrolledOperator();
        [$card] = $this->issue('gcm', 5000);
        $sale = $this->paidOrder('gcm', $card, 2000);
        $url = "/api/tenants/gcm/transactions/{$sale}";

        // 注文の 1 件: 合計 5,000 のうちギフトカード 2,000、お支払い 3,000
        $this->send('GET', $url)->assertOk()
            ->assertJsonPath('transaction.total_amount', 5000)
            ->assertJsonPath('transaction.gift_card.amount', 2000)->assertJsonPath('transaction.gift_card.due', 3000)
            ->assertJsonPath('transaction.gift_card.releasable', 2000)->assertJsonPath('transaction.actions.release_gift_card', true);

        // 再確認が先。戻せる額を超える額・0 は断る
        $this->send('POST', "{$url}/gift-card/release", ['amount' => 1500])->assertStatus(423);
        $this->reconfirm($operator, $authenticator);
        $this->send('POST', "{$url}/gift-card/release", ['amount' => 2001])->assertStatus(422);
        $this->send('POST', "{$url}/gift-card/release", ['amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->send('POST', "{$url}/gift-card/release", ['amount' => 1500])->assertOk()
            ->assertJsonPath('transaction.gift_card.released', 1500)->assertJsonPath('transaction.gift_card.releasable', 500);
        // 店の人が戻すのは 1 回だけ
        $this->send('POST', "{$url}/gift-card/release", ['amount' => 100])->assertStatus(422);

        $this->inside('gcm', fn () => $this->assertSame(5000 - 2000 + 1500, app(GiftCardService::class)->balance($card)));
        $this->assertSame(1500, AuditEvent::query()->where('type', 'operator.gift_card_released')->sole()->data['amount']);
        // ほかの店からは戻せない
        $this->send('POST', "/api/tenants/gcn/transactions/{$sale}/gift-card/release", ['amount' => 100])->assertNotFound();
    }

    public function test_operate_staff_cannot_return_card_money_from_an_order(): void
    {
        [$card] = $this->issue('gcm', 5000);
        $sale = $this->paidOrder('gcm', $card, 2000);
        [$staff, $authenticator] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'gcm', 'operate', 'test');
        $this->reconfirm($staff, $authenticator);

        $this->send('POST', "/api/tenants/gcm/transactions/{$sale}/gift-card/release", ['amount' => 100])->assertForbidden();
        $this->inside('gcm', fn () => $this->assertSame(2000, app(GiftCardService::class)->releasable($sale)));
    }

    /**
     * 5,000 円の注文の一部をギフトカードで払い、払いが確かになった（残りは別の手段で受け取った扱い）
     */
    private function paidOrder(string $shop, string $cardId, int $giftCard): string
    {
        return $this->inside($shop, function () use ($cardId, $giftCard): string {
            $seller = Seller::query()->firstOrFail();
            $party = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com']);
            $line = new LineDraft(LineType::Item, '品', 1, 5000, 1000);

            return DB::transaction(static function () use ($seller, $party, $line, $cardId, $giftCard): string {
                $sale = app(TransactionService::class)->open('sale', $seller, $party, [$line], actor: 'test');
                app(GiftCardService::class)->spend($sale->id, $cardId, $giftCard, 'test');
                app(LedgerService::class)->recordPayment($sale, 5000 - $giftCard, PaymentRoute::Platform, 'pi_admin', 'test');
                app(GiftCardService::class)->recordPayment($sale, 'test');
                app(TransactionService::class)->transition($sale, Phase::Payment, 'test');

                return $sale->id;
            });
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function issue(string $shop, int $amount): array
    {
        return $this->inside($shop, function () use ($amount): array {
            $issued = app(GiftCardService::class)->issue($amount, 180, null, 'test');

            return [$issued['card']->id, GiftCardService::format((string) $issued['code'])];
        });
    }
}
