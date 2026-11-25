<?php

declare(strict_types=1);

/*
| ブラウザのテスト（tests/e2e、Playwright）の下ごしらえ。support/fixtures.js が php で呼び、結果を JSON で返す。
|
|   php tests/e2e/fixtures.php reset                    ec_e2e（作業ごとに分けたときは ec_e2e_<文字>。tests/e2e/env.js の E2E_DB）を作り直す（前回のテナントは DB とロールごと消す）。global-setup が 1 回だけ呼ぶ
|   php tests/e2e/fixtures.php cleanup                  場面が作ったテナントを DB とロールごと消す。global-teardown が終わった後に 1 回だけ呼ぶ
|   php tests/e2e/fixtures.php operator [--grant=店:段階]  運営を 1 人作り、パスキーの登録 URL を返す（場面ごとに新しい運営。--grant でその店だけのスタッフに）
|   php tests/e2e/fixtures.php tenant [--listings=N] [--draft=N] [--stock=N] [--html-name] [--transaction] [--awaiting=N] [--theme=名前] [--every-pattern] [--showcase] [--embeds] [--no-apps] [--tags] [--consent-banner] [--preparing] [--samples] [--stray] [--customers] [--subscription] [--subscriptions]
|                                                       テナントを 1 つ作る（場面ごとに新しいテナント）。商品・取引も入れられる。
|                                                       --subscriptions は定期便の App を使い、会員の定期便を 2 つ（続いている・お休み）入れる（管理画面の App の画面。ADR 0051）
|   php tests/e2e/fixtures.php stock テナント 増減         「商品 1」の在庫を動かす（例: -2。入れた後に在庫が減った場面）
|   php tests/e2e/fixtures.php collect テナント            運営全体の画面の数を、その店だけ集める（operations:collect --tenant）
|   php tests/e2e/fixtures.php member-code テナント メール  会員のログインのコードを出し直して返す（メールは外へ出ないので）
|   php tests/e2e/fixtures.php audit テナント 種類          その店の監査ログのうち、その種類の記録の data を古い順に返す（お客さんの名前と連絡先を見た記録）
|
| 消す操作をするので、DB が ec_e2e か ec_e2e_<文字> で本番でないときだけ動く（設定の控え・店の DB の頭・写真の置き場がその DB と組かも確かめる）。
*/

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\OperatorEnrollments;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Discount\Services\CouponService;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Party\Services\CustomerAddresses;
use App\Core\Party\Services\CustomerLogin;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Services\AppSectionCatalog;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Sample\Services\ShopSamples;
use App\Core\Section\BlockSpec;
use App\Core\Section\CoreSections;
use App\Core\Section\FieldSpec;
use App\Core\Section\FieldType;
use App\Core\Section\PageSpec;
use App\Core\Section\Services\ShopPageService;
use App\Core\Shop\Models\ShopSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\e2e\SubscriptionFixtures;
use Tests\Support\TemplatedTenantDatabaseManager;
use Tests\Support\TestEnvironmentGuard;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// 例外は理由を標準エラーに出して終了コード 1 で止める（Laravel の例外表示は終了コードを 0 のままにすることがある）
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
});

// 設定の控えがある・DB が ec_e2e（か ec_e2e_<文字>）でない・店の DB の頭と写真の置き場がその DB と組でない・本番なら、何も消さずに止める。
// つながった先の DB の名前も、設定で確かめた DB と同じかを見る
$values = TestEnvironmentGuard::valuesFromApplication($app);
$problems = TestEnvironmentGuard::problems(TestEnvironmentGuard::E2E, $values);
$connected = DB::connection()->getDatabaseName();

if ($problems !== [] || app()->isProduction() || ! TestEnvironmentGuard::isBrowserTestDatabase($connected)
    || $connected !== array_values($values['connections'])[0]['database']) {
    fwrite(STDERR, $problems === [] ? "ec_e2e（か ec_e2e_<文字>）以外の DB・本番では動かさない\n" : TestEnvironmentGuard::message($problems));
    exit(1);
}

/** @var list<string> $argv */
$command = $argv[1] ?? '';
$options = array_slice($argv, 2);
$suffix = static fn (): string => strtolower(bin2hex(random_bytes(4)));

$result = match ($command) {
    'reset' => (static function (): array {
        if (Schema::hasTable('tenants')) {
            Tenant::all()->each->delete();
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        return ['reset' => true];
    })(),

    // 終わった後の片付け。場面ごとに作ったテナントの DB とロールを残さない（1 回の通しで 70 ほど溜まっていた）
    'cleanup' => (static function (): array {
        $count = 0;

        if (Schema::hasTable('tenants')) {
            foreach (Tenant::all() as $tenant) {
                $tenant->delete();
                $count++;
            }
        }

        return ['cleanup' => $count];
    })(),

    // 場面ごとの片付け: その場面が作ったテナントを DB とロールごと消す。最後の cleanup まで溜めると、
    // 1 回の通しで 70 ほど（1 つ 12 MB）がメモリの上の DB（CI の tmpfs 1 GB）に積もり、3 GB の CI の VM が終わりの方で遅くなっていた。
    // 消せなかった店（サーバーの処理がまだつないでいる等）は飛ばし、最後の cleanup に任せる
    'drop-tenants' => (static function () use ($options): array {
        $count = 0;

        foreach ($options as $id) {
            try {
                Tenant::query()->find($id)?->delete();
                $count++;
            } catch (Throwable) {
                // 最後の cleanup で消す
            }
        }

        return ['dropped' => $count];
    })(),

    'operator' => (static function () use ($options, $suffix): array {
        $id = $suffix();
        $operator = Operator::create(['name' => "E2E 運営 {$id}", 'email' => "e2e-{$id}@example.com"]);
        // --grant=店:段階: スタッフにして、その店だけ段階を与える（段階ごとの出し分けを確かめる）
        $grant = null;

        foreach ($options as $option) {
            if (preg_match('/\A--grant=([a-z0-9][a-z0-9_-]{0,62}):(read|operate|money)\z/', $option, $m) === 1) {
                $grant = [$m[1], $m[2]];
            }
        }

        if ($grant !== null) {
            app(OperatorAccess::class)->grant($operator, (string) Tenant::query()->findOrFail($grant[0])->getTenantKey(), $grant[1], 'cli:e2e');
        } else {
            // ブラウザのテストの運営はオーナー（新しい運営の既定はスタッフ。ADR 0024）
            $operator->forceFill(['role' => 'owner'])->save();
        }

        return ['enrollUrl' => app(OperatorEnrollments::class)->issue($operator, 'e2e')['url'], 'name' => $operator->name, 'email' => $operator->email];
    })(),

    'tenant' => (static function () use ($options, $suffix): array {
        $listings = 0;
        $drafts = 0;
        $stock = 0;
        $awaiting = 0;
        $htmlName = in_array('--html-name', $options, true);
        $withTransaction = in_array('--transaction', $options, true);
        $withStripe = in_array('--stripe', $options, true);
        $theme = null;

        foreach ($options as $option) {
            if (preg_match('/\A--theme=([a-z][a-z0-9-]{1,31})\z/', $option, $m) === 1) {
                $theme = $m[1];
            }

            if (preg_match('/\A--(listings|draft|stock|awaiting)=(\d{1,2})\z/', $option, $m) === 1) {
                match ($m[1]) {
                    'listings' => $listings = (int) $m[2],
                    'draft' => $drafts = (int) $m[2],
                    'stock' => $stock = (int) $m[2],
                    'awaiting' => $awaiting = (int) $m[2],
                };
            }
        }

        $id = 'e2e'.$suffix();
        // --html-name: 名前に HTML を入れ、画面が文字として出すこと（実行しないこと）を確かめる。--showcase: 見本の絵を撮るときの店の名前
        $name = $htmlName ? '<img src=x onerror="window.__xss=1">商店' : (in_array('--showcase', $options, true) ? 'SAMPLE SHOP' : "テスト商店 {$id}");
        // 店の DB は、移行を流し終えた型から写す（ADR 0057。PHP のテストと同じ部品。ロールと権限は本番と同じ形）。
        // 画面から店を作る場面（管理画面のサーバー）は本番の作り方のまま
        config(['tenancy.database.managers.pgsql' => TemplatedTenantDatabaseManager::class]);
        $tenant = Tenant::create(['id' => $id, 'name' => $name] + ($theme === null ? [] : ['theme' => $theme]));
        $tenant->domains()->create(['domain' => "{$id}.localhost"]);
        $transaction = null;
        $customers = null;
        $subscriptions = null;

        tenancy()->initialize($tenant);

        try {
            // ショップは公開にしておく（新しい店の既定は準備中）。--preparing で準備中のまま（PHP のテストの土台と同じく、イベントは残さない）
            if (! in_array('--preparing', $options, true)) {
                ShopSettings::query()->update(['storefront_status' => 'open']);
            }

            $seller = Seller::query()->firstOrFail();

            for ($i = 1; $i <= $listings; $i++) {
                $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => "商品 {$i}", 'unit_amount' => 1000 * $i, 'status' => ListingStatus::Active]);

                // --stock: 「商品 1」にだけ在庫を入れる（並べ替えを確かめる）
                if ($i === 1 && $stock > 0) {
                    app(StockService::class)->receive($listing, Location::query()->firstOrFail(), $stock);
                }
            }

            for ($i = 1; $i <= $drafts; $i++) {
                Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => "下書きの商品 {$i}", 'unit_amount' => 500, 'status' => ListingStatus::Draft]);
            }

            if ($withTransaction) {
                $transaction = createSale($seller);
            }

            // --awaiting: 入金待ちの注文（お客さんなし。商品は「入金待ちの品 n」）。注文の一覧の絞り込みを確かめる
            for ($i = 1; $i <= $awaiting; $i++) {
                $item = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => "入金待ちの品 {$i}", 'unit_amount' => 800, 'status' => ListingStatus::Active]);
                app(StockService::class)->receive($item, Location::query()->firstOrFail(), 3);
                app(TransactionService::class)->open('sale', $seller, null, [LineDraft::fromListing($item, 1, 1000, Location::query()->firstOrFail())], actor: 'cli:e2e');
            }

            // --stripe: 店に Stripe のテスト用の鍵を置く（送信先は偽の Stripe。鍵の形はその場で組み立てる）
            if ($withStripe) {
                app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForE2eOnly00001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForE2eOnly00001234'])], 'system:e2e');
            }

            // --showcase: テーマの見本の絵を撮るときの店。商品ごとに無地の写真を 1 枚と在庫を入れる（売り切れの札を出さない）
            if (in_array('--showcase', $options, true)) {
                addShowcase();
            }

            // --every-pattern: テーマのトップに区画の種類 × パターンをすべて並べ、自由なページ（/pages/about。メニューに載せる）も作る（ADR 0039 区切り 4）
            if (in_array('--every-pattern', $options, true) && $theme !== null) {
                placeEveryPattern($theme);
            }

            // --embeds: 動画と地図の App を使い、トップに動画と地図の区画を足して公開する（ADR 0040。最初から枠が出る。外への通信はブラウザのテストが止めて数える）
            if (in_array('--embeds', $options, true)) {
                placeEmbeds($tenant);
            }

            // --no-apps: 新しい店で最初から使うになっている App（商品の CSV）も使わないにする（「使っている App はまだありません」の画面のため）
            if (in_array('--no-apps', $options, true)) {
                foreach (app(PluginStates::class)->all() as $name => $state) {
                    if ($state['enabled']) {
                        app(PluginStates::class)->set($name, false, 'system:e2e');
                    }
                }
            }

            // --tags: 見本のタグの App（tag-sample。宛先は実在しない .test）を使い、ID を入れる（ADR 0034。外への通信はブラウザのテストが止めて数える）
            if (in_array('--tags', $options, true)) {
                app(PluginStates::class)->set('tag-sample', true, 'system:e2e');
                app(PluginSettings::class)->update('tag-sample', ['tag_id' => 'TS-E2E001'], 'system:e2e');
            }

            // --consent-banner: 同意の帯を出す店にする（既定は出さない）
            if (in_array('--consent-banner', $options, true)) {
                app(ShopSettingsService::class)->setConsentBanner(true, 'system:e2e');
            }

            // --samples: 入れたときのサンプル（雑貨のお店）を入れる（ホームの開設の手順のサンプルの手順）
            if (in_array('--samples', $options, true)) {
                app(ShopSamples::class)->install();
            }

            // --stray: 返金が要る支払い（二重に払われた）のある店（運営全体の画面の困りごと）
            if (in_array('--stray', $options, true)) {
                addStrayPayment($seller);
            }

            // --subscription: 定期便の App を使う（ADR 0050。商品の画面に「App」の節が出る）
            if (in_array('--subscription', $options, true)) {
                app(PluginStates::class)->set('subscription', true, 'system:e2e');
            }

            // --subscriptions: 定期便の App を使い、会員の定期便を 2 つ入れる（管理画面の App の画面。ADR 0051）
            if (in_array('--subscriptions', $options, true)) {
                app(PluginStates::class)->set('subscription', true, 'system:e2e');
                $subscriptions = addSubscriptions($seller);
            }

            // --customers: お客さんの一覧と画面。名前と電話が同じでメールの違う 3 人と、会員 1 人
            if (in_array('--customers', $options, true)) {
                $customers = addCustomers($seller);
            }

            // クーポンを 1 つ（管理画面のクーポンの 1 件の画面。ADR 0054）。コードを入れない限り注文には効かない
            $coupon = ['id' => app(CouponService::class)->create(['code' => 'E2E-SALE', 'name' => 'ブラウザのテスト', 'kind' => 'fixed', 'value' => 500], 'system:e2e')->id];
        } finally {
            tenancy()->end();
        }

        return ['id' => $id, 'name' => $name, 'listings' => $listings, 'drafts' => $drafts, 'transaction' => $transaction, 'customers' => $customers, 'subscriptions' => $subscriptions, 'coupon' => $coupon];
    })(),

    // 店頭で確定した注文の数と、押さえた在庫の数（二度押し・同時の購入で増えすぎないことを確かめる）
    'sales' => (static function () use ($options): array {
        tenancy()->initialize(Tenant::query()->findOrFail($options[0] ?? ''));

        try {
            return ['sales' => Transaction::query()->where('kind', 'sale')->count(), 'reserved' => (int) InventoryStock::query()->sum('reserved')];
        } finally {
            tenancy()->end();
        }
    })(),

    // 「商品 1」の在庫を増やす・減らす（カートに入れた後に在庫が変わった場面）
    'stock' => (static function () use ($options): array {
        if (preg_match('/\A[+-]?\d{1,2}\z/', $options[1] ?? '') !== 1) {
            throw new InvalidArgumentException('使い方: fixtures.php stock テナント 増減（例: -2）');
        }

        // 増減があれば、その前のテナントもある（2 つ目の引数を確かめたので）
        tenancy()->initialize(Tenant::query()->findOrFail($options[0]));

        try {
            $listing = Listing::query()->where('title', '商品 1')->firstOrFail();
            $stock = app(StockService::class)->adjust($listing, Location::query()->firstOrFail(), (int) $options[1]);

            return ['available' => $stock->quantity - $stock->reserved];
        } finally {
            tenancy()->end();
        }
    })(),

    // 運営全体の画面の数を、その店だけ集める。ほかの場面の店は集めない（同時に走る場面の邪魔をしない）
    'collect' => (static function () use ($options): array {
        $id = (string) Tenant::query()->findOrFail($options[0] ?? '')->getTenantKey();

        return ['collected' => Artisan::call('operations:collect', ['--tenant' => $id]) === 0];
    })(),

    // 会員のログインのコード（ADR 0044 の 3）。ブラウザのテストのメールは array で外へ出ず、DB にはコードのハッシュだけなので、
    // 送ったコードは読み出せない。ブラウザが「コードを送る」を押した後に、同じメールへ新しいコードを出して返す（前のコードは止まる）
    'member-code' => (static function () use ($options): array {
        $email = $options[1] ?? '';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('使い方: fixtures.php member-code テナント メール');
        }

        tenancy()->initialize(Tenant::query()->findOrFail($options[0] ?? ''));

        try {
            // issue が返すのはコードの行の ID。コードは送る仕事と同じ口で読む（読んだら消える）
            $login = app(CustomerLogin::class);

            return ['code' => $login->takeForSending($login->issue($email))['code'] ?? throw new RuntimeException('コードを出せませんでした')];
        } finally {
            tenancy()->end();
        }
    })(),

    // その店の監査ログの、ある種類の記録。画面で名前と連絡先を表示したら、見た記録が残ったことを確かめる
    'audit' => (static function () use ($options): array {
        if (preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\z/', $options[1] ?? '') !== 1) {
            throw new InvalidArgumentException('使い方: fixtures.php audit テナント 種類');
        }

        // 種類（2 つ目の引数）を確かめたので、その前の店もある
        $tenant = (string) Tenant::query()->findOrFail($options[0])->getTenantKey();

        return ['events' => AuditEvent::query()->where('tenant_id', $tenant)->where('type', $options[1])->orderBy('sequence')->get()
            ->map(static fn (AuditEvent $event): array => $event->data)->values()->all()];
    })(),

    default => throw new InvalidArgumentException('使い方: fixtures.php reset | cleanup | operator | tenant [--listings=N] [--draft=N] [--stock=N] [--html-name] [--transaction] [--awaiting=N] [--stripe] [--theme=名前] [--every-pattern] [--showcase] [--embeds] [--no-apps] [--tags] [--consent-banner] [--preparing] [--samples] [--stray] [--customers] [--subscription] [--subscriptions] | sales テナント | stock テナント 増減 | collect テナント | member-code テナント メール | audit テナント 種類'),
};

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

/**
 * 顧客と配送先のある取引（受渡まで）。個人情報は「表示する」まで画面に出ないことを確かめるのに使う。
 * 取引用の商品を別に作るので、--listings の数には入らない（ただし商品の一覧には並ぶ）。
 *
 * @return array{id: string, customer: string, tracking: string}
 */
function createSale(Seller $seller): array
{
    $customerName = 'E2E 顧客 花子';
    $tracking = '1111-2222-3333';
    $store = Location::query()->firstOrFail();
    $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '取引用の商品', 'unit_amount' => 2500, 'status' => ListingStatus::Active]);
    app(StockService::class)->receive($listing, $store, 5);

    $service = app(TransactionService::class);
    $customer = Party::create(['kind' => PartyKind::Customer, 'name' => $customerName, 'email' => 'hanako@example.com', 'address' => ['zip' => '150-0001', 'line1' => '渋谷区神宮前 9-9']]);
    $sale = $service->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 2, 1000, $store)], actor: 'cli:e2e');
    $sale = $service->transition($sale, Phase::Payment, 'cli:e2e');
    app(LedgerService::class)->recordPayment($sale, 5000, PaymentRoute::Platform, 'pi_e2e', 'cli:e2e');
    $sale = $service->transition($sale, Phase::Fulfillment, 'cli:e2e');
    $line = $sale->lines()->where('line_type', LineType::Item)->firstOrFail();
    $fulfillments = app(FulfillmentService::class);
    $shipment = $fulfillments->create($sale, FulfillmentMethod::Shipping, [$line->id => 2], $store, ['name' => $customerName, 'zip' => '150-0001', 'line1' => '渋谷区神宮前 9-9'], 'cli:e2e');
    $fulfillments->ship($shipment, 'yamato', $tracking, 'cli:e2e');

    return ['id' => $sale->id, 'customer' => $customerName, 'tracking' => $tracking];
}

/**
 * 商品ごとに無地の写真（縦長）を 1 枚ずつ付け、在庫を入れる。見本はワイヤーの形で（写真は無地の枠）、色だけ商品ごとに変える。テナント文脈の中で呼ぶ
 */
function addShowcase(): void
{
    $colors = [[196, 186, 170], [168, 180, 166], [182, 172, 186], [204, 194, 178], [160, 172, 182], [190, 178, 164]];

    foreach (Listing::query()->orderBy('title')->get()->values() as $i => $listing) {
        [$r, $g, $b] = $colors[$i % count($colors)];
        $image = imagecreatetruecolor(600, 800);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));
        $path = (string) tempnam(sys_get_temp_dir(), 'e2e');
        imagejpeg($image, $path);
        app(ListingImageService::class)->add($listing, $path);
        @unlink($path);
        app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 10);
    }
}

/**
 * テーマのトップに、テーマの区画の種類 × パターンをすべて 1 つずつ、どの欄も埋めて並べて公開する。自由なページ about も作って公開し、メニューに載せ、
 * 核の共通の区画（basic_*）の種類 × パターンはそちらに並べる（トップにも置けるが、全部を 1 ページに並べると 1 ページの上限 40 を越えるため）。
 * 写真はその場で作る（ショップの写真 1 枚と、商品ごとの写真。カテゴリの札は商品の写真を使う）。テナント文脈の中で呼ぶ
 */
/**
 * 動画と地図の App（ADR 0040）を使い、トップの最初の並びの後ろに動画（YouTube。自動で再生する・繰り返すを選ぶ）と地図の区画を足して公開する。地図はキーの要らない埋め込みなので設定は無い
 */
function placeEmbeds(Tenant $tenant): void
{
    app(PluginStates::class)->set('video', true, 'system:e2e');
    app(PluginStates::class)->set('google-map', true, 'system:e2e');

    $definition = app(ThemeRegistry::class)->forTenant($tenant);
    $schema = app(AppSectionCatalog::class)->schema($definition->schema());
    $content = app(ThemeContentService::class);
    $sections = [
        ...$content->draftPage(null, $schema, 'home'),
        ['type' => 'app_video', 'pattern' => 'wide', 'values' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'heading' => '作っているところ', 'autoplay' => 'on', 'loop' => 'on']],
        ['type' => 'app_google_map', 'pattern' => 'split', 'values' => ['place' => '東京駅', 'heading' => 'アクセス', 'body' => '駅から歩いて 5 分です。']],
    ];
    $version = $content->savePage($definition->name, $schema, 'home', null, 0, $sections, 'system:e2e');
    $content->publish($definition->name, $schema, $version->id, $version->revision, 'system:e2e');
}

function placeEveryPattern(string $theme): void
{
    $schema = app(ThemeRegistry::class)->get($theme)->schema();
    $home = $schema->page('home');

    if ($home === null || $home->fixed) {
        return;
    }

    $jpeg = static function (int $width, int $height): string {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 160, 180, 150));
        $path = (string) tempnam(sys_get_temp_dir(), 'e2e');
        imagejpeg($image, $path);

        return $path;
    };
    $photo = app(ShopMediaService::class)->add($jpeg(1600, 900), '見本の写真', 'system:e2e')->id;
    $category = app(CategoryService::class)->create(['name' => '見本のカテゴリ', 'slug' => 'sample', 'parent_id' => null])->id;

    foreach (Listing::query()->get() as $listing) {
        app(ListingImageService::class)->add($listing, $jpeg(600, 800));
        app(CategoryService::class)->assign($listing, [$category]);
    }

    $first = Listing::query()->orderBy('title')->value('id');
    $value = static fn (FieldSpec $field): mixed => match ($field->type) {
        FieldType::Text, FieldType::Textarea => $field->key === 'text' ? '見本のお知らせ' : 'とても長い見本の見出しが折り返しても横にはみ出さないことを確かめる',
        FieldType::Image => $photo,
        FieldType::Link => '/products',
        // 選ぶだけの商品の欄（核の「1 つの商品を大きく」）は、最初の商品を選ぶ
        FieldType::Products => in_array('new', $field->sources, true) ? ['source' => 'new'] : ['source' => 'pick', 'ids' => [$first]],
        FieldType::Categories => [$category],
        FieldType::Choice => array_key_first($field->options),
        // カスタム区画の HTML。整えた後も残る形
        FieldType::Html, FieldType::Richtext => '<h2>見本の見出し</h2><p>とても長い見本の文が折り返しても横にはみ出さないことを確かめる<strong>太字</strong></p><ul><li>一つ目</li></ul>',
    };
    $row = static fn (array $fields): array => array_map(static fn (FieldSpec $field): mixed => is_string($v = $value($field)) && $field->max !== null ? mb_substr($v, 0, $field->max) : $v, $fields);
    $every = static function (array $types) use ($schema, $row): array {
        $sections = [];

        foreach ($types as $type) {
            $spec = $schema->section($type);

            // 1 つのページに置ける数（limit。フッターなど）までに絞る（角砂糖・kinari のフッターはパターンが 3 つで 1 つまで）
            foreach (array_slice(array_keys($spec->patterns ?? []), 0, $spec?->limit) as $pattern) {
                $sections[] = ['type' => $type, 'pattern' => $pattern, 'values' => $row($spec->fields),
                    'items' => $spec->hasItems() ? array_fill(0, min(3, $spec->maxItems), $row($spec->itemFields)) : [],
                    'blocks' => array_values(array_map(static fn (BlockSpec $block): array => ['type' => $block->key, 'values' => $row($block->fields)], $spec->blocks))];
            }
        }

        return $sections;
    };
    // 1 ページは 40 区画まで。トップにテーマの区画と核の共通の区画の残り 5 種類、自由なページにほかの核の区画を置く
    $later = ['basic_numbers', 'basic_logos', 'basic_steps', 'basic_table', 'basic_spacer'];
    $core = static fn (string $type): bool => str_starts_with($type, CoreSections::PREFIX);
    $onTop = static fn (string $type): bool => ! $core($type) || in_array($type, $later, true);

    $content = app(ThemeContentService::class);
    $top = $every(array_values(array_filter($home->types, $onTop)));
    if (count($top) > PageSpec::MAX_SECTIONS) {
        throw new LogicException('トップの区画が '.PageSpec::MAX_SECTIONS.' 個を越えます。分け方を直してください');
    }
    $version = $content->savePage($theme, $schema, 'home', null, 0, $top, 'system:e2e');

    $page = app(ShopPageService::class)->create(['title' => '私たちについて', 'slug' => 'about', 'in_nav' => true], 'system:e2e');
    $free = $schema->page(PageSpec::freeName($page->id));
    $freeSections = $every(array_values(array_filter($free === null ? [] : $free->types, static fn (string $type): bool => ! $onTop($type))));
    $freeSections[] = ['type' => 'basic_text', 'values' => ['heading' => '店の歩み', 'body' => 'ここに説明を書きます。']];
    $freeSections[] = ['type' => 'basic_image_text', 'values' => ['image' => $photo, 'heading' => '小さな工房から', 'body' => 'ここに説明を書きます。']];
    if (count($freeSections) > PageSpec::MAX_SECTIONS) {
        throw new LogicException('自由なページの区画が '.PageSpec::MAX_SECTIONS.' 個を越えます。分け方を直してください');
    }
    $version = $content->savePage($theme, $schema, PageSpec::freeName($page->id), $version->id, $version->revision, $freeSections, 'system:e2e');
    $content->publish($theme, $schema, $version->id, $version->revision, 'system:e2e');
    app(ShopPageService::class)->publish($page->id, 'system:e2e');
}

/**
 * 返金が要る支払い（二重に払われた）を 1 つ残す（運営全体の画面の困りごと）。払われた注文と、後から届いた 2 つ目の支払い
 */
function addStrayPayment(Seller $seller): void
{
    $store = Location::query()->firstOrFail();
    $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '二重払いの品', 'unit_amount' => 1500, 'status' => ListingStatus::Active]);
    app(StockService::class)->receive($listing, $store, 3);
    $service = app(TransactionService::class);
    $sale = $service->open('sale', $seller, null, [LineDraft::fromListing($listing, 1, 1000, $store)], actor: 'cli:e2e');
    $sale = $service->transition($sale, Phase::Payment, 'cli:e2e');
    PaymentAttempt::create(['transaction_id' => $sale->id, 'provider' => 'stripe', 'provider_ref' => 'cs_live_e2e_stray1', 'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_live_e2e_stray1', 'amount' => 1500, 'currency' => 'JPY', 'status' => 'paid', 'expires_at' => now()->addHour(), 'paid_at' => now()]);
    $late = PaymentAttempt::create(['transaction_id' => $sale->id, 'provider' => 'stripe', 'provider_ref' => 'cs_live_e2e_stray2', 'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_live_e2e_stray2', 'amount' => 1500, 'currency' => 'JPY', 'status' => 'expired', 'expires_at' => now()]);
    StrayPayment::create(['transaction_id' => $sale->id, 'attempt_id' => $late->id, 'reason' => 'duplicate', 'amount' => 1500, 'payment_ref' => 'pi_e2e_stray2']);
}

/**
 * お客さんの一覧と画面の下ごしらえ（ADR 0044）。ショップの注文と同じく、メールでお客さんの記録を引いて注文につなぐ。
 * 花子は 2 つ払った注文と 1 つの入金待ち、同じ名前と電話で違うメールの記録が 2 つ（work・old。同じ人かもしれない）、
 * 太郎はメールのコードで入った会員（届け先を 1 つ登録）。作った順は 花子 → work → old → 太郎
 *
 * @return array<string, array{id: string, email: string, name: string, phone: string}>
 */
function addCustomers(Seller $seller): array
{
    $store = Location::query()->firstOrFail();
    $service = app(TransactionService::class);
    $people = [
        'hanako' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'orders' => [4400, 3300, null]],
        'work' => ['email' => 'hanako.work@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'orders' => [2000]],
        'old' => ['email' => 'hanako.old@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'orders' => [1500]],
        'taro' => ['email' => 'taro@example.com', 'name' => '佐藤 太郎', 'phone' => '0311112222', 'orders' => [5400]],
    ];
    $result = [];

    foreach ($people as $key => $person) {
        $account = app(CustomerAccounts::class)->forEmail($person['email'], 'storefront');

        // 金額 null は入金待ち（払われていない）の注文
        foreach ($person['orders'] as $amount) {
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => "お客さんの品 {$key}", 'unit_amount' => $amount ?? 999, 'status' => ListingStatus::Active]);
            app(StockService::class)->receive($listing, $store, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => $person['name'], 'email' => $person['email'], 'phone' => $person['phone'], 'address' => ['zip' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 9-9']]);
            $sale = $service->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $store)], actor: 'storefront', customerAccount: $account);

            if ($amount !== null) {
                $service->transition($sale, Phase::Payment, 'cli:e2e');
            }
        }

        $result[$key] = ['id' => $account->id, 'email' => $person['email'], 'name' => $person['name'], 'phone' => $person['phone']];
    }

    // 太郎はメールのコードで入って会員になり、届け先を 1 つ登録する（ショップのログインと同じ口）
    $login = app(CustomerLogin::class);
    $code = $login->takeForSending($login->issue($people['taro']['email']))['code'] ?? throw new RuntimeException('コードを出せませんでした');
    $member = $login->verify($people['taro']['email'], $code) ?? throw new RuntimeException('会員にできませんでした');
    app(CustomerAddresses::class)->add($member, ['name' => '佐藤 太郎', 'phone' => '0311112222', 'postal' => '100-0005', 'prefecture' => '東京都', 'city' => '千代田区', 'line1' => '丸の内 1-1-1'], 'cli:e2e');

    return $result;
}

/**
 * 管理画面の App の画面（ADR 0051）の下ごしらえ。会員 1 人の定期便を 2 つ（続いている・お休み）。中身は tests/e2e/SubscriptionFixtures.php
 * （許す一覧の tests/e2e/Subscription* で公開の木に入らない。class_exists で囲むのは、公開の木の静的解析が無いクラスを数えないため）
 *
 * @return array{active: string, paused: string, listing: string, customer: string}
 */
function addSubscriptions(Seller $seller): array
{
    if (! class_exists(SubscriptionFixtures::class)) {
        throw new RuntimeException('定期便の App が入っていない木では --subscriptions を使えません（tests/e2e/SubscriptionFixtures.php がありません）');
    }

    return SubscriptionFixtures::add($seller);
}
