<?php

namespace Tests;

use App\Core\Plugin\PluginRegistry;
use App\Core\Shop\Models\ShopSettings;
use App\Core\Shop\ShopStatus;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\AppContract;
use Tests\Support\TemplatedTenantDatabaseManager;
use Tests\Support\TestEnvironmentGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * DatabaseTruncation で空にしない表。監査ログは追記専用（TRUNCATE もトリガーで拒否する）。
     *
     * @var list<string>
     */
    protected array $exceptTables = ['audit_events'];

    /**
     * テストで作った店のショップを公開にしておくか。本物の店は作った直後は準備中だが、ショップの画面を確かめる
     * 多くのテストは準備中の関所の外の話なので、作った直後に公開にする（イベントは残さない。テストの前提の用意）。
     * 準備中を確かめるテストは偽にする
     */
    protected bool $openNewShops = true;

    /**
     * テストで作る店の DB を、移行を流し終えた型から写すか（ADR 0057。Tests\Support\TemplatedTenantDatabaseManager）。
     * 店を作るたびに移行（百本近く）を一から流す時間を省く。店のロールと権限は本番と同じ形になる
     * （TenantDatabaseTemplateTest が一から作った店と比べる）。移行を一から流す道そのものを確かめるテストは偽にする
     */
    protected bool $tenantDatabasesFromTemplate = true;

    /**
     * おかしな動きを数えるか（ADR 0103）。監査ログは追記だけでテストの間で消えないので、ほかのテストが残した
     * ログインの失敗・店への拒否・運営の追加などを数えて、知らせのメールの中身が流す順で揺れる。数えるのを確かめるテスト（AnomalyAlertsTest）だけ真にする
     */
    protected bool $detectAnomalies = false;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->openNewShops) {
            // 店を作る流れ（DB・マイグレーション・シーダー）の後に動く
            Event::listen(TenantCreated::class, static function (TenantCreated $event): void {
                $event->tenant->run(static fn () => ShopSettings::query()->update(['storefront_status' => ShopStatus::Open->value]));
            });
        }
    }

    /**
     * 読み込んだ設定がテスト用の DB・写真の置き場を指しているかを、表を空にする（setUpTraits）前に確かめる。
     * 設定の控えがあると phpunit.xml の切り替えが効かず、開発の DB のまま走って開発の店を消すため。
     * 秘密の鍵と外へ出る設定は、設定を読む前に決まった値へ上書きする
     *
     * @return Application
     */
    public function createApplication()
    {
        // 秘密の鍵と外へ出る設定は、シェルから何が渡されても決まった値にしてから設定を読む（phpunit.xml の force はシェルの値に負ける）
        TestEnvironmentGuard::pinEnvironment();

        $app = parent::createApplication();

        TestEnvironmentGuard::assertSafe(TestEnvironmentGuard::PHPUNIT, TestEnvironmentGuard::valuesFromApplication($app));

        // 店の DB は型から写す（店を作る流れ〔DB・移行・初めの行〕はそのまま。移行は流し済みなので何もしない）
        if ($this->tenantDatabasesFromTemplate) {
            $app->make('config')->set('tenancy.database.managers.pgsql', TemplatedTenantDatabaseManager::class);
        }

        $app->make('config')->set('admin.detect_anomalies', $this->detectAnomalies);

        return $app;
    }

    /**
     * HTTP テストではミドルウェアが初期化したテナント文脈がテスト終了後も残り、
     * 既定接続がテナント DB を指したままになる。teardown（RefreshDatabase のロールバック等）が
     * 中央 DB で動くよう、先にテナント文脈を閉じる。
     */
    protected function tearDown(): void
    {
        // 入口の守りで止まったときはアプリが無い
        if ($this->app !== null && tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * 名前の App が入っていなければ、このテストを飛ばす（ADR 0098。公開用の木の 1.0 には定期便・予約・チケット・抽選販売の App が入らない）。
     * 開発用の木には 4 つとも入っているので、今までどおり流れる
     */
    protected function requiresApps(string ...$names): void
    {
        $missing = array_values(array_filter($names, fn (string $name): bool => ! $this->hasApp($name)));

        if ($missing !== []) {
            $this->markTestSkipped('App「'.implode('・', $missing).'」が入っていない木（公開用の 1.0 など。ADR 0098）なので飛ばす');
        }
    }

    /**
     * 名前の App が入っているか。テストの一部だけがその App に頼るとき、その部分だけを分ける。
     * 入っているかは plugins/ のパッケージで決める。パッケージがあるのに登録簿に無いのは壊れなので、偽を返さずに落とす
     * （開発用の木で黙って飛ぶことが無いように）
     */
    protected function hasApp(string $name): bool
    {
        if (! isset(AppContract::packages()[$name])) {
            return false;
        }

        $this->assertTrue($this->app->make(PluginRegistry::class)->has($name), "App「{$name}」のパッケージがあるのに登録簿に無い");

        return true;
    }

    /**
     * 公開の木に入らない App（定期便・予約・チケット・抽選販売）のクラスを使う所は、tests/ の Booking*・Lottery*・Subscription*・
     * Ticket* の名前の部品のファイルへ分けてある（許す一覧で公開の木に入らない。ADR 0098）。使う側は、App が入っていると分かった後に
     * `if (! class_exists(部品::class)) { $this->missingPart('ファイル'); }` で囲んでから部品を作る。
     * class_exists で囲むのは、公開の木の静的解析（phpstan）が無い部品のクラスを数えないため（開発用の木では部品の型のまま全部見る）。
     * App が入っているのに部品が無いのは壊れなので、飛ばさずに落とす（開発用の木で黙って確かめが減らないように）
     */
    protected function missingPart(string $file): never
    {
        $this->fail("App が入っているのに、テストの部品（{$file}）がありません");
    }
}
