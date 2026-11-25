<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks;

use App\Core\Money\Money;
use App\Core\Party\Models\CustomerAccount;
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
use App\Core\Plugin\Contracts\AdminActionInputHandler;
use App\Core\Plugin\Contracts\AdminPageHandler;
use Carbon\CarbonImmutable;
use EcSugar\PluginMemberRanks\Models\RankChange;
use EcSugar\PluginMemberRanks\Models\RankMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * 管理画面の「会員ランク」（ADR 0051 の App の画面。ADR 0079）。絞り込みはランクごとの人数・ランクなし・手で決めた。
 * 行は会員（核の「お客さん」の画面へのリンク。台帳の ID の末尾 8 文字だけ）・ランク・直近 12 か月の購入額・手で決めた期限・今のランクになった日時で、名前やメールは出さない。
 * 1 人の画面で、ランクを手で決める・購入額で決めるに戻す（お金の段階。押す前にパスキーで直前の再確認。actor をイベントに残す）
 */
final class RankAdminPage implements AdminActionInputHandler, AdminPageHandler
{
    private const array CAUSES = [RankChange::AUTO => '購入額による更新', RankChange::MANUAL => '店の人による手動の設定', RankChange::LEFT => '会員でなくなった'];

    public function title(): string
    {
        return '会員ランク';
    }

    public function filters(): array
    {
        $filters = [new AdminFilter('all', 'すべての会員', self::filtered('all')->count())];

        foreach (array_reverse(self::ranks(), true) as $position => $rank) {
            $filters[] = new AdminFilter('rank'.$position, $rank->name, self::filtered('rank'.$position)->count());
        }

        $filters[] = new AdminFilter('none', 'ランクなし', self::filtered('none')->count());
        $filters[] = new AdminFilter('pinned', '手動で設定したランク', self::filtered('pinned')->count());

        return $filters;
    }

    public function attention(): array
    {
        $broken = app(RankSettings::class)->brokenAt();

        return [
            new AdminAttention('all', '会員ランクの購入額の設定が逆転しているため、上のランクが使われていません',
                'App の設定の「ランク ◯ になる購入額」を、上のランクほど大きい額にしてください（下のランク以下の額になっているランクと、それより上のランクは、今は使われていません）。', $broken === null ? 0 : 1, 'bad'),
        ];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('member', '会員', CellType::Link),
            new AdminColumn('rank', 'ランク', CellType::Status),
            new AdminColumn('amount', '直近 12 か月の購入額', CellType::Money),
            new AdminColumn('pinned', '手動設定の期限', CellType::DateTime),
            new AdminColumn('ranked', '今のランクになった日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $accounts = $query->orderByDesc('member_ranks_members.rank')->orderByDesc('customer_accounts.member_since')->orderBy('customer_accounts.id')
            ->forPage($page, $perPage)->get(['customer_accounts.id', 'member_ranks_members.rank', 'member_ranks_members.pinned_until', 'member_ranks_members.ranked_at']);
        // 額はこのページの会員の分だけを 1 回で数える
        $amounts = app(PurchaseAmounts::class)->of($accounts->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all(), CarbonImmutable::now());
        $ranks = self::ranks();

        return new AdminRows($accounts->map(static function (CustomerAccount $a) use ($amounts, $ranks): AdminRow {
            $position = min((int) $a->getAttribute('rank'), count($ranks));
            $pinned = $a->getAttribute('pinned_until');

            return new AdminRow($a->id, [
                'member' => self::member($a->id),
                'rank' => self::status($position, $ranks),
                'amount' => $amounts[$a->id] ?? 0,
                'pinned' => is_string($pinned) ? CarbonImmutable::parse($pinned) : null,
                'ranked' => is_string($a->getAttribute('ranked_at')) ? CarbonImmutable::parse((string) $a->getAttribute('ranked_at')) : null,
            ]);
        })->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $account = CustomerAccount::query()->find(strtolower($id));

        if ($account === null || ! Ranks::eligible($account)) {
            return null;
        }

        $now = CarbonImmutable::now();
        $ranks = self::ranks();
        $row = RankMember::query()->find($account->id);
        $position = app(Ranks::class)->current($account->id);
        $amount = app(PurchaseAmounts::class)->forAccount($account->id, $now);
        $next = $ranks[$position + 1] ?? null;
        $changes = RankChange::query()->where('customer_account_id', $account->id)->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();
        $options = ['none' => 'ランクなし'];

        foreach ($ranks as $p => $rank) {
            $options['rank'.$p] = $rank->name;
        }

        $actions = [
            new AdminAction('set', 'ランクを手動で設定する', '選んだランクに変更します。今日から 1 年間は、毎日の自動の更新でランクが変わりません（会員価格とポイントの上乗せも、このランクで適用されます）。ランクを上げた時は、会員にお知らせのメールを送ります。', money: true, fields: [
                new AdminField('rank', FieldType::Choice, 'ランク', required: true, options: $options, default: $position === 0 ? 'none' : 'rank'.$position),
            ]),
        ];

        if ($row?->pinned_until !== null) {
            $actions[] = new AdminAction('unpin', '購入額による自動の設定に戻す', '手動で設定したランクをやめ、直近 12 か月の購入額でランクを決め直します。', money: true);
        }

        return new AdminRecord('会員 '.strtoupper(substr($account->id, -8)).' のランク', [
            new AdminFact('会員', CellType::Link, self::member($account->id)),
            new AdminFact('今のランク', CellType::Status, self::status($position, $ranks)),
            new AdminFact('直近 12 か月の購入額', CellType::Money, $amount),
            new AdminFact('次のランク', CellType::Text, $next === null ? null : $next->name.'（あと '.Money::format(max(0, $next->threshold - $amount)).'）'),
            new AdminFact('手動設定の期限', CellType::DateTime, $row?->pinned_until),
            new AdminFact('今のランクになった日時', CellType::DateTime, $row?->ranked_at),
        ], tables: [
            new AdminTable('ランクの履歴（新しい順に 20 件まで）', [
                new AdminColumn('at', '日時', CellType::DateTime),
                new AdminColumn('change', '変更', CellType::Text),
                new AdminColumn('cause', '理由', CellType::Text),
            ], $changes->map(static fn (RankChange $c): AdminRow => new AdminRow($c->id, [
                'at' => $c->created_at,
                'change' => self::name($c->from_rank, $ranks).' → '.self::name($c->to_rank, $ranks),
                'cause' => self::CAUSES[$c->cause] ?? $c->cause,
            ]))->values()->all()),
        ], actions: $actions);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'unpin' => app(Ranks::class)->unpin(self::accountId($id), $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    public function actWith(string $id, string $action, array $values, string $actor): void
    {
        if ($action !== 'set') {
            throw new AdminRecordNotFound;
        }

        $rank = $values['rank'] ?? null;

        if (! is_string($rank) || preg_match('/\A(?:none|rank([1-3]))\z/', $rank, $m) !== 1) {
            throw new AdminActionRefused('ランクを選び直してください。');
        }

        app(Ranks::class)->setManually(self::accountId($id), (int) ($m[1] ?? 0), $actor);
    }

    private static function accountId(string $id): string
    {
        $id = strtolower($id);

        return preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1 ? $id : throw new AdminRecordNotFound;
    }

    /**
     * @return array<int, Rank>
     */
    private static function ranks(): array
    {
        return app(RankSettings::class)->ranks();
    }

    /**
     * @param  array<int, Rank>  $ranks
     * @return array{label: string, tone: string}
     */
    private static function status(int $position, array $ranks): array
    {
        return ['label' => self::name($position, $ranks), 'tone' => $position === 0 ? 'muted' : 'good'];
    }

    /**
     * @param  array<int, Rank>  $ranks
     */
    private static function name(int $position, array $ranks): string
    {
        return $position === 0 ? 'ランクなし' : ($ranks[$position]->name ?? "ランク {$position}（今は使っていない）");
    }

    /**
     * 会員（核の「お客さん」の画面へ。見せるのは台帳の ID の末尾 8 文字だけ）
     *
     * @return array{label: string, kind: string, id: string}
     */
    private static function member(string $accountId): array
    {
        return ['label' => '会員 '.strtoupper(substr($accountId, -8)), 'kind' => 'customer', 'id' => $accountId];
    }

    /**
     * ランクを持てる会員（まとめた記録・止めた台帳を除く）と、今のランクの行
     *
     * @return Builder<CustomerAccount>
     */
    private static function filtered(string $filter): Builder
    {
        $count = count(self::ranks());
        $query = CustomerAccount::query()
            ->leftJoin('member_ranks_members', 'member_ranks_members.customer_account_id', '=', 'customer_accounts.id')
            ->whereNotNull('customer_accounts.member_since')->whereNull('customer_accounts.disabled_at')->whereNull('customer_accounts.merged_into_id');

        if (preg_match('/\Arank([1-3])\z/', $filter, $m) === 1) {
            // 使うランクを減らした時の、上のランクの人は使う中でいちばん上に数える（Ranks::current と同じ）
            $position = (int) $m[1];

            return $position === $count ? $query->where('member_ranks_members.rank', '>=', $position) : $query->where('member_ranks_members.rank', $position);
        }

        return match ($filter) {
            'none' => $query->where(static fn ($q) => $q->whereNull('member_ranks_members.rank')->orWhere('member_ranks_members.rank', 0)),
            'pinned' => $query->where('member_ranks_members.pinned_until', '>', CarbonImmutable::now()),
            default => $query,
        };
    }
}
