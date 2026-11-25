<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Models\Tenant;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AppContract;
use Tests\Support\ContractPluginGate;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * 全部の App に共通の確かめ（App の契約のテスト。外の提案 11-06「無効なら使えない・他店のデータを読めない・権限不足なら操作できない、を
 * 全プラグインに共通テストとして」）。登録簿の全部の App に、App ごとの 1 件のテストとして当たる（データの提供者。App を足せば自動で当たる）。
 *
 *   1. 使っていない店: App のショップの経路と、管理画面の App の画面・操作の API が 404。ショップの経路の 404 は使う店の関所（plugin:<名前>）で止まったもの。
 *      止めても開くと宣言した経路（keptStorefrontRoutes）だけは関所を通らずに App の受け口まで届く
 *   2. ほかの店: App の画面の 1 件の API に、ほかの店の記録の ID を入れると 404
 *   3. 権限: 閲覧の段階は App の画面の操作が 403。money の操作は運用の段階でも 403、お金の段階でも直前の再確認の前は 423。断った操作で記録は変わらない
 *   4. 秘密の設定: 店の鍵で暗号化して置き、管理画面の応答・ショップ・記録（イベント）に中身を出さない
 *
 * 速さのため、店（App を切り替える店とほかの店）はクラスで 1 回だけ作り、App ごとに使う・使わないを切り替える。
 * そのため中央の表は最初のテストの前にだけ空にし（DatabaseTruncation）、作った店はクラスの終わりに消す。
 * 宣言とコードだけで決まる確かめ（経路の印・お金の印・マニフェスト）は AppDeclarationsTest
 */
final class AppContractTest extends TestCase
{
    use AppContractRecords;
    use DatabaseTruncation {
        truncateDatabaseTables as private truncateAllTables;
    }
    use OperatorSession;

    /** App を使う・使わないを切り替える店 */
    private const string HERE = 'aca';

    /** ほかの店（画面の記録を作る） */
    private const string OTHER = 'acb';

    /**
     * 作った 1 件がお金の操作（money）を出す App。お金の段階の確かめ（運用は 403・再確認の前は 423）が、ここで本物の App の操作に当たる
     * （予約の当日払いの「受け取った」）。チケットの「無効にして返金する」は、今の流れでは出したチケットの注文が完了になり画面に出ないので、
     * お金の印の有無は AppDeclarationsTest の読み取りで確かめる
     */
    private const array MONEY_RECORDS = ['booking'];

    private static bool $shops = false;

    /** @var array<string, string|null> App の名前 => ほかの店に作った画面の 1 件 */
    private static array $records = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->configureAdminHost();
        // 使う店の関所（plugin:<名前>）が止めた App を残す（404 の出どころを見分ける）
        $this->app['router']->aliasMiddleware('plugin', ContractPluginGate::class);
        ContractPluginGate::$stopped = [];
        Mail::fake();
        FakeStripe::install();

        if (! self::$shops) {
            foreach ([self::HERE, self::OTHER] as $id) {
                Tenant::query()->find($id)?->delete();
                Tenant::create(['id' => $id, 'name' => "店 {$id}"])->domains()->create(['domain' => "{$id}.localhost"]);
            }

            self::$shops = true;
        }
    }

    protected function tearDown(): void
    {
        FakeStripe::reset();
        parent::tearDown();
    }

    /**
     * 店はクラスで分け合うので、中央の表を空にするのは最初のテストの前だけ
     */
    protected function truncateDatabaseTables(): void
    {
        if (! self::$shops) {
            $this->truncateAllTables();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$shops) {
            // テストの後はアプリが無いので、片付けのためにだけ起こす
            $case = new self('cleanup');
            $app = $case->createApplication();

            try {
                Tenant::query()->whereIn('id', [self::HERE, self::OTHER])->get()->each->delete();
            } finally {
                $app->flush();
                // 起こしたアプリが積んだエラー・例外ハンドラを外す。テストの外で積むと PHPUnit は自分のハンドラを
                // 入れられなくなり（Runner\ErrorHandler::enable は積まれていると何もしないで戻る）、この処理より後の
                // テストは全部 Risky になり、警告と非推奨の検知も効かなくなる
                HandleExceptions::flushState($case);
            }
        }

        self::$shops = false;
        self::$records = [];
        self::$contractMoments = [];
        parent::tearDownAfterClass();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function apps(): array
    {
        return AppContract::provider();
    }

    #[DataProvider('apps')]
    public function test_an_app_the_shop_does_not_use_answers_404_on_its_shop_routes_and_admin_api(string $plugin): void
    {
        $definition = app(PluginRegistry::class)->get($plugin);
        $this->use(self::HERE, $plugin, false);
        $this->owner();
        $base = '/api/tenants/'.self::HERE;
        $id = strtolower((string) Str::ulid());

        // 管理画面: App の画面の一覧・1 件・操作・足す・直す・読む・書き出し・取り込み・注文の書類・商品の画面の App の欄
        $calls = [
            ['GET', "{$base}/apps/{$plugin}/records", []],
            ['GET', "{$base}/apps/{$plugin}/records/{$id}", []],
            ['POST', "{$base}/apps/{$plugin}/records/{$id}/actions/probe", []],
            ['POST', "{$base}/apps/{$plugin}/records", ['values' => []]],
            ['PUT', "{$base}/apps/{$plugin}/records/{$id}", ['values' => []]],
            ['POST', "{$base}/apps/{$plugin}/lookup", ['input' => 'probe']],
            ['POST', "{$base}/apps/{$plugin}/export", ['values' => []]],
            ['POST', "{$base}/apps/{$plugin}/imports", []],
            ['GET', "{$base}/apps/{$plugin}/documents/probe", []],
            ['POST', "{$base}/apps/{$plugin}/documents/probe", []],
            ['PUT', "{$base}/listings/{$id}/apps/{$plugin}", ['values' => []]],
        ];

        foreach ($calls as [$method, $path, $body]) {
            $this->assertSame(404, $this->send($method, $path, $body)->status(), "{$plugin}: 使っていない店で {$method} {$path} が 404 でない");
        }

        // メニューと注文の書類のボタンにも出ない
        $this->assertNotContains($plugin, array_column((array) $this->send('GET', "{$base}/app-pages")->assertOk()->json('pages'), 'plugin'));
        $this->assertNotContains($plugin, array_column((array) $this->send('GET', "{$base}/order-documents")->assertOk()->json('documents'), 'plugin'));

        // ショップ: App の経路は使う店の関所で止まって 404。止めても開くと宣言した経路だけは、関所を通らずに App の受け口まで届く
        $kept = AppContract::keptWhenOff($definition);

        foreach (AppContract::shopRoutes(app(Router::class), $plugin, $definition->shopPages?->prefix()) as $route) {
            $method = $route->methods()[0];
            $url = 'http://'.self::HERE.'.localhost/'.AppContract::fill($route);
            $stopped = count(ContractPluginGate::$stopped);
            $status = $this->call($method, $url)->status();
            $gate = array_slice(ContractPluginGate::$stopped, $stopped);

            if (in_array($route->getName(), $kept, true)) {
                $this->assertSame([], $gate, "{$plugin}: 止めても開くと宣言した {$method} {$url} が、使う店の関所で止まった");
                $this->assertLessThan(500, $status, "{$plugin}: 止めた店で {$method} {$url} が壊れた");

                continue;
            }

            $this->assertSame([404, [$plugin]], [$status, $gate], "{$plugin}: 使っていない店で {$method} {$url} が、使う店の関所で 404 にならない");
        }

        foreach ($definition->app()->scripts as $script) {
            $this->get('http://'.self::HERE.".localhost/apps/{$plugin}/{$script->file}")->assertNotFound();
        }

        // 確かめが効いていること: 使う店にすると、画面の一覧とスクリプトは開く（上の 404 は「使っていない」から）
        $this->use(self::HERE, $plugin, true);

        try {
            $this->assertSame($definition->adminPage !== null ? 200 : 404, $this->send('GET', "{$base}/apps/{$plugin}/records")->status(), "{$plugin}: 使う店で画面の一覧が開かない");

            foreach ($definition->app()->scripts as $script) {
                $this->get('http://'.self::HERE.".localhost/apps/{$plugin}/{$script->file}")->assertOk();
            }
        } finally {
            $this->use(self::HERE, $plugin, false);
        }
    }

    #[DataProvider('apps')]
    public function test_a_record_of_another_shop_is_not_found_through_this_shop(string $plugin): void
    {
        $this->use(self::HERE, $plugin, true);
        $this->use(self::OTHER, $plugin, true);
        $id = $this->record($plugin);
        $this->atMoment($plugin);
        $this->owner();

        try {
            if ($id === null) {
                // 画面の無い App は、使っていても画面の API が 404
                $this->send('GET', '/api/tenants/'.self::HERE."/apps/{$plugin}/records")->assertNotFound();

                return;
            }

            // その店では見える（作った記録が本物の 1 件であること）
            $there = $this->send('GET', '/api/tenants/'.self::OTHER."/apps/{$plugin}/records/{$id}");
            $this->assertSame(self::recordStatus($plugin), $there->status(), "{$plugin}: 記録を作った店で 1 件が開かない（AppContractRecords の作り方を直す）");
            $actions = array_column((array) $there->json('record.actions'), 'key');

            // ほかの店からは、見る・操作する・直すのどれも 404（操作は、その店の 1 件が出している操作の名前で）
            $here = '/api/tenants/'.self::HERE."/apps/{$plugin}/records/{$id}";
            $this->assertSame(404, $this->send('GET', $here)->status(), "{$plugin}: ほかの店の記録が見える");

            foreach ([...$actions, 'probe'] as $action) {
                $this->assertSame(404, $this->send('POST', "{$here}/actions/{$action}")->status(), "{$plugin}: ほかの店の記録に {$action} が通る");
            }

            $this->assertSame(404, $this->send('PUT', $here, ['values' => []])->status(), "{$plugin}: ほかの店の記録を直せる");
        } finally {
            $this->use(self::HERE, $plugin, false);
        }
    }

    #[DataProvider('apps')]
    public function test_staff_need_operate_to_act_and_money_with_reconfirmation_for_money_actions(string $plugin): void
    {
        $this->use(self::OTHER, $plugin, true);
        $id = $this->record($plugin) ?? strtolower((string) Str::ulid());
        $this->atMoment($plugin);
        $base = '/api/tenants/'.self::OTHER."/apps/{$plugin}";
        $hasPage = app(PluginRegistry::class)->get($plugin)->adminPage !== null;

        // 断る前の 1 件（オーナーが見たもの）。操作の名前と money の印もここから
        $this->owner();
        $before = $hasPage ? $this->send('GET', "{$base}/records/{$id}")->assertStatus(self::recordStatus($plugin))->json('record') : null;
        $actions = is_array($before) ? (array) $before['actions'] : [];

        [$staff] = $this->operator('staff');
        $access = app(OperatorAccess::class);

        // 閲覧の段階: 1 件が出している操作も、出していない操作も、足す・直す・読む・書き出し・取り込みも 403
        $access->grant($staff, self::OTHER, 'read', 'test');
        $writes = [
            ...array_map(static fn (array $a): array => ['POST', "{$base}/records/{$id}/actions/{$a['key']}", ['values' => self::values($a)]], $actions),
            ['POST', "{$base}/records/{$id}/actions/probe", []],
            ['POST', "{$base}/records", ['values' => []]],
            ['PUT', "{$base}/records/{$id}", ['values' => []]],
            ['POST', "{$base}/lookup", ['input' => 'probe']],
            ['POST', "{$base}/export", ['values' => []]],
            ['POST', "{$base}/imports", []],
        ];

        foreach ($writes as [$method, $path, $body]) {
            $this->assertSame(403, $this->send($method, $path, $body)->status(), "{$plugin}: 閲覧の段階で {$method} {$path} が 403 でない");
        }

        // お金の操作: 印の付いたものと、お金の言葉に当たるもの（例外の一覧を除く。印を外すと、運用の段階で通ってしまうのでここで落ちる）
        $money = array_values(array_filter($actions, static fn (array $a): bool => $a['money'] === true || self::looksLikeMoney($plugin, $a)));

        foreach ($money as $action) {
            $this->assertTrue($action['money'], "{$plugin}: 操作 {$action['key']} は「".AppContract::moneyWord($action['key'], "{$action['label']} {$action['confirm']}").'」に当たるのに money の印が無い');
        }

        // 記録の作り方がお金の操作を出す App（お金の段階の確かめが空回りしないこと）
        if (in_array($plugin, self::MONEY_RECORDS, true)) {
            $this->assertNotEmpty($money, "{$plugin}: 作った 1 件がお金の操作を出していない");
        }

        // 運用の段階は 403、お金の段階でも直前の再確認の前は 423

        foreach (['operate' => 403, 'money' => 423] as $level => $status) {
            $access->grant($staff, self::OTHER, $level, 'test');

            foreach ($money as $action) {
                $this->assertSame($status, $this->send('POST', "{$base}/records/{$id}/actions/{$action['key']}", ['values' => self::values($action)])->status(), "{$plugin}: {$level} の段階でお金の操作 {$action['key']} が {$status} でない");
            }
        }

        // 断った操作は App に渡っていない（1 件はオーナーが見た時のまま。お金の段階は個人情報も見られるので同じ形で比べられる）
        if (is_array($before)) {
            $after = $this->send('GET', "{$base}/records/{$id}")->assertOk()->json('record');
            $this->assertSame($before, $after, "{$plugin}: 断ったはずの操作で記録が変わった");
        } else {
            $this->assertSame([], $money);
        }
    }

    #[DataProvider('apps')]
    public function test_secret_settings_are_encrypted_and_never_shown(string $plugin): void
    {
        $specs = app(PluginRegistry::class)->get($plugin)->app()->settings;
        $secrets = array_filter($specs, static fn (SettingSpec $s): bool => $s->secret);

        if ($secrets === []) {
            $this->assertSame([], $secrets);

            return;
        }

        // 管理画面と同じく全部の項目を送る（秘密と入れるべき文は宣言に合う値、切り替えと選ぶは値あり、ほかの文は空）
        $input = [];

        foreach ($specs as $key => $spec) {
            $input[$key] = $spec->secret || $spec->required || in_array($spec->type, [SettingType::Toggle, SettingType::Choice], true) ? self::sample($spec) : null;
        }

        $this->tenancyIn(self::HERE, function () use ($plugin, $input, $secrets): void {
            $settings = app(PluginSettings::class);
            $settings->update($plugin, $input, 'test');
            $row = DB::table('plugin_settings')->where('plugin', $plugin)->first();
            $this->assertNotNull($row);
            $admin = $settings->forAdmin($plugin);
            $public = (array) $settings->publicValues($plugin);
            $events = DomainEvent::query()->where('type', 'plugin.settings_changed')->pluck('payload')->all();
            $this->assertNotEmpty($events);
            $event = json_encode($events, JSON_THROW_ON_ERROR);

            foreach (array_keys($secrets) as $key) {
                $value = (string) $input[$key];
                // DB には暗号文だけ（平文の値も、値の入った列も無い）
                $this->assertStringNotContainsString($value, (string) $row->secrets, "{$plugin}.{$key}: 秘密の値が平文で置かれている");
                $this->assertStringNotContainsString($value, (string) $row->values, "{$plugin}.{$key}: 秘密の値がふつうの値の列にある");
                // 管理画面には「入っているか」だけ、ショップと記録には何も
                $this->assertTrue($admin['secrets'][$key], "{$plugin}.{$key}");
                $this->assertArrayNotHasKey($key, $admin['values']);
                $this->assertArrayNotHasKey($key, $public);
                $this->assertStringNotContainsString($value, $event);
                // サーバーの中では読み戻せる
                $this->assertSame($value, $settings->secret($plugin, $key));
            }
        });
    }

    /**
     * 作った店で 1 件を開いた時の答え（ふつうは 200。1 件の画面が今壊れている App〔AppContract::BROKEN_RECORD_PAGES〕は 500。
     * その App も、ほかの店の 404 と閲覧の 403 は確かめる。直ったら一覧から消す。消さないとここで 200 と 500 の違いで落ちる）
     */
    private static function recordStatus(string $plugin): int
    {
        return AppContract::brokenRecordPage($plugin) !== null ? 500 : 200;
    }

    /**
     * 画面に出た操作が、お金の言葉に当たるか（AppContract の例外の一覧に理由つきで載せたものは除く）
     *
     * @param  array<string, mixed>  $action
     */
    private static function looksLikeMoney(string $plugin, array $action): bool
    {
        $key = (string) $action['key'];

        foreach ([AppContract::moneyUnmarked($plugin), AppContract::MONEY_WORDS_ONLY[$plugin] ?? []] as $list) {
            if (isset($list[$key])) {
                return false;
            }
        }

        return AppContract::moneyWord($key, "{$action['label']} {$action['confirm']}") !== null;
    }

    /**
     * ほかの店に作った画面の 1 件（クラスで 1 回だけ作る。画面の無い App は null）
     */
    private function record(string $plugin): ?string
    {
        if (array_key_exists($plugin, self::$records)) {
            return self::$records[$plugin];
        }

        $hasPage = app(PluginRegistry::class)->get($plugin)->adminPage !== null;
        $id = $hasPage ? $this->tenancyIn(self::OTHER, fn (): ?string => $this->contractRecord($plugin, self::OTHER)) : null;
        $this->assertTrue(! $hasPage || is_string($id), "{$plugin}: 画面を持つ App の 1 件の作り方が AppContractRecords に無い（足す）");

        return self::$records[$plugin] = $id === null ? null : strtolower($id);
    }

    /**
     * 1 件の操作が出る時刻へ移る（予約の当日など。AppContractRecords が残したときだけ）。
     * ほかは今の秒で時計を止める（1 件の中の時刻つきの値〔記事のプレビューの URL の期限など〕を、断る前と後で比べるため）
     */
    private function atMoment(string $plugin): void
    {
        if (isset(self::$contractMoments[$plugin])) {
            $this->travelTo(self::$contractMoments[$plugin]);
        } else {
            $this->freezeSecond();
        }
    }

    private function use(string $shop, string $plugin, bool $enabled): void
    {
        $this->tenancyIn($shop, static fn () => app(PluginStates::class)->set($plugin, $enabled, 'test'));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    private function tenancyIn(string $shop, \Closure $work): mixed
    {
        tenancy()->initialize(Tenant::query()->findOrFail($shop));

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }

    /**
     * オーナーとしてログインする（運営はテストごとに作る。中央の表をテストごとに空にしないので、メールは重ねない）
     */
    private function owner(): void
    {
        $this->operator('owner');
    }

    /**
     * @return array{0: Operator, 1: SoftwareAuthenticator}
     */
    private function operator(string $role): array
    {
        // 前の人はログアウトする（ログインしたままでは登録できない）
        if ($this->sessionId !== null) {
            $this->send('POST', '/auth/logout')->assertNoContent();
        }

        return $this->enrolledOperator(Str::lower(Str::random(12))."@{$role}.example.com", $role);
    }

    /**
     * 操作の欄（無効にする理由など）を宣言に合う値で埋める。欄の確かめは権限の確かめより先に動くので、合う値で送る
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private static function values(array $action): array
    {
        $out = [];

        foreach ((array) ($action['form']['fields'] ?? []) as $field) {
            $out[$field['key']] = match ($field['type']) {
                'choice' => $field['options'][0][0],
                'toggle' => true,
                default => '契約のテストの値',
            };
        }

        return $out;
    }

    /**
     * 設定の項目の宣言に合う試しの値（合う値が無ければ止める。足すときは候補に足す）
     */
    private static function sample(SettingSpec $spec): bool|string
    {
        if ($spec->type === SettingType::Toggle) {
            return true;
        }

        if ($spec->type === SettingType::Choice) {
            return (string) array_key_first($spec->options);
        }

        if ($spec->type === SettingType::Media) {
            self::fail("設定の項目 {$spec->key} は写真で、試しの値を作れません（AppContractTest::sample に写真の作り方を足す）");
        }

        $candidates = [
            'shop-'.Str::lower(Str::random(6)).'@example.com',
            'https://hooks.slack.com/services/T0000AAAA/B0000BBBB/'.Str::random(24),
            Str::random(43),
            bin2hex(random_bytes(16)),
            (string) random_int(1000000000, 9999999999),
            'G-'.Str::upper(Str::random(10)),
            'TS-'.Str::upper(Str::random(8)),
        ];

        foreach ($candidates as $candidate) {
            if ($spec->problem($candidate) === null) {
                return $candidate;
            }
        }

        self::fail("設定の項目 {$spec->key} の宣言に合う試しの値がありません（AppContractTest::sample の候補に足す）");
    }
}
