<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder;

use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminAttention;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminField;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\AdminTable;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\AdminPage\FieldType;
use App\Core\Plugin\Contracts\AdminFormHandler;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginPreorder\Models\PreorderItem;
use EcSugar\PluginPreorder\Models\PreorderListing;
use Illuminate\Database\Eloquent\Builder;

/**
 * 管理画面の「予約販売」（ADR 0051 の App の画面。ADR 0073 の 7）。予約の商品ごとに、予約の数・上限・発売日と予約の注文の並び。
 * 直す欄で発売日・受付の終わり・上限を変える（発売日を変えると、予約したお客さんにメールで知らせる）。予約販売にする・やめるは商品の画面の欄で。
 * 発売日を過ぎて発送も済んだ商品は、操作「次の予約を始める」で回を進めてから、新しい発売日を入れる（前の回の予約を数えない）。
 * ホームの「やること」に、発売日になって発送を始められる予約の注文の数を出す。お客さんの名前と連絡先は出さない（注文へのリンクだけ）
 */
final class PreorderAdminPage implements AdminFormHandler, AdminPageHandler
{
    private const array FILTERS = ['open' => '予約を受付中', 'ready' => '発送を始められる', 'all' => 'すべて'];

    public function title(): string
    {
        return '予約販売';
    }

    public function filters(): array
    {
        return array_map(fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], self::filtered($key)->count()), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [new AdminAttention('ready', '発送を始められる予約の注文', '発売日になった予約の注文です。商品がそろったら、注文の画面から発送してください。',
            self::readyOrders()->count(), 'warn')];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('listing', '商品', CellType::Link),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('release', '発売日', CellType::Date),
            new AdminColumn('taken', '予約の数', CellType::Number),
            new AdminColumn('capacity', '上限', CellType::Number),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $rows = $query->orderBy('release_date')->orderBy('id')->forPage($page, $perPage)->get();
        $taken = PreorderCounts::taken($rows->pluck('listing_id')->all());
        $titles = Listing::query()->whereIn('id', $rows->pluck('listing_id'))->pluck('title', 'id');

        return new AdminRows($rows->map(static fn (PreorderListing $row): AdminRow => new AdminRow($row->id, [
            'listing' => ['label' => mb_strimwidth((string) ($titles[$row->listing_id] ?? '商品'), 0, 80, '…'), 'kind' => 'listing', 'id' => $row->listing_id],
            'status' => self::status($row, $taken[$row->listing_id] ?? 0),
            'release' => self::day($row->releaseDate()),
            'taken' => $taken[$row->listing_id] ?? 0,
            'capacity' => $row->capacity,
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $row = PreorderListing::query()->find($id);

        if ($row === null) {
            return null;
        }

        $taken = PreorderCounts::taken([$row->listing_id])[$row->listing_id] ?? 0;
        $title = (string) Listing::query()->whereKey($row->listing_id)->value('title');
        $items = PreorderItem::query()->where('listing_id', $row->listing_id)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
        $orders = Transaction::query()->whereIn('id', $items->pluck('transaction_id'))->get()->keyBy('id');
        $pending = Fulfillment::query()->whereIn('transaction_id', $items->pluck('transaction_id'))->where('status', FulfillmentStatus::Pending)->pluck('transaction_id')->flip();

        return new AdminRecord('予約販売 '.mb_strimwidth($title, 0, 60, '…'), [
            new AdminFact('商品', CellType::Link, ['label' => mb_strimwidth($title === '' ? '商品' : $title, 0, 80, '…'), 'kind' => 'listing', 'id' => $row->listing_id]),
            new AdminFact('状態', CellType::Status, self::status($row, $taken)),
            new AdminFact('発売日（発送を始める日）', CellType::Date, self::day($row->releaseDate())),
            new AdminFact('予約の締切日', CellType::Date, $row->endsOn() === null ? null : self::day($row->endsOn())),
            new AdminFact('予約の数', CellType::Number, $taken),
            new AdminFact('予約の数の上限', CellType::Number, $row->capacity),
            new AdminFact('取り消し', CellType::Text, 'お客さんから取り消しの連絡があったときは、注文の画面の「注文を取り消す」で全額を返金してください（予約の数も戻ります）。'),
        ], [new AdminTable('予約の注文（新しい 200 件）', [
            new AdminColumn('order', '注文', CellType::Link),
            new AdminColumn('quantity', '数', CellType::Number),
            new AdminColumn('state', '状態', CellType::Status),
            new AdminColumn('release', '約束した発売日', CellType::Date),
        ], $items->map(static function (PreorderItem $item) use ($orders, $pending): AdminRow {
            $order = $orders->get($item->transaction_id);

            return new AdminRow(null, [
                'order' => ['label' => strtoupper(substr($item->transaction_id, -8)), 'kind' => 'transaction', 'id' => $item->transaction_id],
                'quantity' => $item->quantity,
                'state' => self::orderState($order, isset($pending[$item->transaction_id])),
                'release' => self::day($item->releaseDate()),
            ]);
        })->values()->all())], PreorderListings::canStartNext($row)
            ? [new AdminAction('next_round', '次の予約を始める', 'この商品の前回の予約（発売日を過ぎて発送も終わったもの）を締め切り、次の予約を受け付けられるようにします。前回の予約の数は数えなくなります。この後、「編集」で新しい発売日を入力してください。')]
            : []);
    }

    public function act(string $id, string $action, string $actor): void
    {
        if ($action !== 'next_round' || PreorderListing::query()->find($id) === null) {
            throw new AdminRecordNotFound;
        }

        try {
            app(PreorderListings::class)->nextRound($id, $actor);
        } catch (PreorderRefused $e) {
            throw new AdminActionRefused($e->getMessage());
        }
    }

    public function createLabel(): ?string
    {
        // 予約販売にするのは商品の画面の欄で（ここは直すだけ）
        return null;
    }

    public function fields(): array
    {
        return [
            new AdminField('release_date', FieldType::Text, '発売日（発送を始める日）', '「2026-11-20」の形で入力します。変更すると、予約したお客さんにメールでお知らせします。今日の日付にすると、予約の注文をすぐ発送できます。', required: true, max: 10),
            new AdminField('ends_on', FieldType::Text, '予約の締切日（任意）', '「2026-11-15」の形で入力します。その日まで予約を受け付けます。空なら発売日の前日まで受け付けます。', max: 10),
            new AdminField('capacity', FieldType::Text, '予約の数の上限（任意）', '入荷する数に合わせて入力します。今の予約の数より少なくはできません。空なら上限なし。', max: 6),
        ];
    }

    public function values(string $id): ?array
    {
        $row = PreorderListing::query()->find($id);

        // 止めた予約販売は直せない（商品の画面の欄で始め直す）
        if ($row === null || ! $row->active) {
            return null;
        }

        return ['release_date' => $row->releaseDate(), 'ends_on' => $row->endsOn(), 'capacity' => $row->capacity === null ? null : (string) $row->capacity];
    }

    public function create(array $values, string $actor): string
    {
        throw new AdminRecordNotFound;
    }

    public function update(string $id, array $values, string $actor): void
    {
        $row = PreorderListing::query()->find($id);
        $listing = $row === null || ! $row->active ? null : Listing::query()->find($row->listing_id);

        if ($listing === null) {
            throw new AdminRecordNotFound;
        }

        app(PreorderListings::class)->save($listing, [
            'enabled' => true,
            'release_date' => is_string($values['release_date'] ?? null) ? $values['release_date'] : null,
            'ends_on' => is_string($values['ends_on'] ?? null) ? $values['ends_on'] : null,
            'capacity' => is_string($values['capacity'] ?? null) ? $values['capacity'] : null,
        ], $actor);
    }

    /**
     * 発売日になって発送を始められる予約の注文（払った・開いている注文で、発送を始める日が今日までの発送の前の受渡がある）
     *
     * @return Builder<Transaction>
     */
    private static function readyOrders(): Builder
    {
        return Transaction::query()
            ->where('status', TransactionStatus::Open)
            ->whereIn('phase', [Phase::Payment, Phase::Fulfillment])
            ->whereIn('id', PreorderItem::query()->select('transaction_id'))
            ->whereIn('id', Fulfillment::query()->where('status', FulfillmentStatus::Pending)->whereNotNull('ships_on')->where('ships_on', '<=', PreorderTerms::today())->select('transaction_id'));
    }

    /**
     * @return Builder<PreorderListing>
     */
    private static function filtered(string $filter): Builder
    {
        $query = PreorderListing::query();

        return match ($filter) {
            'open' => $query->where('active', true)->where('release_date', '>', PreorderTerms::today()),
            'ready' => $query->whereIn('listing_id', PreorderItem::query()->select('listing_id')->whereIn('transaction_id', self::readyOrders()->select('id'))),
            default => $query,
        };
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function status(PreorderListing $row, int $taken): array
    {
        $today = PreorderTerms::today();

        return match (true) {
            ! $row->active => ['label' => '予約販売をやめた', 'tone' => 'muted'],
            $row->releaseDate() <= $today => ['label' => '発売日を過ぎた', 'tone' => 'muted'],
            ! PreorderCheckout::open($row, $today) => ['label' => '受付を終えた', 'tone' => 'warn'],
            $row->capacity !== null && $taken >= $row->capacity => ['label' => '上限に達した', 'tone' => 'warn'],
            default => ['label' => '受付中', 'tone' => 'good'],
        };
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function orderState(?Transaction $order, bool $pending): array
    {
        return match (true) {
            $order === null => ['label' => '—', 'tone' => 'muted'],
            $order->status->isClosed() => ['label' => '取り消し・返金', 'tone' => 'muted'],
            $order->phase === Phase::Agreement => ['label' => 'お支払い待ち', 'tone' => 'muted'],
            $pending => ['label' => '発送待ち', 'tone' => 'warn'],
            default => ['label' => '発送済み', 'tone' => 'good'],
        };
    }

    private static function day(string $date): ?CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, PreorderTerms::ZONE) ?: null;
    }
}
