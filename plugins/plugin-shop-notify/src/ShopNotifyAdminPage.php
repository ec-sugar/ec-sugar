<?php

declare(strict_types=1);

namespace EcSugar\PluginShopNotify;

use App\Core\Money\Money;
use App\Core\Plugin\AdminPage\AdminAttention;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminField;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\AdminPage\FieldType;
use App\Core\Plugin\Contracts\AdminFormHandler;
use App\Core\Plugin\Contracts\AdminPageHandler;
use Carbon\CarbonImmutable;
use EcSugar\PluginShopNotify\Jobs\SendShopNotice;
use EcSugar\PluginShopNotify\Models\ShopNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 管理画面の「店への知らせ」（ADR 0051 の App の画面）。送った知らせの一覧（何の知らせ・宛先の種類・送れたか・いつ）と 1 件の様子。
 * 宛先そのもの（Slack の URL・メールアドレス）は出さない。足す口（AdminFormHandler）は「試しに 1 通送る」で、記録の行を作ってキューで送る。
 * 試しは店ごとに 10 分に 5 回まで（外のサービスへの送りすぎを防ぐ）
 */
final class ShopNotifyAdminPage implements AdminFormHandler, AdminPageHandler
{
    public const int TEST_LIMIT = 5;

    private const array FILTERS = ['all' => 'すべて', 'failed' => '送信できなかった'];

    private const array KINDS = ['paid' => '注文が入った', 'cancelled' => '注文の取消', 'low_stock' => '在庫が少ない', 'test' => 'テスト'];

    private const array CHANNELS = ['slack' => 'Slack', 'mail' => 'メール'];

    private const array STATUSES = [
        'pending' => ['label' => '送信中', 'tone' => 'muted'],
        'sent' => ['label' => '送信済み', 'tone' => 'good'],
        'failed' => ['label' => '送信できなかった', 'tone' => 'bad'],
        'skipped' => ['label' => '送らなかった', 'tone' => 'muted'],
    ];

    /** 誤りの言葉 => 店の人に見せる文 */
    private const array ERRORS = [
        'connection' => 'Slack につながりませんでした（時間をおいて送り直します）',
        'bad_destination' => '通知先の URL が正しくありません（Slack の URL を入れ直してください）',
        'not_configured' => '通知先が入っていません',
        'not_used' => 'App を止めていたか、このお知らせをオフにしていたため、送りませんでした',
        'mail' => 'メールを送れませんでした（時間をおいて送り直します）',
        'http_404' => 'Slack の URL が見つかりません（Webhook が削除されたか、作り直されています）。URL を入れ直してください',
        'http_403' => 'Slack に拒否されました（Webhook が無効になっています）。URL を入れ直してください',
        'http_410' => 'Slack の URL が使えなくなりました。URL を入れ直してください',
    ];

    public function title(): string
    {
        return '店への通知';
    }

    public function filters(): array
    {
        return [
            new AdminFilter('all', self::FILTERS['all'], ShopNotice::query()->count()),
            new AdminFilter('failed', self::FILTERS['failed'], ShopNotice::query()->where('status', 'failed')->count()),
        ];
    }

    public function attention(): array
    {
        $failed = ShopNotice::query()->where('status', 'failed')->where('created_at', '>=', CarbonImmutable::now()->subDay()->utc())->count();

        return [new AdminAttention('failed', '店への知らせを送れませんでした', '直近 24 時間に送信できなかったお知らせがあります。Slack の URL とメールアドレスを確認してください', $failed)];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('kind', '通知', CellType::Text),
            new AdminColumn('channel', '通知先', CellType::Text),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('created', '発生した日時', CellType::DateTime),
            new AdminColumn('sent', '送った日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = ShopNotice::query()->when($filter === 'failed', static fn (Builder $q) => $q->where('status', 'failed'));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return new AdminRows($rows->map(static fn (ShopNotice $n): AdminRow => new AdminRow($n->id, [
            'kind' => self::KINDS[$n->kind] ?? $n->kind,
            'channel' => self::CHANNELS[$n->channel] ?? $n->channel,
            'status' => self::STATUSES[$n->status] ?? self::STATUSES['pending'],
            'created' => $n->created_at,
            'sent' => $n->sent_at,
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $notice = ShopNotice::query()->find(strtolower($id));

        if ($notice === null) {
            return null;
        }

        $facts = $notice->facts;
        $out = [
            new AdminFact('通知', CellType::Text, self::KINDS[$notice->kind] ?? $notice->kind),
            new AdminFact('通知先', CellType::Text, self::CHANNELS[$notice->channel] ?? $notice->channel),
            new AdminFact('状態', CellType::Status, self::STATUSES[$notice->status] ?? self::STATUSES['pending']),
            new AdminFact('送れなかった理由', CellType::Text, $notice->error === null ? null : (self::ERRORS[$notice->error] ?? "通知先に拒否されました（{$notice->error}）")),
            new AdminFact('送ろうとした回数', CellType::Number, $notice->attempts),
            new AdminFact('発生した日時', CellType::DateTime, $notice->created_at),
            new AdminFact('送った日時', CellType::DateTime, $notice->sent_at),
        ];

        if (in_array($notice->kind, ['paid', 'cancelled'], true)) {
            $out[] = new AdminFact('注文番号', CellType::Text, (string) ($facts['number'] ?? ''));
            $out[] = new AdminFact('注文', CellType::Link, ['label' => '注文を開く', 'kind' => 'transaction', 'id' => (string) ($facts['transaction_id'] ?? '')]);
            $out[] = new AdminFact('金額', CellType::Text, Money::format((int) ($facts['amount'] ?? 0)).'（'.(int) ($facts['items'] ?? 0).' 点）');
        }

        if ($notice->kind === 'low_stock') {
            $out[] = new AdminFact('商品', CellType::Link, ['label' => self::cut((string) ($facts['title'] ?? '商品')), 'kind' => 'listing', 'id' => (string) ($facts['listing_id'] ?? '')]);
            $out[] = new AdminFact('注文できる在庫（知らせた時）', CellType::Number, (int) ($facts['available'] ?? 0));
        }

        return new AdminRecord((self::KINDS[$notice->kind] ?? '通知').'（'.(self::CHANNELS[$notice->channel] ?? '').'）', $out);
    }

    public function act(string $id, string $action, string $actor): void
    {
        throw new AdminRecordNotFound;
    }

    public function createLabel(): string
    {
        return 'テストで 1 通送る';
    }

    public function fields(): array
    {
        return [
            new AdminField('channel', FieldType::Choice, '通知先', '設定した通知先へ、テストの通知を 1 通送ります（注文の内容は入りません）', true, options: ['all' => '設定にある全部', 'slack' => 'Slack だけ', 'mail' => 'メールだけ'], default: 'all'),
        ];
    }

    public function values(string $id): ?array
    {
        return null;
    }

    public function create(array $values, string $actor): string
    {
        $channels = app(ShopNotifySettings::class)->channels();
        $chosen = (string) ($values['channel'] ?? 'all');
        $only = $chosen === 'all' ? array_keys($channels) : [$chosen];

        if ($channels === []) {
            throw ValidationException::withMessages(['values.channel' => '先に App の設定で、Slack の URL か通知を受けるメールアドレスを入れてください。']);
        }

        if (array_diff($only, array_keys($channels)) !== []) {
            throw ValidationException::withMessages(['values.channel' => $chosen === 'slack' ? 'Slack の URL がまだ入っていません。' : '通知を受けるメールアドレスがまだ入っていません。']);
        }

        if (ShopNotice::query()->where('kind', 'test')->where('created_at', '>=', CarbonImmutable::now()->subMinutes(10)->utc())->distinct()->count('dedupe_key') >= self::TEST_LIMIT) {
            throw ValidationException::withMessages(['values.channel' => 'テストのお知らせは 10 分に '.self::TEST_LIMIT.' 回までです。少し待ってからお試しください。']);
        }

        $key = strtolower((string) Str::ulid());
        $facts = app(ShopNotices::class)->facts('test', null) ?? [];
        $first = null;

        foreach ($only as $channel) {
            $row = ShopNotice::query()->create(['kind' => 'test', 'dedupe_key' => $key, 'channel' => $channel, 'facts' => $facts, 'status' => 'pending']);
            $first ??= $row->id;
        }

        // 送るのはキューで（送れなくても、この操作は断らない。結果は一覧の状態で見る）
        try {
            SendShopNotice::dispatch('test', null, $key, $only);
        } catch (Throwable $error) {
            report($error);
        }

        return (string) $first;
    }

    public function update(string $id, array $values, string $actor): void
    {
        throw new AdminRecordNotFound;
    }

    private static function cut(string $text): string
    {
        return mb_strlen($text) > CellType::TEXT_MAX ? mb_substr($text, 0, CellType::TEXT_MAX - 1).'…' : $text;
    }
}
