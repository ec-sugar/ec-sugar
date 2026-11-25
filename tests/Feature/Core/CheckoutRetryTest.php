<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 支払いの画面の作り直し。Stripe で画面は作れたのに答えが届かなかった・こちらの記録に失敗したとき、
 * やり直しは同じ冪等キー・同じ中身で頼む（Stripe は同じキーで違う中身を断るので、中身が変わると、その注文は 24 時間払えなくなる）。
 * 頼む中身（何回目・冪等キー・有効期限）は、頼む前に記録しておく
 */
final class CheckoutRetryTest extends TestCase
{
    use DatabaseTruncation;

    private const string BODY = 'FakeForTestsOnly000';

    private Tenant $tenant;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'cra', 'name' => '店']);
        tenancy()->initialize($this->tenant);
        app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => implode('_', ['whsec', self::BODY.'1234'])]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_lost_answer_is_retried_with_the_same_key_and_the_same_contents(): void
    {
        $sale = $this->sale();
        $this->loseTheAnswer($sale);
        $this->assertCount(1, $this->stripe->sessions);
        $first = $this->stripe->lastParams();

        // 少し後にお客さんが押し直す: 同じキー・同じ中身なので、Stripe は同じ画面を返す（画面は増えない）
        $this->travel(2)->minutes();
        $session = app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');

        $this->assertCount(1, $this->stripe->sessions);
        $this->assertSame(array_key_first($this->stripe->sessions), $session->ref);
        $this->assertSame("checkout-{$sale->id}-1", $this->stripe->lastHeader('Idempotency-Key'));
        $this->assertSame($first['expires_at'], $this->stripe->lastParams()['expires_at']);
        $this->assertSame([$session->ref], PaymentAttempt::query()->where('transaction_id', $sale->id)->pluck('provider_ref')->all());
    }

    public function test_a_plan_too_close_to_its_expiry_is_not_reused(): void
    {
        $sale = $this->sale();
        $this->loseTheAnswer($sale);

        // 記録した有効期限まで 30 分を切った（Stripe は 30 分より先しか受けない）: 新しい回数・新しいキーで頼む
        $this->travel(40)->minutes();
        $session = app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');

        $this->assertSame("checkout-{$sale->id}-2", $this->stripe->lastHeader('Idempotency-Key'));
        $this->assertCount(2, $this->stripe->sessions);
        $this->assertSame([$session->ref], PaymentAttempt::query()->where('transaction_id', $sale->id)->pluck('provider_ref')->all());
    }

    public function test_attempts_made_before_plans_existed_are_counted(): void
    {
        $sale = $this->sale();
        // 記録の仕組みより前に作った支払いの画面（回数 1 は使用済み）
        app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');
        PaymentAttempt::query()->where('transaction_id', $sale->id)->update(['expires_at' => now()->addMinute()]);
        DB::table('checkout_plans')->where('transaction_id', $sale->id)->delete();

        app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');
        $this->assertSame("checkout-{$sale->id}-2", $this->stripe->lastHeader('Idempotency-Key'));
    }

    public function test_the_fake_stripe_refuses_the_same_key_with_different_contents(): void
    {
        $sale = $this->sale();
        $this->loseTheAnswer($sale);
        // 記録を消して、昔の作り（呼ぶたびに有効期限を作り直す）と同じ状態にする: 同じキーで違う中身になり、断られる
        DB::table('checkout_plans')->where('transaction_id', $sale->id)->delete();
        $this->travel(2)->minutes();

        try {
            app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');
            $this->fail('同じキーで違う中身なのに通った');
        } catch (PaymentUnavailableException) {
        }

        $this->assertSame(0, PaymentAttempt::query()->where('transaction_id', $sale->id)->count());
    }

    /**
     * Stripe では画面を作ったが、答えが届かなかった
     */
    public function test_when_the_other_press_recorded_the_same_page_and_it_was_paid_it_says_paid_and_does_not_close_it(): void
    {
        $sale = $this->sale();
        $ref = $this->sessionMadeByTheOtherPress($sale, 'paid');

        try {
            app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');
            $this->fail('払い済みの画面を返した');
        } catch (PaymentUnavailableException $e) {
            $this->assertSame('このご注文のお支払いは済んでいます。', $e->getMessage());
        }

        // 払われた画面を閉じる操作は送らない（閉じると、届いた支払いが宙に浮く）。記録は先の 1 行だけ
        $this->assertSame([], array_values(array_filter($this->stripe->requests, static fn (array $r): bool => str_ends_with($r['url'], "/{$ref}/expire"))));
        $this->assertSame(['paid'], PaymentAttempt::query()->where('provider_ref', $ref)->pluck('status')->all());
    }

    public function test_when_the_other_press_recorded_the_same_page_as_not_payable_it_closes_the_page_and_refuses(): void
    {
        $sale = $this->sale();
        $ref = $this->sessionMadeByTheOtherPress($sale, 'expired');

        try {
            app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');
            $this->fail('払えない画面を返した');
        } catch (PaymentUnavailableException $e) {
            $this->assertSame(PaymentService::NOT_PAYABLE, $e->getMessage());
        }

        // 払えない画面は代行の側でも閉じる（閉じないと、お客さんが画面を開いたまま払えてしまう）
        $this->assertCount(1, array_filter($this->stripe->requests, static fn (array $r): bool => str_ends_with($r['url'], "/{$ref}/expire")));
        $this->assertSame('expired', $this->stripe->sessions[$ref]['status']);
        $this->assertSame(1, PaymentAttempt::query()->where('provider_ref', $ref)->count());
    }

    public function test_when_stripe_says_the_same_key_is_in_use_the_customer_is_asked_to_try_again_and_the_retry_reuses_the_key(): void
    {
        $sale = $this->sale();
        $stripe = $this->stripe;
        // 本物の Stripe は、同じ冪等キーの頼みが処理中だと 409（idempotency_error）を返すことがある（偽物はふだん同じ画面を返す）。1 回だけ
        $this->stripe->respond = static function () use ($stripe): array {
            $stripe->respond = null;

            return [(string) json_encode(['error' => ['type' => 'idempotency_error', 'code' => 'idempotency_key_in_use', 'message' => 'in use']]), 409, []];
        };

        try {
            app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');
            $this->fail('処理中なのに画面を返した');
        } catch (PaymentUnavailableException $e) {
            // 2 人目に出る文: 中身（鍵・Stripe の言葉）は出さず、やり直しを頼む
            $this->assertSame('お支払いの画面を用意できませんでした。時間をおいて、もう一度お試しください。', $e->getMessage());
            $this->assertSame(503, $e->status());
        }

        $this->assertSame(0, PaymentAttempt::query()->where('transaction_id', $sale->id)->count());

        // 押し直すと同じキー・同じ中身で頼み、画面ができる（新しいキーで 2 つ目の画面を作らない）
        $session = app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');

        // 1 回目（409）も押し直しも、同じ冪等キー
        $keys = [];
        foreach ($this->stripe->requests as $request) {
            if (str_ends_with($request['url'], '/v1/checkout/sessions')) {
                foreach ($request['headers'] as $header) {
                    if (str_starts_with($header, 'Idempotency-Key: ')) {
                        $keys[] = substr($header, strlen('Idempotency-Key: '));
                    }
                }
            }
        }
        $this->assertSame(["checkout-{$sale->id}-1", "checkout-{$sale->id}-1"], array_slice($keys, -2));
        $this->assertCount(1, $this->stripe->sessions);
        $this->assertSame([$session->ref], PaymentAttempt::query()->where('transaction_id', $sale->id)->pluck('provider_ref')->all());
    }

    /**
     * 同時に押された方が、同じ画面（同じ冪等キー）を先に記録した場面を作る: 1 回目は答えが届かず画面だけが Stripe にでき、
     * 2 回目に頼んだ瞬間（Stripe が同じ画面を返す前）に、相手がその画面を $status で記録する
     */
    private function sessionMadeByTheOtherPress(Transaction $sale, string $status): string
    {
        $this->loseTheAnswer($sale);
        $ref = (string) array_key_first($this->stripe->sessions);
        $this->stripe->before = static function () use ($sale, $ref, $status): void {
            PaymentAttempt::create([
                'transaction_id' => $sale->id, 'provider' => 'stripe', 'provider_ref' => $ref, 'checkout_url' => "https://checkout.stripe.com/c/pay/{$ref}",
                'amount' => 1000, 'currency' => 'JPY', 'status' => $status, 'expires_at' => now()->addMinutes(30),
                'paid_at' => $status === 'paid' ? now() : null,
            ]);
        };

        return $ref;
    }

    private function loseTheAnswer(Transaction $sale): void
    {
        $this->stripe->dropNextCheckoutAnswer = true;

        try {
            app(PaymentService::class)->startCheckout($sale, 'https://x/ok', 'https://x/back');
            $this->fail('答えが届かないのに画面を返した');
        } catch (PaymentUnavailableException) {
        }

        $this->assertSame(0, PaymentAttempt::query()->where('transaction_id', $sale->id)->count());
    }

    private function sale(): Transaction
    {
        $location = Location::query()->firstOrFail();
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
        app(StockService::class)->receive($listing, $location, 3);
        $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']);

        return app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');
    }
}
