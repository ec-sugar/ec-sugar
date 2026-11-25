<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

use App\Core\Plugin\AdminPage\AdminAttention;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginLine\Models\LineLink;
use EcSugar\PluginLine\Models\LineMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * 管理画面の「LINE 連携」（ADR 0051 の App の画面。ADR 0070）。
 *
 * - 「つなぎ方と数」の 1 件: LINE の管理画面に写す URL（ログインの戻り先・Webhook）と、設定がそろっているか、
 *   つないだ会員の数・そのうち友だちの数・この 7 日に送った数と送れなかった数（数だけ。誰がつないだかは出さない）
 * - 送った知らせの一覧（種類・結果・日時・注文へのリンク）。宛先の LINE も本文も持っていないので出せない
 */
final class LineAdminPage implements AdminPageHandler
{
    /** 「つなぎ方と数」の 1 件の ID（行の ID は ULID の形と決まっているので、0 の ULID を使う） */
    public const string SUMMARY = '00000000000000000000000000';

    private const array KINDS = ['order_paid' => '注文を受けた', 'order_shipped' => '発送した'];

    private const array STATUSES = [
        'pending' => ['label' => '送信中', 'tone' => 'muted'],
        'sent' => ['label' => '送信済み', 'tone' => 'good'],
        'failed' => ['label' => '送信できなかった', 'tone' => 'bad'],
        'skipped' => ['label' => '上限のため送らなかった', 'tone' => 'warn'],
    ];

    /** 理由の言葉 => 店の人に見せる文 */
    private const array ERRORS = [
        'connection' => 'LINE に接続できませんでした',
        'unauthorized' => 'チャネルアクセストークンが違うか、無効になっています。入れ直してください',
        'forbidden' => 'LINE に拒否されました（チャネルの権限を確認してください）',
        'too_many' => 'LINE で送れる数の上限（月の上限など）を超えました',
        'limit' => 'このショップの送る数の上限を超えたので、送りませんでした',
        'error' => '送信中にエラーが起きました',
    ];

    public function title(): string
    {
        return 'LINE 連携';
    }

    public function filters(): array
    {
        return [
            new AdminFilter('setup', '設定と利用状況', 1),
            new AdminFilter('all', '送った通知', LineMessage::query()->count()),
            new AdminFilter('failed', '送信できなかった', LineMessage::query()->where('status', 'failed')->count()),
        ];
    }

    public function attention(): array
    {
        $failed = LineMessage::query()->where('status', 'failed')->where('created_at', '>=', CarbonImmutable::now()->subDay()->utc())->count();

        return [new AdminAttention('failed', 'LINE のお知らせを送れませんでした', '直近 24 時間に送信できなかったお知らせがあります。チャネルアクセストークンと、LINE 公式アカウントで送れる数の上限を確認してください', $failed)];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('kind', '通知', CellType::Text),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('created', '日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        if ($filter === 'setup') {
            return new AdminRows([new AdminRow(self::SUMMARY, [
                'kind' => 'LINE の管理画面に貼り付ける URL と、連携した会員の数',
                'status' => $this->ready(),
                'created' => null,
            ])], 1);
        }

        $query = LineMessage::query()->when($filter === 'failed', static fn (Builder $q) => $q->where('status', 'failed'));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return new AdminRows($rows->map(static fn (LineMessage $m): AdminRow => new AdminRow($m->id, [
            'kind' => self::KINDS[$m->kind] ?? $m->kind,
            'status' => self::STATUSES[$m->status] ?? self::STATUSES['pending'],
            'created' => $m->created_at,
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        if ($id === self::SUMMARY) {
            return $this->summary();
        }

        $message = LineMessage::query()->find(strtolower($id));

        if ($message === null) {
            return null;
        }

        $facts = [
            new AdminFact('通知', CellType::Text, self::KINDS[$message->kind] ?? $message->kind),
            new AdminFact('状態', CellType::Status, self::STATUSES[$message->status] ?? self::STATUSES['pending']),
            new AdminFact('送れなかった理由', CellType::Text, $message->error === null ? null : (self::ERRORS[$message->error] ?? "LINE に拒否されました（{$message->error}）")),
            new AdminFact('発生した日時', CellType::DateTime, $message->created_at),
            new AdminFact('送った日時', CellType::DateTime, $message->sent_at),
        ];

        if ($message->transaction_id !== null) {
            $facts[] = new AdminFact('注文', CellType::Link, ['label' => '注文を開く', 'kind' => 'transaction', 'id' => $message->transaction_id]);
        }

        return new AdminRecord('LINE の通知（'.(self::KINDS[$message->kind] ?? '').'）', $facts);
    }

    public function act(string $id, string $action, string $actor): void
    {
        throw new AdminRecordNotFound;
    }

    private function summary(): AdminRecord
    {
        $tenant = tenant();
        $since = CarbonImmutable::now()->subDays(7)->utc();
        $settings = app(LineSettings::class);

        return new AdminRecord('設定と利用状況', [
            new AdminFact('LINE ログインのコールバック URL', CellType::Text, LineLogin::callbackUrl(), copy: true),
            new AdminFact('Messaging API の Webhook URL', CellType::Text, $tenant instanceof Tenant ? StorefrontUrls::of($tenant, '/p/line/webhook') : null, copy: true),
            new AdminFact('LINE でログイン', CellType::Status, $settings->login() !== null ? ['label' => '使えます', 'tone' => 'good'] : ['label' => 'チャネル ID とシークレットが未設定', 'tone' => 'muted']),
            new AdminFact('LINE での通知', CellType::Status, $settings->accessToken() !== null ? ['label' => '送れます', 'tone' => 'good'] : ['label' => 'アクセストークンが未設定', 'tone' => 'muted']),
            new AdminFact('Messaging API のチャネルシークレット（Webhook の確認用）', CellType::Status, $settings->webhookSecret() !== null ? ['label' => '入っています', 'tone' => 'good'] : ['label' => '未設定', 'tone' => 'muted']),
            new AdminFact('LINE と連携した会員', CellType::Number, LineLink::query()->count()),
            new AdminFact('そのうち公式アカウントと友だち', CellType::Number, LineLink::query()->where('friend', true)->count()),
            new AdminFact('直近 7 日に送ったお知らせ', CellType::Number, LineMessage::query()->where('status', 'sent')->where('created_at', '>=', $since)->count()),
            new AdminFact('直近 7 日に送れなかったお知らせ', CellType::Number, LineMessage::query()->where('status', 'failed')->where('created_at', '>=', $since)->count()),
        ]);
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function ready(): array
    {
        $settings = app(LineSettings::class);

        return match (true) {
            $settings->login() !== null && $settings->accessToken() !== null => ['label' => '設定がそろっています', 'tone' => 'good'],
            $settings->login() !== null => ['label' => 'ログインだけ使えます', 'tone' => 'warn'],
            default => ['label' => '未設定', 'tone' => 'muted'],
        };
    }
}
