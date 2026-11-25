<?php

declare(strict_types=1);

/*
| 通しの確かめ（docs/development/flow-check.md）の下ごしらえと読み出し。tests/e2e/flow/runner.js が呼び、最後の行に JSON を返す。
|
| 確かめる先のアプリの中で動かす（手元はコンテナ、写しは SSH の先のコンテナ）。ファイルは標準入力で渡すので、先にファイルを置かなくてよい:
|   docker exec -i ec-sugar-app-1 php -- guard < tests/e2e/flow/flow.php
|
|   guard                                   本番に向いていないかを確かめる（ホストの名前・メールの送信先）。何も書かない
|   setup ドメインの後ろ                      試しの店（flow<16 進>）と、その店だけお金の段階のスタッフを作る。商品・クーポン・ポイント・ギフトカード・銀行振込・代引き・定期便
|   enroll 店                                その店のスタッフのパスキーの登録 URL を出す
|   order 店 注文                            注文の状態・支払いの記録・台帳・返金・受渡・ポイント・ギフトカード・クーポンを返す
|   stock 店                                 商品ごとの在庫（数・押さえた数）
|   member-code 店 メール                    会員のログインのコードを出し直して返す（メールを読まずに入る）
|   member 店 メール                         会員に届け先を足し、ポイントを 1,000 渡す（ログインの後に 1 回）
|   expire 店 注文 分                         その注文だけを、今から「分」だけ進めた時刻で払われないまま期限切れの見回りにかける
|   subscription 店 メール                   会員の定期便と回・記録（イベント）を返す
|   run-subscriptions 店 日付                その店だけで、定期便の毎日の処理を日付（YYYY-MM-DD、日本時間の正午）に進めて流す
|   cleanup 店 運営のメール                  試しの店を DB ごと消し、スタッフを止める
|
| 店の ID は flow で始まるものだけを受ける（ほかの店・デモの店・利用者が試している店には触らない）。
*/

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\OperatorEnrollments;
use App\Core\Discount\Models\CouponRedemption;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Models\LedgerPosting;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Party\Services\CustomerAddresses;
use App\Core\Party\Services\CustomerLogin;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\StoredPaymentMethod;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Models\OrderPoints;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Models\Tenant;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use EcSugar\PluginSubscription\Models\Subscription;
use EcSugar\PluginSubscription\Models\SubscriptionRound;
use EcSugar\PluginSubscription\RoundProcessor;
use EcSugar\PluginSubscription\SubscriptionListingPanel;
use Illuminate\Contracts\Console\Kernel;

// 標準入力で渡されたとき __DIR__ は使えないので、アプリの場所は今いるディレクトリ（コンテナの作業ディレクトリ）か FLOW_APP_ROOT
$root = getenv('FLOW_APP_ROOT') ?: (string) getcwd();

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
});

const FLOW_ACTOR = 'cli:flow';

/**
 * 本番に向いていないか。理由の一覧を返す（空なら動かしてよい）。
 * 本番のホストの名前（ec-sugar.jp）を使う設定・外へ届くメールの送信先は止める。写しで流す時は、写しの設定を localhost とメールを外に出さない形にしておく
 *
 * @return list<string>
 */
function flowProblems(): array
{
    $problems = [];
    $hosts = [
        'APP_URL' => (string) config('app.url'),
        'ADMIN_DOMAIN' => (string) config('admin.domain', ''),
        'ADMIN_URL' => (string) config('admin.url', ''),
        'CENTRAL_DOMAINS' => implode(',', (array) config('tenancy.central_domains', [])),
    ];

    foreach ($hosts as $name => $value) {
        if (str_contains(strtolower($value), 'ec-sugar.jp')) {
            $problems[] = "{$name} が本番のホスト（ec-sugar.jp）を向いている";
        }
    }

    $mailer = (string) config('mail.default');
    $host = strtolower((string) config("mail.mailers.{$mailer}.host", ''));

    if (! in_array($mailer, ['log', 'array'], true) && ! ($mailer === 'smtp' && in_array($host, ['mail', 'mailpit', 'localhost', '127.0.0.1', '::1'], true))) {
        $problems[] = "メールが外へ届く送り方（{$mailer}・{$host}）。写しでは MAIL_MAILER=log にする";
    }

    return $problems;
}

function flowTenant(string $id): Tenant
{
    if (preg_match('/\Aflow[0-9a-f]{8,16}\z/', $id) !== 1) {
        throw new InvalidArgumentException("通しの確かめの店（flow で始まる ID）だけを扱う: {$id}");
    }

    return Tenant::query()->findOrFail($id);
}

/**
 * 店の文脈の中で動かし、必ず閉じる（conventions の 2）
 *
 * @template T
 *
 * @param  callable(): T  $fn
 * @return T
 */
function inShop(string $id, callable $fn): mixed
{
    tenancy()->initialize(flowTenant($id));

    try {
        return $fn();
    } finally {
        tenancy()->end();
    }
}

/**
 * 時刻を進める（null で戻す）。Carbon と CarbonImmutable の両方（アプリの中は now() と CarbonImmutable::now() が混ざる）
 */
function travel(?CarbonImmutable $at): void
{
    Carbon::setTestNow($at);
    CarbonImmutable::setTestNow($at);
}

/**
 * 定期便の毎日の処理（RoundProcessor）を、今の店で 1 回流す。定期便の App のクラスは class_exists で囲んで使い、
 * 戻り値の型をここで決める（公開の木で、無いクラスの呼び出しの戻り値が inShop の型の引数へ流れないように）
 *
 * @return array<string, int> 何をいくつしたか
 */
function runSubscriptionRounds(CarbonImmutable $at): array
{
    if (! class_exists(RoundProcessor::class)) {
        throw new RuntimeException('定期便の App が入っていません');
    }

    return app(RoundProcessor::class)->run($at);
}

/**
 * 注文の値を、照らし合わせやすい形で返す（個人情報は返さない）
 *
 * @return array<string, mixed>
 */
function orderState(string $transactionId): array
{
    $transaction = Transaction::query()->findOrFail(strtolower($transactionId));
    $ledger = app(LedgerService::class);
    $journals = LedgerJournal::query()->where('transaction_id', $transaction->id)->orderBy('created_at')->orderBy('id')->get()
        ->map(static function (LedgerJournal $journal) use ($transaction): array {
            $postings = LedgerPosting::query()->where('journal_id', $journal->id)->pluck('amount', 'account')->map(static fn (mixed $v): int => (int) $v)->all();

            return [
                'type' => $journal->type,
                // お客さんの口座の動き（支払いは +、返金は −）
                'payer' => $postings[$transaction->payer->value] ?? 0,
                'postings' => $postings,
                'reference' => $journal->reference,
            ];
        })->values()->all();

    return [
        'id' => $transaction->id,
        'status' => $transaction->status->value,
        'phase' => $transaction->phase->value,
        'total' => $transaction->total_amount,
        'tax' => $transaction->tax_amount,
        'lines' => TransactionLine::query()->where('transaction_id', $transaction->id)->orderBy('position')->get()
            ->map(static fn (TransactionLine $line): array => ['position' => $line->position, 'kind' => $line->kind, 'description' => $line->description, 'quantity' => $line->quantity, 'amount' => $line->amount])->values()->all(),
        'attempts' => PaymentAttempt::query()->where('transaction_id', $transaction->id)->orderBy('created_at')->get()
            ->map(static fn (PaymentAttempt $a): array => ['kind' => $a->kind, 'provider' => $a->provider, 'ref' => $a->provider_ref, 'status' => $a->status, 'amount' => $a->amount, 'method' => $a->method])->values()->all(),
        'ledger' => ['balances' => $ledger->balances($transaction), 'net_paid' => $ledger->netPaid($transaction), 'journals' => $journals],
        'refunds' => Refund::query()->where('transaction_id', $transaction->id)->orderBy('created_at')->get()
            ->map(static fn (Refund $r): array => ['purpose' => $r->purpose, 'channel' => $r->channel, 'amount' => $r->amount, 'status' => $r->status, 'ref' => $r->provider_ref, 'payment_ref' => $r->payment_ref])->values()->all(),
        'fulfillments' => Fulfillment::query()->where('transaction_id', $transaction->id)->get()
            ->map(static fn (Fulfillment $f): array => ['status' => $f->status->value, 'tracking' => $f->tracking_number])->values()->all(),
        'points' => ($p = OrderPoints::query()->find($transaction->id)) === null ? null : ['spent' => $p->spent, 'earn' => $p->earn],
        'gift_card' => ($g = OrderGiftCard::query()->find($transaction->id)) === null ? null : ['amount' => $g->amount],
        'coupon' => ($c = CouponRedemption::query()->where('transaction_id', $transaction->id)->first()) === null ? null : ['amount' => $c->amount, 'status' => $c->status],
        'events' => DomainEvent::query()->where('aggregate_id', $transaction->id)->orderBy('sequence')->pluck('type')->values()->all(),
    ];
}

/**
 * @return array<string, array{quantity: int, reserved: int}>
 */
function stockState(): array
{
    $titles = Listing::query()->pluck('title', 'id');
    $out = [];

    foreach (InventoryStock::query()->orderBy('listing_id')->get() as $stock) {
        $out[(string) ($titles[$stock->listing_id] ?? $stock->listing_id)] = ['quantity' => $stock->quantity, 'reserved' => $stock->reserved];
    }

    return $out;
}

/** @var list<string> $argv */
$command = $argv[1] ?? '';
$options = array_slice($argv, 2);
$problems = flowProblems();

if ($problems !== []) {
    fwrite(STDERR, "通しの確かめは本番に向けて動かさない:\n- ".implode("\n- ", $problems)."\n");
    exit(1);
}

$result = match ($command) {
    'guard' => ['ok' => true, 'env' => (string) config('app.env'), 'mailer' => (string) config('mail.default')],

    'setup' => (static function () use ($options): array {
        $suffix = $options[0] ?? '';

        if (preg_match('/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\z/', $suffix) !== 1 || str_contains($suffix, 'ec-sugar.jp')) {
            throw new InvalidArgumentException('使い方: flow.php setup ドメインの後ろ（例: localhost）');
        }

        $id = 'flow'.bin2hex(random_bytes(6));
        $tenant = Tenant::create(['id' => $id, 'name' => "通しの確かめ {$id}"]);
        $tenant->domains()->create(['domain' => "{$id}.{$suffix}"]);

        $made = inShop($id, static function (): array {
            app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, FLOW_ACTOR);
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listings = [];

            // 商品ごとに 1 つの流れ（ほかの流れの在庫の動きが混ざらない）。値段は税込み
            foreach (['card' => 1000, 'refund' => 1200, 'cancel' => 1300, 'secure' => 1400, 'decline' => 1500, 'bank' => 1600, 'cod' => 1700, 'konbini' => 1800, 'teiki' => 2000, 'coupon' => 2100, 'points' => 2200, 'giftcard' => 2300] as $key => $price) {
                $listing = app(ListingService::class)->create($seller, 'product', ['title' => "通し {$key}", 'unit_amount' => $price, 'status' => ListingStatus::Active], FLOW_ACTOR);
                app(InventoryOperations::class)->receive($listing, $location, 10, FLOW_ACTOR);
                $listings[$key] = $listing->id;
            }

            // 定期便（毎月・10% 引き）。定期便の App が入っていない木（公開用の 1.0。ADR 0098）では作らない（場面 6 は飛ぶ）。
            // このファイルは標準入力で確かめる先へ渡すので、定期便の所をほかのファイルへ分けられない。定期便のクラスは
            // class_exists で囲んで使う（公開の木の静的解析が無いクラスを数えないため。開発用の木では型のまま全部見る）
            if (app(PluginRegistry::class)->has('subscription') && class_exists(SubscriptionListingPanel::class)) {
                app(PluginStates::class)->set('subscription', true, FLOW_ACTOR);
                (new SubscriptionListingPanel)->write(Listing::query()->findOrFail($listings['teiki']), ['enabled' => true, 'interval_monthly' => true, 'discount' => 'pct10'], FLOW_ACTOR);
            }

            // ポイント（1%）・クーポン（300 円引き）・ギフトカード（額面 1,000 円）
            app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 100], FLOW_ACTOR);
            app(CouponService::class)->create(['code' => 'FLOW-300', 'name' => '通しの確かめ', 'kind' => 'fixed', 'value' => 300], FLOW_ACTOR);
            $gift = app(GiftCardService::class)->issue(1000, 30, null, FLOW_ACTOR);

            // 銀行振込と代引き（手数料 330 円）。コンビニ払いも出す（Stripe のアカウントで有効でなければ Stripe の画面に出ない）
            app(DirectMethodSettings::class)->update([
                'bank_transfer' => true, 'bank_days' => 7,
                'bank_account' => ['bank' => 'テスト銀行', 'branch' => '本店', 'type' => 'ordinary', 'number' => '1234567', 'holder' => 'ツウシ'],
                'cod' => true, 'cod_fees' => [['below' => null, 'fee' => 330]], 'cod_limit' => 300000,
            ], FLOW_ACTOR);
            app(PaymentMethodSettings::class)->update(['konbini' => true, 'paypay' => false, 'konbini_days' => 3], FLOW_ACTOR);

            return ['listings' => $listings, 'coupon' => 'FLOW-300', 'giftCard' => $gift['code']];
        });

        // その店だけお金の段階のスタッフ（ほかの店は見えない）
        $operator = Operator::create(['name' => "通しの確かめ {$id}", 'email' => "{$id}@example.com"]);
        app(OperatorAccess::class)->grant($operator, $id, 'money', FLOW_ACTOR);

        return ['id' => $id, 'domain' => "{$id}.{$suffix}", 'operator' => $operator->email] + $made;
    })(),

    // 残した店でもう一度流す（FLOW_SHOP）。商品の ID を返し、ギフトカードを 1 枚足す（コードは発行の時にしか分からない）
    'reuse' => inShop($options[0] ?? '', static function () use ($options): array {
        $listings = [];

        foreach (Listing::query()->where('title', 'like', '通し %')->get() as $listing) {
            $listings[substr($listing->title, strlen('通し '))] = $listing->id;
        }

        $id = (string) tenant()?->getTenantKey();

        return ['id' => $id, 'domain' => (string) tenant()?->domains()->value('domain'), 'operator' => "{$id}@example.com", 'listings' => $listings, 'coupon' => 'FLOW-300',
            'giftCard' => app(GiftCardService::class)->issue(1000, 30, null, FLOW_ACTOR)['code'], 'reused' => $options[0] ?? ''];
    }),

    // その店のスタッフのパスキーの登録 URL（場面の処理ごとに新しいブラウザの仮想の認証器で登録し直す）
    'enroll' => (static function () use ($options): array {
        $tenant = flowTenant($options[0] ?? '');
        $operator = Operator::query()->whereEmail("{$tenant->id}@example.com")->whereNull('disabled_at')->firstOrFail();

        return ['url' => app(OperatorEnrollments::class)->issue($operator, 'flow')['url']];
    })(),

    'order' => inShop($options[0] ?? '', static fn (): array => orderState($options[1] ?? '')),

    'stock' => inShop($options[0] ?? '', static fn (): array => stockState()),

    // 会員のログインのコード（メールは読まない。前のコードは止まる。tests/e2e/fixtures.php の member-code と同じ）
    'member-code' => inShop($options[0] ?? '', static function () use ($options): array {
        $login = app(CustomerLogin::class);

        return ['code' => $login->takeForSending($login->issue($options[1] ?? ''))['code'] ?? throw new RuntimeException('コードを出せませんでした')];
    }),

    'member' => inShop($options[0] ?? '', static function () use ($options): array {
        $account = app(CustomerAccounts::class)->forEmail($options[1] ?? '', FLOW_ACTOR);

        if (app(CustomerAddresses::class)->list($account)->isEmpty()) {
            app(CustomerAddresses::class)->add($account, ['name' => '通し 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''], FLOW_ACTOR);
            app(PointService::class)->adjust($account, 1000, '通しの確かめ', FLOW_ACTOR);
        }

        return ['account' => $account->id, 'points' => app(PointService::class)->balance($account->id)];
    }),

    // 払われないまま期限切れの見回り（storefront:expire-unpaid-orders の 1 件分）。全店を回すコマンドを先の時刻で流すと、ほかの店の注文まで取り消すので、この注文だけ
    'expire' => inShop($options[0] ?? '', static function () use ($options): array {
        $minutes = (int) ($options[2] ?? 0);
        travel(CarbonImmutable::now()->addMinutes(max(0, min(60 * 24 * 30, $minutes))));

        try {
            $outcome = app(PaymentService::class)->expireIfUnpaid(Transaction::query()->findOrFail(strtolower($options[1] ?? '')), CarbonImmutable::now()->subMinutes((int) config('payment.expiry_grace_minutes')));
        } finally {
            travel(null);
        }

        return ['outcome' => $outcome] + orderState($options[1] ?? '');
    }),

    // 定期便の App のクラスは class_exists で囲んで使う（上の setup の定期便と同じ理由）。引数の型に App のクラスを書く関数は作らない
    'subscription' => inShop($options[0] ?? '', static function () use ($options): array {
        if (! class_exists(Subscription::class) || ! class_exists(SubscriptionRound::class)) {
            throw new RuntimeException('定期便の App が入っていません');
        }

        $account = app(CustomerAccounts::class)->findByEmail($options[1] ?? '') ?? throw new RuntimeException('会員が見つかりません');

        // 預けたカード（会員ごと。定期便の回はその会員の今のカードで請求する）。末尾 4 桁と状態だけ
        $cards = StoredPaymentMethod::query()->where('customer_account_id', $account->id)->orderBy('created_at')->get()
            ->map(static fn (StoredPaymentMethod $c): string => "{$c->last4}:{$c->status}")->values()->all();

        $subscriptions = [];

        foreach (Subscription::query()->where('customer_account_id', $account->id)->orderBy('created_at')->get() as $s) {
            $rounds = [];
            $ids = [$s->id];

            foreach (SubscriptionRound::query()->where('subscription_id', $s->id)->orderBy('number')->get() as $r) {
                $rounds[] = ['number' => $r->number, 'due_on' => $r->due_on->toDateString(), 'status' => $r->status, 'transaction' => $r->transaction_id, 'declines' => $r->declines, 'charge_ref' => $r->charge_ref, 'retry_on' => $r->retry_on?->toDateString()];
                $ids[] = $r->id;
            }

            $subscriptions[] = [
                'id' => $s->id, 'status' => $s->status, 'anchor_on' => $s->anchor_on->toDateString(), 'first_transaction' => $s->first_transaction_id,
                'rounds' => $rounds,
                'events' => DomainEvent::query()->whereIn('aggregate_id', $ids)->orderBy('sequence')->pluck('type')->values()->all(),
            ];
        }

        return ['cards' => $cards, 'subscriptions' => $subscriptions];
    }),

    // 定期便の毎日の処理（subscriptions:run の 1 店分）を、その日の正午（日本時間）に進めて流す。全店を回すコマンドを先の日で流さない
    'run-subscriptions' => inShop($options[0] ?? '', static function () use ($options): array {
        $date = $options[1] ?? '';

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            throw new InvalidArgumentException('使い方: flow.php run-subscriptions 店 YYYY-MM-DD');
        }

        $at = CarbonImmutable::parse("{$date} 12:00", 'Asia/Tokyo')->utc();

        if ($at->isBefore(CarbonImmutable::now()->subDay()) || $at->isAfter(CarbonImmutable::now()->addDays(400))) {
            throw new InvalidArgumentException('日付は今日から 400 日先まで');
        }

        travel($at);

        try {
            return ['at' => $at->toIso8601String(), 'counts' => runSubscriptionRounds($at)];
        } finally {
            travel(null);
        }
    }),

    'cleanup' => (static function () use ($options): array {
        $tenant = flowTenant($options[0] ?? '');
        $email = $options[1] ?? '';

        if ($email === "{$tenant->id}@example.com") {
            Operator::query()->whereEmail($email)->update(['disabled_at' => now()]);
        }

        $tenant->delete();

        return ['cleaned' => $tenant->id];
    })(),

    default => throw new InvalidArgumentException("知らない命令: {$command}"),
};

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
