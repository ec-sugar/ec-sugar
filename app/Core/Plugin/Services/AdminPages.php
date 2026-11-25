<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminActionThrottled;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\Contracts\AdminActionInputHandler;
use App\Core\Plugin\Contracts\AdminLookupHandler;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\RateLimit\Throttle;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonInterface;
use LogicException;
use Throwable;

/**
 * 管理画面の App の画面（ADR 0051）。店の文脈の中だけで使う。
 * 出すのは、この店で使っている App のうち画面の係（AdminPageHandler）を持つものだけ。使っていない・画面の無い App・無い記録は同じ 404。
 *
 * 核が受け持つのは、App が渡した中身の形の確かめ（値は CellType の形だけ。HTML・任意の URL は通さない）と、
 * 操作を「今の 1 件の画面が出している操作」に限ること。状態の変更（ロック・イベント）は係が受け持つ。
 * 個人情報の印（personal）の付いた事実と表は、見てよい人（$personal が真。運用の段階から。決めるのは管理画面の受け口）にだけ出す。
 * 1 件の答えの personal は、印の付いたものが無ければ null・出したら shown・隠したら hidden（受け口は shown のとき見た事実を監査ログに残す）
 */
final class AdminPages
{
    public const int PER_PAGE = 50;

    private const string ZONE = 'Asia/Tokyo';

    private const int MAX_COLUMNS = 12;

    private const int MAX_FILTERS = 10;

    private const int MAX_FACTS = 30;

    private const int MAX_TABLES = 5;

    private const int MAX_TABLE_ROWS = 200;

    private const int MAX_ACTIONS = 6;

    private const int MAX_ATTENTION = 3;

    public const int ACTIONS_PER_HOUR = 6;

    public const int LOOKUPS_PER_10_MIN = 300;

    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginStates $states,
    ) {}

    /**
     * メニューに出す App の画面（App の名前の順）
     *
     * @return list<array{plugin: string, title: string}>
     */
    public function menu(): array
    {
        $out = [];

        foreach ($this->registry->all() as $plugin) {
            if ($plugin->adminPage !== null && $this->states->enabled($plugin->name)) {
                $out[] = ['plugin' => $plugin->name, 'title' => self::text($plugin, $plugin->adminPage->title(), '画面の名前')];
            }
        }

        return $out;
    }

    /**
     * ホームの「やること」に出す App の行（件数が 1 以上のものだけ）。1 つの App が落ちても、ホームとほかの App の行は出す（落ちたことは報告する）
     *
     * @return list<array{plugin: string, filter: string, label: string, description: string, count: int, tone: string}>
     */
    public function attention(): array
    {
        $out = [];

        foreach ($this->registry->all() as $plugin) {
            if ($plugin->adminPage === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            try {
                $out = [...$out, ...$this->attentionOf($plugin, $plugin->adminPage)];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $out;
    }

    /**
     * 一覧の 1 ページ。$filter が null なら先頭の絞り込み、知らない絞り込みは 404
     *
     * @return array{plugin: string, title: string, filter: string, filters: list<array{key: string, label: string, count: int}>, columns: list<array{key: string, label: string, type: string}>, rows: list<array{id: ?string, cells: array<string, mixed>}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function list(string $plugin, ?string $filter, int $page): array
    {
        $definition = $this->definition($plugin);
        /** @var AdminPageHandler $handler */
        $handler = $definition->adminPage;
        $filters = $this->filters($definition, $handler->filters());
        $filter ??= array_key_first($filters);

        if (! isset($filters[$filter])) {
            throw new AdminRecordNotFound;
        }

        $columns = $this->columns($definition, $handler->columns(), self::MAX_COLUMNS);
        $result = $handler->rows($filter, $page, self::PER_PAGE);

        if (count($result->rows) > self::PER_PAGE || $result->total < 0) {
            throw new LogicException("App {$definition->name}: 一覧の行は 1 ページ ".self::PER_PAGE.' 行まで');
        }

        return [
            'plugin' => $definition->name,
            'title' => self::text($definition, $handler->title(), '画面の名前'),
            'filter' => $filter,
            'filters' => array_values($filters),
            'columns' => self::columnsOut($columns),
            'rows' => $this->rows($definition, $columns, $result->rows, true),
            'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $result->total],
        ];
    }

    /**
     * 1 件の様子。$personal が偽なら、個人情報の印の付いた事実と表を出さない
     *
     * @return array{plugin: string, page_title: string, id: string, title: string, facts: list<array{label: string, type: string, value: mixed, copy?: true}>, tables: list<array{title: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array{id: ?string, cells: array<string, mixed>}>}>, actions: list<array{key: string, label: string, confirm: string, danger: bool, repeatable: bool, money: bool}>, personal: 'shown'|'hidden'|null}
     */
    public function record(string $plugin, string $id, bool $personal = false): array
    {
        $definition = $this->definition($plugin);
        /** @var AdminPageHandler $handler */
        $handler = $definition->adminPage;
        $record = self::isUlid($id) ? $handler->record($id) : null;

        if ($record === null) {
            throw new AdminRecordNotFound;
        }

        if (count($record->facts) > self::MAX_FACTS || count($record->tables) > self::MAX_TABLES) {
            throw new LogicException("App {$definition->name}: 1 件の画面の事実は ".self::MAX_FACTS.' 個・表は '.self::MAX_TABLES.' つまで');
        }

        $facts = [];
        // 個人情報の印の付いたものがあったか（出す・隠すの答えに使う）
        $marked = false;
        $prominent = 0;

        foreach ($record->facts as $fact) {
            $value = self::cell($definition, $fact->type, $fact->value);
            $marked = $marked || $fact->personal;

            // 見てよくない人には、印の付いた事実を形を確かめた後で外す（作り間違いは段階にかかわらず止める）
            if ($fact->personal && ! $personal) {
                continue;
            }

            if ($fact->prominent && ($fact->type !== CellType::Text || ++$prominent > 2)) {
                throw new LogicException("App {$definition->name}: 大きく出す事実は文の形で 1 件に 2 つまで");
            }

            // 写すボタンは文の値があるときだけ（印の無い事実は今までの形のまま）。大きく出す印も付いたときだけ
            $facts[] = ['label' => self::text($definition, $fact->label, '事実の名前'), 'type' => $fact->type->value, 'value' => $value]
                + ($fact->copy && $fact->type === CellType::Text && $value !== null ? ['copy' => true] : [])
                + ($fact->prominent ? ['prominent' => true] : []);
        }

        $tables = [];

        foreach ($record->tables as $table) {
            if (count($table->rows) > self::MAX_TABLE_ROWS) {
                throw new LogicException("App {$definition->name}: 表の行は ".self::MAX_TABLE_ROWS.' 行まで');
            }

            $columns = $this->columns($definition, $table->columns, self::MAX_COLUMNS);
            $out = ['title' => self::text($definition, $table->title, '表の名前'), 'columns' => self::columnsOut($columns), 'rows' => $this->rows($definition, $columns, $table->rows, false)];
            $marked = $marked || $table->personal;

            if (! $table->personal || $personal) {
                $tables[] = $out;
            }
        }

        return [
            'plugin' => $definition->name,
            'page_title' => self::text($definition, $handler->title(), '画面の名前'),
            'id' => $id,
            'title' => self::text($definition, $record->title, '見出し'),
            'facts' => $facts,
            'tables' => $tables,
            // 欄のある操作は、押す前に出す欄（足す・直すの欄と同じ形）も渡す。欄の無い操作は今までの形のまま
            'actions' => array_values(array_map(static fn (AdminAction $a): array => ['key' => $a->key, 'label' => $a->label, 'confirm' => $a->confirm, 'danger' => $a->danger, 'repeatable' => $a->repeatable]
                + ($a->fields === [] ? [] : ['form' => app(AdminForms::class)->blankOf($definition, $a->label, $a->fields)])
                + ['money' => $a->money], $this->actions($definition, $record->actions))),
            'personal' => $marked ? ($personal ? 'shown' : 'hidden') : null,
        ];
    }

    /**
     * 操作を行い、行った後の 1 件を返す。今の 1 件の画面が出していない操作は 404（状態が変わった後の古い画面からの押し直しも含む）。
     * 取り消せない操作（danger）は、App に渡す前に $beforeDanger を呼ぶ（管理画面の直前の再確認）。
     * 操作で記録が無くなった（「消す」）ときは null。$personal は record と同じ（行った後の 1 件に個人情報を出すか）。
     * お金に触れる操作（money）は、App に渡す前に $beforeMoney を呼ぶ（「お金」の段階と直前の再確認）。渡されなければ行わない（同じ 404）
     *
     * 欄のある操作は、$input（欄の名前 => 値）を足す・直すの欄と同じ決まりで確かめてから係の actWith に渡す
     *
     * @param  callable(): void  $beforeDanger
     * @param  array<mixed>  $input
     * @param  (callable(): void)|null  $beforeMoney
     * @return array<string, mixed>|null
     */
    public function act(string $plugin, string $id, string $action, string $actor, callable $beforeDanger, bool $personal = false, array $input = [], ?callable $beforeMoney = null): ?array
    {
        $definition = $this->definition($plugin);
        /** @var AdminPageHandler $handler */
        $handler = $definition->adminPage;
        $record = self::isUlid($id) ? $handler->record($id) : null;

        $offered = $record === null ? null : ($this->actions($definition, $record->actions)[$action] ?? null);

        if ($offered === null) {
            throw new AdminRecordNotFound;
        }

        // 欄の値は再確認より先に確かめる（入れ直しのたびに再確認をさせない）
        $values = $offered->fields === [] ? [] : AdminForms::forward(fn (): array => app(AdminForms::class)->valuesOf($definition, $offered->fields, $input));
        $inputHandler = match (true) {
            $offered->fields === [] => null,
            $handler instanceof AdminActionInputHandler => $handler,
            default => throw new LogicException("App {$definition->name}: 欄のある操作は AdminActionInputHandler を実装した係だけ"),
        };

        if ($offered->money) {
            if ($beforeMoney === null) {
                throw new AdminRecordNotFound;
            }

            $beforeMoney();
        }

        if ($offered->danger) {
            $beforeDanger();
        }

        // 同じ記録への操作は 1 時間に ACTIONS_PER_HOUR 回まで（店の人のくり返しで、お客さんへの知らせを重ねない）。数えるのは App に渡す操作だけ。
        // 取り消せない操作（解約など。直前の再確認つき・一度きり）は数えず、締め出さない。数えるのは足した後の数で（同時に押しても上限を超えない）。
        // くり返すのがふつうの操作（並べ替え。repeatable）も数えない
        if (! $offered->danger && ! $offered->repeatable) {
            $key = 'admin-page-action:'.(string) tenant()?->getTenantKey().':'.$plugin.':'.strtolower($id);

            if (! Throttle::allow($key, self::ACTIONS_PER_HOUR, 3600)) {
                throw new AdminActionThrottled;
            }
        }

        if ($inputHandler !== null) {
            AdminForms::forward(static fn () => $inputHandler->actWith($id, $action, $values, $actor));
        } else {
            $handler->act($id, $action, $actor);
        }

        return $handler->record($id) === null ? null : $this->record($plugin, $id, $personal);
    }

    /**
     * 一覧の上の「読む・探す」の欄（係が AdminLookupHandler も実装したときだけ。無ければ null）
     *
     * @return array{label: string, hint: string, scan: bool}|null
     */
    public function lookupForm(string $plugin): ?array
    {
        $definition = $this->definition($plugin);
        $handler = $definition->adminPage;

        if (! $handler instanceof AdminLookupHandler) {
            return null;
        }

        return ['label' => self::text($definition, $handler->lookupLabel(), '読む欄の名前'), 'hint' => self::text($definition, $handler->lookupHint(), '読む欄の案内'), 'scan' => $handler->lookupScan()];
    }

    /**
     * 読む・探す: 店の人が入れた文（QR の中身・番号など）から 1 件の ID。欄の無い App は 404、見つからない・使えないは App の断り（409）。
     * 回数は店 × App × 人ごとに 10 分で LOOKUPS_PER_10_MIN 回まで（番号の当てずっぽうを抑える。受付の列が続いても足りる数）
     */
    public function lookup(string $plugin, string $input, string $actor): string
    {
        $definition = $this->definition($plugin);
        $handler = $definition->adminPage;

        if (! $handler instanceof AdminLookupHandler) {
            throw new AdminRecordNotFound;
        }

        $input = trim($input);

        if ($input === '' || ! mb_check_encoding($input, 'UTF-8') || mb_strlen($input) > AdminLookupHandler::INPUT_MAX || preg_match('/\p{C}/u', $input) === 1) {
            throw new AdminActionRefused('読み取れない文字です。もう一度読み取るか、番号を入力してください。');
        }

        if (! Throttle::allow('admin-page-lookup:'.(string) tenant()?->getTenantKey().':'.$plugin.':'.$actor, self::LOOKUPS_PER_10_MIN, 600)) {
            throw new AdminActionThrottled;
        }

        $id = $handler->lookup($input, $actor);

        if (! self::isUlid($id)) {
            throw new LogicException("App {$definition->name}: 読んだ結果は 1 件の ID（ULID）");
        }

        return strtolower($id);
    }

    /**
     * 注文の画面に出す App の 1 件（AdminOrderPanelProvider を持つ使っている App だけ）。形は record と同じで、個人情報は出さず、操作も出さない
     * （操作は App の画面の 1 件で）。App の誤りは注文の画面を止めずに知らせる
     *
     * @return list<array{plugin: string, page_title: string, id: string, title: string, facts: list<array<string, mixed>>, tables: list<array<string, mixed>>}>
     */
    public function forOrder(Transaction $order): array
    {
        $panels = [];

        foreach ($this->registry->all() as $plugin) {
            if ($plugin->adminOrderPanel === null || $plugin->adminPage === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            try {
                $id = $plugin->adminOrderPanel->recordForOrder($order);

                if ($id !== null) {
                    $record = $this->record($plugin->name, $id);
                    $panels[] = ['plugin' => $record['plugin'], 'page_title' => $record['page_title'], 'id' => $record['id'], 'title' => $record['title'],
                        'facts' => $record['facts'], 'tables' => $record['tables']];
                }
            } catch (AdminRecordNotFound) {
                continue;
            } catch (Throwable $error) {
                report($error);
            }
        }

        return $panels;
    }

    /**
     * 使っている App で、画面の係を持つものだけ（ほかは同じ答えの 404）
     */
    public function definition(string $plugin): PluginDefinition
    {
        if (! $this->registry->has($plugin)) {
            throw new AdminRecordNotFound;
        }

        $definition = $this->registry->get($plugin);

        if ($definition->adminPage === null || ! $this->states->enabled($plugin)) {
            throw new AdminRecordNotFound;
        }

        return $definition;
    }

    /**
     * @return list<array{plugin: string, filter: string, label: string, description: string, count: int, tone: string}>
     */
    private function attentionOf(PluginDefinition $plugin, AdminPageHandler $handler): array
    {
        $items = $handler->attention();

        if (count($items) > self::MAX_ATTENTION) {
            throw new LogicException("App {$plugin->name}: やることは ".self::MAX_ATTENTION.' つまで');
        }

        $filters = $this->filters($plugin, $handler->filters());
        $out = [];

        foreach ($items as $item) {
            if (! isset($filters[$item->filter]) || ! in_array($item->tone, CellType::TONES, true)) {
                throw new LogicException("App {$plugin->name}: やることの絞り込みは一覧の絞り込みのどれかで、色は決まった種類");
            }

            if ($item->count > 0) {
                $out[] = ['plugin' => $plugin->name, 'filter' => $item->filter, 'label' => self::text($plugin, $item->label, 'やることの名前'),
                    'description' => self::text($plugin, $item->description, 'やることの説明'), 'count' => $item->count, 'tone' => $item->tone];
            }
        }

        return $out;
    }

    /**
     * @param  list<AdminFilter>  $filters
     * @return array<string, array{key: string, label: string, count: int}>
     */
    private function filters(PluginDefinition $plugin, array $filters): array
    {
        if ($filters === [] || count($filters) > self::MAX_FILTERS) {
            throw new LogicException("App {$plugin->name}: 絞り込みは 1〜".self::MAX_FILTERS.' 個');
        }

        $out = [];

        foreach ($filters as $filter) {
            self::key($plugin, $filter->key, isset($out[$filter->key]));
            $out[$filter->key] = ['key' => $filter->key, 'label' => self::text($plugin, $filter->label, '絞り込みの名前'), 'count' => max(0, $filter->count)];
        }

        return $out;
    }

    /**
     * @param  list<AdminColumn>  $columns
     * @return array<string, AdminColumn>
     */
    private function columns(PluginDefinition $plugin, array $columns, int $max): array
    {
        if ($columns === [] || count($columns) > $max) {
            throw new LogicException("App {$plugin->name}: 列は 1〜{$max} 個");
        }

        $out = [];

        foreach ($columns as $column) {
            self::key($plugin, $column->key, isset($out[$column->key]));
            self::text($plugin, $column->label, '列の名前');
            $out[$column->key] = $column;
        }

        return $out;
    }

    /**
     * @param  array<string, AdminColumn>  $columns
     * @return list<array{key: string, label: string, type: string}>
     */
    private static function columnsOut(array $columns): array
    {
        return array_values(array_map(static fn (AdminColumn $c): array => ['key' => $c->key, 'label' => $c->label, 'type' => $c->type->value], $columns));
    }

    /**
     * 行の値を列の形で確かめる。列に無い値は出さず、書かなかった列は null。一覧の行は 1 件の画面へ進む ID が要る
     *
     * @param  array<string, AdminColumn>  $columns
     * @param  list<AdminRow>  $rows
     * @return list<array{id: ?string, cells: array<string, mixed>}>
     */
    private function rows(PluginDefinition $plugin, array $columns, array $rows, bool $needsId): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (($needsId || $row->id !== null) && ($row->id === null || ! self::isUlid($row->id))) {
                throw new LogicException("App {$plugin->name}: 行の ID は ULID");
            }

            $cells = [];

            foreach ($columns as $key => $column) {
                $cells[$key] = self::cell($plugin, $column->type, $row->cells[$key] ?? null);
            }

            $out[] = ['id' => $row->id, 'cells' => $cells];
        }

        return $out;
    }

    /**
     * @param  list<AdminAction>  $actions
     * @return array<string, AdminAction>
     */
    private function actions(PluginDefinition $plugin, array $actions): array
    {
        if (count($actions) > self::MAX_ACTIONS) {
            throw new LogicException("App {$plugin->name}: 操作は ".self::MAX_ACTIONS.' 個まで');
        }

        $out = [];

        foreach ($actions as $action) {
            self::key($plugin, $action->key, isset($out[$action->key]));
            self::text($plugin, $action->label, '操作の名前');
            self::text($plugin, $action->confirm, '確認の文');
            $out[$action->key] = $action;
        }

        return $out;
    }

    /**
     * 1 つの値を形に合わせて出す（合わなければ App の作り間違いとして止める）
     */
    private static function cell(PluginDefinition $plugin, CellType $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $out = match ($type) {
            CellType::Text => is_string($value) && self::fits($value) ? $value : null,
            CellType::Number, CellType::Money => is_int($value) ? $value : null,
            // 日付は店の時刻の帯（日本）の日付で。DB の日付の列は UTC の 0 時なので、直しても日は変わらない
            CellType::Date => $value instanceof CarbonInterface ? $value->copy()->setTimezone(self::ZONE)->format('Y-m-d') : null,
            CellType::DateTime => $value instanceof CarbonInterface ? $value->toIso8601String() : null,
            CellType::Status => is_array($value) && count($value) === 2 && is_string($value['label'] ?? null) && self::fits($value['label'])
                && in_array($value['tone'] ?? null, CellType::TONES, true) ? ['label' => $value['label'], 'tone' => $value['tone']] : null,
            CellType::Link => is_array($value) && count($value) === 3 && is_string($value['label'] ?? null) && self::fits($value['label'])
                && in_array($value['kind'] ?? null, CellType::LINK_KINDS, true) && is_string($value['id'] ?? null) && self::isUlid($value['id'])
                ? ['label' => $value['label'], 'kind' => $value['kind'], 'id' => $value['id']] : null,
        };

        if ($out === null) {
            throw new LogicException("App {$plugin->name}: {$type->value} の形に合わない値");
        }

        return $out;
    }

    private static function key(PluginDefinition $plugin, string $key, bool $taken): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $key) !== 1 || $taken) {
            throw new LogicException("App {$plugin->name}: 名前 {$key} は英小文字・数字・_ の 32 文字までで、重ならないこと");
        }
    }

    private static function text(PluginDefinition $plugin, string $text, string $what): string
    {
        if ($text === '' || ! self::fits($text)) {
            throw new LogicException("App {$plugin->name}: {$what}は 1〜".CellType::TEXT_MAX.' 文字');
        }

        return $text;
    }

    private static function fits(string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8') && mb_strlen($text) <= CellType::TEXT_MAX;
    }

    private static function isUlid(string $id): bool
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\z/', $id) === 1;
    }
}
