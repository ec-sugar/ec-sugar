<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder;

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
use Carbon\CarbonImmutable;
use EcSugar\PluginCartReminder\Models\CartReminder;
use Illuminate\Database\Eloquent\Builder;

/**
 * 管理画面の「カゴ落ちのお知らせ」（ADR 0051 の App の画面。ADR 0067）。絞り込みの数が、直近 30 日の送った数・リンクから戻った数・そこから払われた注文の数。
 * 行は注文番号（核の注文の画面へのリンク）と状態と日時だけで、宛先のメールや名前は出さない（お客さんは注文の画面で、記録を残して見る）。操作は無い
 */
final class CartReminderAdminPage implements AdminPageHandler
{
    private const array FILTERS = [
        'sent' => '送った（30 日）',
        'returned' => 'リンクから戻った（30 日）',
        'recovered' => '支払われた（30 日）',
        'skipped' => '送らなかった（30 日）',
        'all' => 'すべて',
    ];

    private const array STATUS = [
        CartReminder::QUEUED => ['label' => '送信待ち', 'tone' => 'muted'],
        CartReminder::SENDING => ['label' => '送信中', 'tone' => 'muted'],
        CartReminder::SENT => ['label' => '送信済み', 'tone' => 'good'],
        CartReminder::FAILED => ['label' => '送信に失敗', 'tone' => 'bad'],
        CartReminder::SKIPPED => ['label' => '送らなかった', 'tone' => 'muted'],
    ];

    public function title(): string
    {
        return 'カゴ落ちのお知らせ';
    }

    public function filters(): array
    {
        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], self::filtered($key)->count()), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        $since = CarbonImmutable::now()->subDays(7);

        return [new AdminAttention('skipped', '送り主の表示が足りず送らなかったお知らせ', 'お知らせのメールには送り主の名前・住所・問い合わせ先を載せます（特定電子メール法）。設定の「特商法の表記」の「販売業者」「所在地」「電話番号かメールアドレス」を書くと、次からは送ります。',
            CartReminder::query()->where('status', CartReminder::SKIPPED)->where('reason', 'no_sender')->where('created_at', '>', $since)->count(), 'bad')];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('order', '支払われずに取り消された注文', CellType::Link),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('sent', '送った日時', CellType::DateTime),
            new AdminColumn('returned', 'リンクから戻った日時', CellType::DateTime),
            new AdminColumn('recovered', '支払われた注文', CellType::Link),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return new AdminRows($rows->map(static fn (CartReminder $r): AdminRow => new AdminRow($r->id, [
            'order' => self::orderLink($r->transaction_id),
            'status' => self::status($r),
            'sent' => $r->sent_at,
            'returned' => $r->returned_at,
            'recovered' => $r->recovered_transaction_id === null ? null : self::orderLink($r->recovered_transaction_id),
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $r = CartReminder::query()->find(strtolower($id));

        if ($r === null) {
            return null;
        }

        return new AdminRecord('カゴ落ちのお知らせ '.self::number($r->transaction_id), [
            new AdminFact('状態', CellType::Status, self::status($r)),
            new AdminFact('支払われずに取り消された注文', CellType::Link, ['label' => '注文の画面を開く', 'kind' => 'transaction', 'id' => $r->transaction_id]),
            new AdminFact('送らなかった理由', CellType::Text, $r->reason === null ? null : (CartReminder::REASONS[$r->reason] ?? null)),
            new AdminFact('記録を作った日時', CellType::DateTime, $r->created_at),
            new AdminFact('送った日時', CellType::DateTime, $r->sent_at),
            new AdminFact('「カートに戻す」のリンクの期限', CellType::DateTime, $r->status === CartReminder::SENT ? $r->expires_at : null),
            new AdminFact('リンクから戻った日時', CellType::DateTime, $r->returned_at),
            new AdminFact('支払われた注文', CellType::Link, $r->recovered_transaction_id === null ? null : ['label' => self::number($r->recovered_transaction_id), 'kind' => 'transaction', 'id' => $r->recovered_transaction_id]),
            new AdminFact('このメールから配信を停止した日時', CellType::DateTime, $r->unsubscribed_at),
        ]);
    }

    public function act(string $id, string $action, string $actor): void
    {
        throw new AdminRecordNotFound;
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function status(CartReminder $r): array
    {
        $status = self::STATUS[$r->status] ?? ['label' => $r->status, 'tone' => 'muted'];

        if ($r->status === CartReminder::SKIPPED && $r->reason !== null) {
            $status['label'] = mb_strimwidth('送らなかった（'.(CartReminder::REASONS[$r->reason] ?? $r->reason).'）', 0, CellType::TEXT_MAX);
        }

        return $status;
    }

    /**
     * @return array{label: string, kind: string, id: string}
     */
    private static function orderLink(string $transactionId): array
    {
        return ['label' => self::number($transactionId), 'kind' => 'transaction', 'id' => $transactionId];
    }

    /**
     * 注文番号（ショップと管理画面で見せる形。ULID の末尾 8 文字の大文字）
     */
    private static function number(string $transactionId): string
    {
        return strtoupper(substr($transactionId, -8));
    }

    /**
     * @return Builder<CartReminder>
     */
    private static function filtered(string $filter): Builder
    {
        $since = CarbonImmutable::now()->subDays(CartReminders::STATS_DAYS);
        $query = CartReminder::query();

        return match ($filter) {
            'sent' => $query->where('status', CartReminder::SENT)->where('sent_at', '>', $since),
            'returned' => $query->where('status', CartReminder::SENT)->where('sent_at', '>', $since)->whereNotNull('returned_at'),
            'recovered' => $query->where('status', CartReminder::SENT)->where('sent_at', '>', $since)->whereNotNull('recovered_transaction_id'),
            'skipped' => $query->where('status', CartReminder::SKIPPED)->where('created_at', '>', $since),
            default => $query,
        };
    }
}
