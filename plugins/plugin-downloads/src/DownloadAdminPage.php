<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

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
use App\Core\Plugin\Contracts\AdminLookupHandler;
use App\Core\Plugin\Contracts\AdminOrderPanelProvider;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 管理画面の App の画面「ダウンロード」（ADR 0084）。1 行は 1 つの注文（リンクを作った注文）。
 * 1 件の画面と注文の画面（AdminOrderPanelProvider）に、ファイルごとの回数・最後の日時・期限・様子。操作は回数を戻す・期限を延ばす・止める・再開する（運用の段階から。記録に残す）。
 * 一覧の上の欄で注文番号から探せる（AdminLookupHandler）。個人情報は出さない（お客さんの名前は注文の画面で）
 */
final class DownloadAdminPage implements AdminLookupHandler, AdminOrderPanelProvider, AdminPageHandler
{
    private const array FILTERS = ['active' => 'ダウンロードできる', 'waiting' => 'ファイル待ち', 'used_up' => '回数を使い切った', 'expired' => '期限切れ', 'stopped' => '停止中', 'all' => 'すべて'];

    public function title(): string
    {
        return 'ダウンロード';
    }

    public function filters(): array
    {
        return array_map(fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], $key === 'waiting' ? DownloadGrants::waiting()->count() : $this->orders($key)->count()), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [new AdminAttention('waiting', 'ファイルが無く、ダウンロードのリンクを送れていない注文', '支払い済みなのに、商品にファイルが登録されていないため、お客さんにダウンロードのリンクを送れていない注文です。商品の画面でファイルをアップロードすると、10 分以内に自動で送ります（注文ごとの画面の「今のファイルでリンクを送る」を押すと、すぐに送れます）。',
            DownloadGrants::waiting()->count(), 'warn'),
            new AdminAttention('used_up', 'ダウンロードの回数を使い切った注文', '回数を使い切ったファイルのある注文です。お客さんから問い合わせがあれば、注文ごとの画面で回数をリセットできます。',
                $this->orders('used_up')->where('last_at', '>', CarbonImmutable::now()->subDays(7))->count(), 'muted')];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('order', '注文', CellType::Link),
            new AdminColumn('state', '状態', CellType::Status),
            new AdminColumn('files', 'ファイル', CellType::Number),
            new AdminColumn('downloads', 'ダウンロードの回数', CellType::Number),
            new AdminColumn('last', '最後のダウンロード', CellType::DateTime),
            new AdminColumn('expires', '期限', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        if ($filter === 'waiting') {
            $waiting = DownloadGrants::waiting();

            return new AdminRows((clone $waiting)->orderByDesc('created_at')->forPage($page, $perPage)->get()->map(static fn (Transaction $order): AdminRow => new AdminRow($order->id, [
                'order' => ['label' => strtoupper($order->id), 'kind' => 'transaction', 'id' => $order->id],
                'state' => ['label' => 'ファイル待ち', 'tone' => 'warn'],
                'files' => DownloadGrant::query()->where('transaction_id', $order->id)->count(),
                'downloads' => 0,
                'last' => null,
                'expires' => null,
            ]))->all(), $waiting->count());
        }

        $query = $this->orders($filter);
        $total = (clone $query)->count();
        $now = CarbonImmutable::now();
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get()->map(static function (\stdClass $row) use ($now): AdminRow {
            $id = (string) $row->transaction_id;

            return new AdminRow($id, [
                'order' => ['label' => strtoupper($id), 'kind' => 'transaction', 'id' => $id],
                'state' => self::state((bool) $row->stopped, CarbonImmutable::parse((string) $row->expires_at)->lessThanOrEqualTo($now), (bool) $row->used_up),
                'files' => (int) $row->files,
                'downloads' => (int) $row->downloads,
                'last' => $row->last_at === null ? null : CarbonImmutable::parse((string) $row->last_at),
                'expires' => CarbonImmutable::parse((string) $row->expires_at),
            ]);
        })->all();

        return new AdminRows($rows, $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $grants = DownloadGrant::query()->where('transaction_id', $id)->orderBy('created_at')->orderBy('id')->get();
        $order = Transaction::query()->find($id);
        $waiting = DownloadGrants::waiting()->whereKey($id)->exists();

        if ($order === null || ($grants->isEmpty() && ! $waiting)) {
            return null;
        }

        // ファイルが無くて渡せていない注文（払った時にファイルが無かった商品がある）: 置き直したら「今のファイルで渡す」
        if ($waiting) {
            return new AdminRecord('ご注文番号 '.strtoupper($order->id), [
                new AdminFact('注文', CellType::Link, ['label' => strtoupper($order->id), 'kind' => 'transaction', 'id' => $order->id]),
                new AdminFact('状態', CellType::Status, ['label' => 'ファイル待ち', 'tone' => 'warn']),
                new AdminFact('案内', CellType::Text, '商品にファイルが無いため、まだダウンロードのリンクを送っていません。商品の画面でファイルをアップロードすると、10 分以内に自動で送ります。'),
            ], [], [new AdminAction('grant', '今のファイルでリンクを送る', '今アップロードされているファイルで、この注文のダウンロードのリンクを作り、お客さんへご案内のメールを送ります。')]);
        }

        $now = CarbonImmutable::now();
        $files = DownloadFile::query()->whereIn('id', $grants->pluck('file_id'))->get()->keyBy('id');
        $closed = $order->status->isClosed() || in_array($order->phase, [Phase::Cancel, Phase::Refund], true);
        $rows = [];

        foreach ($grants as $grant) {
            $file = $files->get($grant->file_id);
            $rows[] = new AdminRow(null, [
                'name' => $file === null ? 'ファイル' : mb_substr($file->name, 0, 100).($file->purged_at !== null ? '（削除済み）' : ''),
                'count' => $grant->count.' / '.$grant->max_count.' 回',
                'last' => $grant->last_downloaded_at,
                'expires' => $grant->expires_at,
                'state' => self::state($grant->stopped_at !== null, $grant->expires_at->lessThanOrEqualTo($now), $grant->count >= $grant->max_count, $grant->stop_reason),
            ]);
        }

        $stopped = $grants->first(static fn (DownloadGrant $g): bool => $g->stopped_at !== null);
        $actions = [];

        if ($grants->contains(static fn (DownloadGrant $g): bool => $g->count > 0)) {
            $actions[] = new AdminAction('reset', '回数をリセット', 'この注文のファイルのダウンロードの回数を 0 回に戻します（操作の記録が残ります）。');
        }

        if (! $closed) {
            $actions[] = new AdminAction('extend', '期限を '.DownloadGrants::EXTEND_DAYS.' 日延ばす', 'この注文のダウンロードの期限を、今（期限の前なら今の期限）から '.DownloadGrants::EXTEND_DAYS.' 日延ばします。');

            if ($grants->contains(static fn (DownloadGrant $g): bool => $g->stopped_at === null)) {
                $actions[] = new AdminAction('stop', 'ダウンロードを止める', 'この注文のダウンロードを止めます（お客さんはダウンロードできなくなります。あとで再開できます）。', danger: true);
            }

            if ($grants->contains(static fn (DownloadGrant $g): bool => $g->stop_reason === 'staff')) {
                $actions[] = new AdminAction('resume', '再開する', '止めたダウンロードを、またダウンロードできるようにします。');
            }
        }

        return new AdminRecord('ご注文番号 '.strtoupper($order->id), [
            new AdminFact('注文', CellType::Link, ['label' => strtoupper($order->id), 'kind' => 'transaction', 'id' => $order->id]),
            new AdminFact('状態', CellType::Status, $closed ? ['label' => '停止（注文の取消・返金）', 'tone' => 'muted']
                : self::state($stopped !== null, $grants->every(static fn (DownloadGrant $g): bool => $g->expires_at->lessThanOrEqualTo($now)), $grants->contains(static fn (DownloadGrant $g): bool => $g->count >= $g->max_count), $stopped?->stop_reason)),
            new AdminFact('期限', CellType::DateTime, $grants->max('expires_at')),
            new AdminFact('最後のダウンロード', CellType::DateTime, $grants->max('last_downloaded_at')),
            new AdminFact('ダウンロードの回数（合計）', CellType::Number, (int) $grants->sum('count')),
        ], [
            new AdminTable('ファイル', [
                new AdminColumn('name', 'ファイル', CellType::Text),
                new AdminColumn('count', '回数', CellType::Text),
                new AdminColumn('last', '最後のダウンロード', CellType::DateTime),
                new AdminColumn('expires', '期限', CellType::DateTime),
                new AdminColumn('state', '状態', CellType::Status),
            ], $rows),
        ], $actions);
    }

    public function act(string $id, string $action, string $actor): void
    {
        $grants = app(DownloadGrants::class);

        if ($action === 'grant') {
            if (! $grants->paid($id)) {
                throw new AdminActionRefused('まだリンクを送れません（ファイルの無い商品が残っているか、注文がまだ支払い済みになっていません）。');
            }

            SendDownloadMail::dispatch($id);

            return;
        }

        if (! DownloadGrant::query()->where('transaction_id', $id)->exists()) {
            throw new AdminRecordNotFound;
        }

        $changed = match ($action) {
            'reset' => $grants->reset($id, $actor),
            'extend' => $grants->extend($id, $actor, CarbonImmutable::now()),
            'stop' => $grants->stop($id, $actor, CarbonImmutable::now()),
            'resume' => $grants->resume($id, $actor),
            default => throw new AdminRecordNotFound,
        };

        if ($changed === 0) {
            throw new AdminActionRefused('変更するものがありませんでした（ほかの人が先に操作したか、注文が取り消されています）。');
        }
    }

    public function recordForOrder(Transaction $order): ?string
    {
        return DownloadGrant::query()->where('transaction_id', $order->id)->exists() || DownloadGrants::waiting()->whereKey($order->id)->exists() ? $order->id : null;
    }

    public function lookupLabel(): string
    {
        return '注文番号で探す';
    }

    public function lookupHint(): string
    {
        return 'お客さんから問い合わせのあった注文番号（26 文字）を入れると、その注文のダウンロードの状況を開きます。';
    }

    public function lookupScan(): bool
    {
        return false;
    }

    public function lookup(string $input, string $actor): string
    {
        $id = strtolower(trim((string) preg_replace('/[\s#-]+/u', '', mb_convert_kana($input, 'as'))));

        if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) !== 1 || (! DownloadGrant::query()->where('transaction_id', $id)->exists() && ! DownloadGrants::waiting()->whereKey($id)->exists())) {
            throw new AdminActionRefused('その注文番号のダウンロードは見つかりませんでした。');
        }

        return $id;
    }

    /**
     * 注文ごとのまとめ（絞り込みつき）。行は transaction_id・files・downloads・last_at・expires_at・created_at・stopped・used_up
     */
    private function orders(string $filter): Builder
    {
        $now = CarbonImmutable::now();
        $summary = DB::table('downloads_grants')->select('transaction_id')
            ->selectRaw('COUNT(*) AS files, SUM(count) AS downloads, MAX(last_downloaded_at) AS last_at, MAX(expires_at) AS expires_at, MIN(created_at) AS created_at')
            ->selectRaw('BOOL_OR(stopped_at IS NOT NULL) AS stopped, BOOL_OR(count >= max_count) AS used_up')
            ->groupBy('transaction_id');
        $query = DB::query()->fromSub($summary, 'downloads_summary');

        return match ($filter) {
            'active' => $query->where('stopped', false)->where('expires_at', '>', $now),
            'used_up' => $query->where('used_up', true)->where('stopped', false),
            'expired' => $query->where('expires_at', '<=', $now)->where('stopped', false),
            'stopped' => $query->where('stopped', true),
            default => $query,
        };
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function state(bool $stopped, bool $expired, bool $usedUp, ?string $reason = null): array
    {
        return match (true) {
            $stopped => ['label' => '停止（'.(DownloadGrant::STOP_REASONS[(string) $reason] ?? 'お店による停止').'）', 'tone' => 'muted'],
            $expired => ['label' => '期限切れ', 'tone' => 'muted'],
            $usedUp => ['label' => '回数を使い切った', 'tone' => 'warn'],
            default => ['label' => 'ダウンロードできる', 'tone' => 'good'],
        };
    }
}
