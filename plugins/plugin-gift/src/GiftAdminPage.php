<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

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
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * 管理画面の「贈り物」（ADR 0051 の App の画面。ADR 0055 の 9）。受け取り待ち・受け取り済み・返金が止まった・取り消しの一覧と 1 件。
 * 差出人・メッセージ・受け取る人のお届け先は出さない（お届け先は注文の画面の「個人情報」で、記録を残して見る）。
 * 取り消し（全額の返金）は、お金の権限と直前の再確認がある注文の画面の「注文を取り消す」で行う（この画面に返金の操作は置かない。
 * App の画面の操作は運用の段階から押せるため、お金を動かす操作を置くと権限を飛び越える）。返金できず止まった贈り物は、ホームの「やること」に出す
 */
final class GiftAdminPage implements AdminPageHandler
{
    private const array FILTERS = ['waiting' => '受け取り待ち', 'claimed' => '受け取り済み', 'stopped' => '返金できなかった', 'closed' => '取り消し・期限切れ', 'all' => 'すべて'];

    private const array STATUS = [
        'preparing' => ['label' => 'お支払いの確認待ち', 'tone' => 'muted'],
        'waiting' => ['label' => '受け取り待ち', 'tone' => 'warn'],
        'claimed' => ['label' => '受け取り済み（発送できます）', 'tone' => 'good'],
        'shipped' => ['label' => '発送済み', 'tone' => 'good'],
        'cancelling' => ['label' => '取り消しの処理中', 'tone' => 'warn'],
        'stopped' => ['label' => '返金できなかった（要確認）', 'tone' => 'bad'],
        'cancelled' => ['label' => '取り消し', 'tone' => 'muted'],
        'expired' => ['label' => '期限切れで返金', 'tone' => 'muted'],
    ];

    public function title(): string
    {
        return '贈り物';
    }

    public function filters(): array
    {
        return array_map(fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], self::filtered($key)->count()), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [new AdminAttention('stopped', '返金できなかった贈り物', '期限切れや取り消しになった贈り物の返金ができず、処理を止めています。注文の画面で返金の記録を確認し、もう一度「注文を取り消す」を押してください。',
            GiftOrder::query()->whereIn('status', ['stopped', 'cancelling'])->count(), 'bad')];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('order', '注文', CellType::Link),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('expires', '受け取りの期限', CellType::Date),
            new AdminColumn('placed', '注文の日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $gifts = $query->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderByDesc('created_at')->forPage($page, $perPage)->get();

        return new AdminRows($gifts->map(static fn (GiftOrder $gift): AdminRow => new AdminRow($gift->id, [
            'order' => ['label' => strtoupper(substr($gift->transaction_id, -8)), 'kind' => 'transaction', 'id' => $gift->transaction_id],
            'status' => self::STATUS[GiftStatus::of($gift)],
            'expires' => $gift->expires_at,
            'placed' => $gift->getAttribute('created_at'),
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $gift = GiftOrder::query()->find($id);

        if ($gift === null) {
            return null;
        }

        $status = GiftStatus::of($gift);

        return new AdminRecord('贈り物 '.strtoupper(substr($gift->transaction_id, -8)), [
            new AdminFact('状態', CellType::Status, self::STATUS[$status]),
            new AdminFact('注文', CellType::Link, ['label' => '注文の画面を開く', 'kind' => 'transaction', 'id' => $gift->transaction_id]),
            new AdminFact('受け取りの期限', CellType::Date, $gift->expires_at),
            new AdminFact('注文の日時', CellType::DateTime, $gift->getAttribute('created_at')),
            new AdminFact('受け取った日時', CellType::DateTime, $gift->claimed_at),
            new AdminFact('取り消した日時', CellType::DateTime, $gift->closed_at),
            new AdminFact('取り消し・返金', CellType::Text, in_array($status, ['waiting', 'claimed', 'stopped', 'cancelling'], true)
                ? '注文の画面の「注文を取り消す」で全額を返金します（受け取りのリンクも使えなくなります）。'
                : null),
        ]);
    }

    public function act(string $id, string $action, string $actor): void
    {
        throw new AdminRecordNotFound;
    }

    /**
     * @return Builder<GiftOrder>
     */
    private static function filtered(string $filter): Builder
    {
        $query = GiftOrder::query();

        return match ($filter) {
            'waiting' => $query->whereIn('status', ['preparing', 'waiting']),
            'claimed' => $query->where('status', 'claimed'),
            'stopped' => $query->whereIn('status', ['stopped', 'cancelling']),
            'closed' => $query->whereIn('status', ['cancelled', 'expired']),
            default => $query,
        };
    }
}
