<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminAttention;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\AdminTable;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\AdminPages;
use App\Core\Plugin\Services\PluginStates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use LogicException;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App の画面の核（ADR 0051）。App が渡す中身は核の型だけ（文・数・金額・日付・状態・核の画面へのリンク）で、合わなければ開発の誤りとして止める。
 * 出すのは使っている App だけ。操作は今の 1 件の画面が出しているものだけを App に渡す（ほかは 404 で App を呼ばない）。
 * 経路は見るのが閲覧、操作が運用
 */
final class AdminPagesTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string ID = '01JBQ0000000000000000000AA';

    private FakeAdminPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->page = new FakeAdminPage;
        app(PluginRegistry::class)->register(new PluginDefinition('fake-page', '見本の App', '', 1, adminPage: $this->page));
        $this->tenant('apa');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_money_actions_reach_the_app_only_for_money_staff(): void
    {
        $this->inside('apa', fn () => app(PluginStates::class)->set('fake-page', true, 'test'));
        $this->page->money = true;
        $this->page->danger = false;
        [$staff, $key] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'apa', 'operate', 'test');
        $path = '/api/tenants/apa/apps/fake-page/records/'.self::ID;

        // お金に触れる操作（当日払いの受け取り・返金など）は、運用の段階では App に渡さない
        $this->send('GET', $path)->assertOk()->assertJsonPath('record.actions.0.money', true);
        $this->send('POST', $path.'/actions/close')->assertForbidden();
        $this->assertSame(0, $this->page->calls());

        // お金の段階でも、直前の再確認の前は 423 で App を呼ばない（取り消せない操作でなくても。注文の取消・入金の確認と同じ）
        $access->grant($staff, 'apa', 'money', 'test');
        $this->send('POST', $path.'/actions/close')->assertStatus(423);
        $this->reconfirm($staff, $key);
        $this->send('POST', $path.'/actions/close')->assertOk();
        // App に渡ったのは再確認の後の 1 回だけ（423 の時は渡っていない）
        $this->assertSame(1, $this->page->calls());
    }

    public function test_only_apps_in_use_have_a_page_and_values_come_out_in_the_core_shapes(): void
    {
        $this->enrolledOperator();

        $this->send('GET', '/api/tenants/apa/app-pages')->assertOk()->assertExactJson(['pages' => []]);
        $this->send('GET', '/api/tenants/apa/apps/fake-page/records')->assertNotFound();
        $this->send('GET', '/api/tenants/apa/apps/nothing/records')->assertNotFound();

        $this->inside('apa', fn () => app(PluginStates::class)->set('fake-page', true, 'test'));
        $this->send('GET', '/api/tenants/apa/app-pages')->assertOk()->assertExactJson(['pages' => [['plugin' => 'fake-page', 'title' => '見本']]]);

        // 絞り込みを書かなければ先頭。値は形どおり（日付は Y-m-d、日時は ISO 8601、書かなかった列は null）。HTML の文字もただの文として渡る
        $list = $this->send('GET', '/api/tenants/apa/apps/fake-page/records')->assertOk();
        $list->assertJsonPath('filter', 'open')->assertJsonPath('filters.1', ['key' => 'closed', 'label' => '閉じた', 'count' => 0]);
        $list->assertJsonPath('rows.0', ['id' => self::ID, 'cells' => [
            'name' => '<b>見本</b>', 'amount' => 5400, 'day' => '2026-11-02', 'at' => '2026-11-02T03:00:00+00:00',
            'state' => ['label' => '続いている', 'tone' => 'good'], 'who' => ['label' => 'お客さん', 'kind' => 'customer', 'id' => self::ID], 'none' => null,
        ]]);
        $list->assertJsonPath('meta', ['page' => 1, 'per_page' => AdminPages::PER_PAGE, 'total' => 1]);
        $this->assertSame('open', $this->page->askedFilter);
        $this->send('GET', '/api/tenants/apa/apps/fake-page/records?filter=closed&page=3')->assertOk();
        $this->assertSame(['closed', 3], [$this->page->askedFilter, $this->page->askedPage]);
        // 知らない絞り込みは 404、形の違う絞り込み・ページは 422
        $this->send('GET', '/api/tenants/apa/apps/fake-page/records?filter=other')->assertNotFound();
        $this->send('GET', '/api/tenants/apa/apps/fake-page/records?filter=DROP')->assertUnprocessable();
        $this->send('GET', '/api/tenants/apa/apps/fake-page/records?page=0')->assertUnprocessable();

        // 1 件の様子。無い ID は 404
        $record = $this->send('GET', '/api/tenants/apa/apps/fake-page/records/'.self::ID)->assertOk()->json('record');
        $this->assertSame(['見本の 1 件', '見本'], [$record['title'], $record['page_title']]);
        $this->assertSame([['label' => '金額', 'type' => 'money', 'value' => 1200]], $record['facts']);
        $this->assertSame([['key' => 'close', 'label' => '閉じる', 'confirm' => '閉じますか', 'danger' => true, 'repeatable' => false, 'money' => false]], $record['actions']);
        $this->send('GET', '/api/tenants/apa/apps/fake-page/records/01JBQ0000000000000000000ZZ')->assertNotFound();

        // 使うのをやめた App は、メニューにも経路にも無い
        $this->inside('apa', fn () => app(PluginStates::class)->set('fake-page', false, 'test'));
        $this->send('GET', '/api/tenants/apa/app-pages')->assertExactJson(['pages' => []]);
        $this->send('GET', '/api/tenants/apa/apps/fake-page/records/'.self::ID)->assertNotFound();
    }

    public function test_actions_are_only_those_the_record_offers_now_and_need_the_operate_level(): void
    {
        $this->inside('apa', fn () => app(PluginStates::class)->set('fake-page', true, 'test'));
        [$staff, $key] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'apa', 'read', 'test');

        // 閲覧の段階では見られるが、操作は断る（App を呼ばない）
        $this->send('GET', '/api/tenants/apa/apps/fake-page/records/'.self::ID)->assertOk();
        $this->send('POST', '/api/tenants/apa/apps/fake-page/records/'.self::ID.'/actions/close')->assertForbidden();
        $this->assertSame(0, $this->page->calls());

        $access->grant($staff, 'apa', 'operate', 'test');
        // 画面に出ていない操作は 404 で、App を呼ばない（下で App に渡ったのが 1 回だけなことで確かめる）
        $this->send('POST', '/api/tenants/apa/apps/fake-page/records/'.self::ID.'/actions/reopen')->assertNotFound();

        // 取り消せない操作（danger）は、直前の再確認の前は 423 で App を呼ばない
        $this->send('POST', '/api/tenants/apa/apps/fake-page/records/'.self::ID.'/actions/close')->assertStatus(423);
        $this->reconfirm($staff, $key);

        // 出ている操作は、操作した人の印つきで App に渡り、行った後の 1 件が返る
        $this->send('POST', '/api/tenants/apa/apps/fake-page/records/'.self::ID.'/actions/close')->assertOk()->assertJsonPath('record.actions', []);
        $this->assertSame([[self::ID, 'close', $staff->actor()]], $this->page->acted);

        // 閉じた後は出ていないので 404。App の断りは 409 と文
        $this->send('POST', '/api/tenants/apa/apps/fake-page/records/'.self::ID.'/actions/close')->assertNotFound();
        $this->page->closed = false;
        $this->page->refuse = '今は閉じられません。';
        $this->send('POST', '/api/tenants/apa/apps/fake-page/records/'.self::ID.'/actions/close')->assertStatus(409)->assertJsonPath('user_message', '今は閉じられません。');
    }

    public function test_values_outside_the_core_shapes_stop_as_a_development_error(): void
    {
        $this->inside('apa', function (): void {
            app(PluginStates::class)->set('fake-page', true, 'test');
            $pages = app(AdminPages::class);
            $bad = [
                'URL のリンク' => ['who' => ['label' => 'x', 'kind' => 'url', 'id' => self::ID]],
                'ID でないリンク' => ['who' => ['label' => 'x', 'kind' => 'customer', 'id' => 'javascript:alert(1)']],
                'リンクに余分な鍵' => ['who' => ['label' => 'x', 'kind' => 'customer', 'id' => self::ID, 'href' => 'https://evil.example']],
                '知らない色' => ['state' => ['label' => 'x', 'tone' => 'red']],
                '小数の金額' => ['amount' => 1.5],
                '文字の日付' => ['day' => '2026-11-02'],
                '長すぎる文' => ['name' => str_repeat('あ', CellType::TEXT_MAX + 1)],
                '壊れた文字' => ['name' => "\xff"],
            ];

            foreach ($bad as $what => $cells) {
                $this->page->cells = $cells;

                try {
                    $pages->list('fake-page', null, 1);
                    $this->fail("通ってしまった: {$what}");
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }
            }

            // 一覧の行は ID が要り、1 ページの上限を超えられない
            $this->page->cells = [];
            $this->page->rowId = null;
            $this->expectException(LogicException::class);
            $pages->list('fake-page', null, 1);
        });
    }

    public function test_actions_on_one_record_are_limited_per_hour(): void
    {
        $this->tenant('apb');

        foreach (['apa', 'apb'] as $shop) {
            $this->inside($shop, fn () => app(PluginStates::class)->set('fake-page', true, 'test'));
        }

        [$owner, $key] = $this->enrolledOperator();
        $this->reconfirm($owner, $key);
        $this->page->danger = false;
        $this->page->refuse = '今は閉じられません。';
        $path = '/api/tenants/apa/apps/fake-page/records/'.self::ID.'/actions/close';

        // 1 時間に 6 回まで App に渡す（断られた操作も数える）。7 回目は 429 で App を呼ばない
        for ($i = 0; $i < AdminPages::ACTIONS_PER_HOUR; $i++) {
            $this->send('POST', $path)->assertStatus(409);
        }

        $this->page->refuse = null;
        $this->send('POST', $path)->assertStatus(429)->assertJsonPath('user_message', 'この記録への操作が続いています。しばらくしてから、もう一度お試しください。');
        $this->assertSame(0, $this->page->calls());

        // 別の店の同じ ID の記録は、別に数える
        $this->send('POST', '/api/tenants/apb/apps/fake-page/records/'.self::ID.'/actions/close')->assertOk();
        $this->page->closed = false;

        // 取り消せない操作（直前の再確認つき）は数えず、締め出さない
        $this->page->danger = true;
        $this->send('POST', $path)->assertOk();
        $this->page->closed = false;
        $this->page->danger = false;

        // 1 時間たてば、また押せる
        $this->travel(61)->minutes();
        $this->send('POST', $path)->assertOk();
    }

    public function test_the_home_shows_app_todo_rows_with_a_count_and_one_broken_app_does_not_break_the_home(): void
    {
        $this->enrolledOperator();
        $this->page->attention = [new AdminAttention('closed', '閉じた記録', '見本の説明です。', 2, 'bad'), new AdminAttention('open', '空の行', '0 件なので出ない。', 0)];

        // 使っていない App の行は出ない
        $this->send('GET', '/api/tenants/apa/overview')->assertOk()->assertJsonPath('app_todo', []);

        $this->inside('apa', fn () => app(PluginStates::class)->set('fake-page', true, 'test'));
        $this->send('GET', '/api/tenants/apa/overview')->assertOk()->assertJsonPath('app_todo', [
            ['plugin' => 'fake-page', 'filter' => 'closed', 'label' => '閉じた記録', 'description' => '見本の説明です。', 'count' => 2, 'tone' => 'bad'],
        ]);

        // 一覧に無い絞り込み・知らない色の行を返す App は、その App の行だけを出さない（ホームは開ける）
        foreach ([new AdminAttention('other', 'x', 'x', 1), new AdminAttention('open', 'x', 'x', 1, 'red')] as $bad) {
            $this->page->attention = [$bad];
            $this->send('GET', '/api/tenants/apa/overview')->assertOk()->assertJsonPath('app_todo', []);
        }
    }

    public function test_personal_facts_are_only_for_the_operate_level_and_seeing_them_is_audited(): void
    {
        $this->inside('apa', fn () => app(PluginStates::class)->set('fake-page', true, 'test'));
        $this->page->personal = true;
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'apa', 'read', 'test');
        $path = '/api/tenants/apa/apps/fake-page/records/'.self::ID;
        $viewed = fn (): int => $this->viewedBy($staff->id);

        // 閲覧の段階: 個人情報の印の付いた事実と表は出さず、「隠した」と知らせる。見た記録も残らない
        $read = $this->send('GET', $path)->assertOk();
        $this->assertSame('hidden', $read->json('record.personal'));
        $this->assertSame(['金額'], array_column($read->json('record.facts'), 'label'));
        $this->assertSame([], $read->json('record.tables'));
        $this->assertStringNotContainsString('hanako@example.com', (string) $read->getContent());
        $this->assertStringNotContainsString('見本の本文', (string) $read->getContent());
        $this->assertSame(0, $viewed());

        // 運用の段階: 出して、見た事実を中央の監査ログに残す（誰が・どの店・どの App のどの記録。中身は残さない）
        $access->grant($staff, 'apa', 'operate', 'test');
        $shown = $this->send('GET', $path)->assertOk();
        $this->assertSame('shown', $shown->json('record.personal'));
        $this->assertSame(['金額', 'メール'], array_column($shown->json('record.facts'), 'label'));
        $this->assertSame('hanako@example.com', $shown->json('record.facts.1.value'));
        $this->assertSame('見本の本文', $shown->json('record.tables.0.rows.0.cells.text'));
        $audit = AuditEvent::query()->where('type', 'operator.personal_data_viewed')->where('operator_id', $staff->id)->sole();
        $this->assertSame([$staff->id, 'apa'], [$audit->operator_id, $audit->tenant_id]);
        // 中身の鍵の並びは DB（jsonb）が並べ替えるので、並びを見ずに比べる
        $this->assertEquals(['subject' => 'app_record', 'plugin' => 'fake-page', 'record_id' => strtolower(self::ID)], $audit->data);
        $this->assertStringNotContainsString('hanako', json_encode($audit->data, JSON_THROW_ON_ERROR));

        // 操作の後に返す 1 件も、見たことになる
        $this->page->danger = false;
        $this->send('POST', $path.'/actions/close')->assertOk()->assertJsonPath('record.personal', 'shown');
        $this->assertSame(2, $viewed());

        // 個人情報の印の無い App の 1 件は、どの段階でも印が null で記録も残らない
        $this->page->personal = false;
        $this->send('GET', $path)->assertOk()->assertJsonPath('record.personal', null);
        $this->assertSame(2, $this->viewedBy($staff->id));
    }

    /**
     * 個人情報を見た記録の数（監査ログは追記だけでテストの間も消えないので、この運営の分だけを数える）
     */
    private function viewedBy(string $operatorId): int
    {
        return AuditEvent::query()->where('type', 'operator.personal_data_viewed')->where('operator_id', $operatorId)->count();
    }

    public function test_column_and_action_names_are_checked(): void
    {
        $this->inside('apa', function (): void {
            app(PluginStates::class)->set('fake-page', true, 'test');
            $pages = app(AdminPages::class);

            $this->page->columnKey = 'Bad Key';
            $this->assertThrows(fn () => $pages->list('fake-page', null, 1), LogicException::class);
            $this->page->columnKey = 'name';

            $this->page->actionKey = 'close"; drop';
            $this->assertThrows(fn () => $pages->record('fake-page', self::ID), LogicException::class);

            $this->assertThrows(fn () => $pages->record('fake-page', 'not-an-id'), AdminRecordNotFound::class);
        });
    }
}

/**
 * テスト用の App の画面。値と答えをテストから変えられる
 */
final class FakeAdminPage implements AdminPageHandler
{
    /** @var array<string, mixed> */
    public array $cells = [];

    public ?string $rowId = '01JBQ0000000000000000000AA';

    public string $columnKey = 'name';

    public string $actionKey = 'close';

    public ?string $askedFilter = null;

    public ?int $askedPage = null;

    public bool $closed = false;

    public ?string $refuse = null;

    public bool $danger = true;

    /** お金を動かす操作の印 */
    public bool $money = false;

    /** 個人情報の印の付いた事実と表も返す */
    public bool $personal = false;

    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $acted = [];

    public function calls(): int
    {
        return count($this->acted);
    }

    public function title(): string
    {
        return '見本';
    }

    public function filters(): array
    {
        return [new AdminFilter('open', '開いている', 1), new AdminFilter('closed', '閉じた', 0)];
    }

    /** @var list<AdminAttention> */
    public array $attention = [];

    public function attention(): array
    {
        return $this->attention;
    }

    public function columns(): array
    {
        return [
            new AdminColumn($this->columnKey, '名前', CellType::Text),
            new AdminColumn('amount', '金額', CellType::Money),
            new AdminColumn('day', '日', CellType::Date),
            new AdminColumn('at', '日時', CellType::DateTime),
            new AdminColumn('state', '状態', CellType::Status),
            new AdminColumn('who', 'お客さん', CellType::Link),
            new AdminColumn('none', '空', CellType::Number),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $this->askedFilter = $filter;
        $this->askedPage = $page;

        return new AdminRows([new AdminRow($this->rowId, $this->cells + [
            'name' => '<b>見本</b>',
            'amount' => 5400,
            'day' => CarbonImmutable::parse('2026-11-02 12:00', 'Asia/Tokyo'),
            'at' => CarbonImmutable::parse('2026-11-02 03:00', 'UTC'),
            'state' => ['label' => '続いている', 'tone' => 'good'],
            'who' => ['label' => 'お客さん', 'kind' => 'customer', 'id' => '01JBQ0000000000000000000AA'],
            'extra' => 'これは列に無いので出ない',
        ])], 1);
    }

    public function record(string $id): ?AdminRecord
    {
        if ($id !== '01JBQ0000000000000000000AA') {
            return null;
        }

        $facts = [new AdminFact('金額', CellType::Money, 1200)];
        $tables = [];

        if ($this->personal) {
            $facts[] = new AdminFact('メール', CellType::Text, 'hanako@example.com', personal: true);
            $tables[] = new AdminTable('本文', [new AdminColumn('text', '本文', CellType::Text)], [new AdminRow(null, ['text' => '見本の本文'])], personal: true);
        }

        return new AdminRecord('見本の 1 件', $facts, $tables, $this->closed ? [] : [new AdminAction($this->actionKey, '閉じる', '閉じますか', danger: $this->danger, money: $this->money)]);
    }

    public function act(string $id, string $action, string $actor): void
    {
        if ($this->refuse !== null) {
            throw new AdminActionRefused($this->refuse);
        }

        $this->acted[] = [$id, $action, $actor];
        $this->closed = true;
    }
}
