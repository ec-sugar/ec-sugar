<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

use App\Core\Plugin\AdminPage\AdminAction;
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
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use EcSugar\PluginProductFeed\Models\Feed;

/**
 * 管理画面の「商品フィード」（ADR 0051 の App の画面）。一覧は行き先（Google・Meta）の 2 行、1 件の画面に URL（写せる）・出す行と商品の数・
 * 店の人が外した商品・出せない商品とその理由。操作は URL を作る・作り直す・止める（作り直す・止めるは取り消せないので直前の再確認の後）。
 * 出すのは商品の名前と数だけで、お客さんの個人情報は扱わない
 */
final class FeedAdminPage implements AdminPageHandler
{
    /** 1 件の画面の表に出す商品の数（核の上限は 200 行） */
    private const int TABLE_ROWS = 200;

    public function title(): string
    {
        return '商品フィード';
    }

    public function filters(): array
    {
        return [new AdminFilter('all', 'フィード', Feed::query()->count())];
    }

    public function attention(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('name', 'フィード', CellType::Text),
            new AdminColumn('status', 'URL', CellType::Status),
            new AdminColumn('rows', 'フィードの行数', CellType::Number),
            new AdminColumn('issued', 'URL を作った日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $feeds = self::feeds();
        $rows = $feeds === [] ? 0 : self::cache()->summary()['rows'];

        return new AdminRows(array_map(static fn (Feed $feed): AdminRow => new AdminRow($feed->id, [
            'name' => $feed->format->label(),
            'status' => self::status($feed),
            'rows' => $rows,
            'issued' => $feed->issued_at,
        ]), array_slice($feeds, ($page - 1) * $perPage, $perPage)), count($feeds));
    }

    public function record(string $id): ?AdminRecord
    {
        $feed = Feed::query()->find(strtolower($id));

        if ($feed === null) {
            return null;
        }

        $summary = self::cache()->summary();
        $url = self::url($feed);
        $actions = $feed->token_hash === null
            ? [new AdminAction('issue', 'URL を作る', 'フィードの URL を作ります。この URL を知っている人は、ショップに公開している商品の一覧（名前・値段・在庫の有無・写真）を読めます。Google や Meta の登録の画面だけに貼り付けてください。')]
            : [
                new AdminAction('rotate', 'URL を作り直す', '新しい URL を作ります。今の URL はすぐに使えなくなるため、Google や Meta の登録の画面に、新しい URL を入れ直してください。', danger: true),
                new AdminAction('stop', 'URL を止める', 'URL を無効にします。Google や Meta が読み込みに来てもフィードを返さなくなり、向こうの画面では読み込みのエラーになります。もう一度使うときは「URL を作る」で新しい URL を作ってください。', danger: true),
            ];

        $facts = [
            new AdminFact('URL', CellType::Status, self::status($feed)),
            new AdminFact('フィードの URL', CellType::Text, $url === null || mb_strlen($url) > CellType::TEXT_MAX ? null : $url, copy: true),
            new AdminFact('形式', CellType::Text, $feed->format === FeedFormat::Google ? 'RSS 2.0 の XML（Google の商品データの項目）' : 'RSS 2.0 の XML（Google の形。Meta はこの形を読めます）'),
            new AdminFact('フィードの行数（種類ごと）', CellType::Number, $summary['rows']),
            new AdminFact('フィードに載る商品', CellType::Number, $summary['listings']),
            new AdminFact('フィードから外した商品', CellType::Number, count($summary['excluded'])),
            new AdminFact('フィードに載せられない商品', CellType::Number, count($summary['skipped'])),
            new AdminFact('URL を作った日時', CellType::DateTime, $feed->issued_at),
            new AdminFact('フィードの更新', CellType::Text, '最長 1 時間ごと（商品を変更すると、Google や Meta が次に読み込んだ時に新しい内容になります）'),
        ];

        if ($summary['over'] > 0) {
            $facts[] = new AdminFact('行数の上限を超えたため載せなかった商品', CellType::Number, $summary['over']);
        }

        return new AdminRecord($feed->format->label(), $facts, [
            new AdminTable('フィードから外した商品', [new AdminColumn('listing', '商品', CellType::Link)], self::listingRows($summary['excluded'], null)),
            new AdminTable('フィードに載せられない商品', [new AdminColumn('listing', '商品', CellType::Link), new AdminColumn('reason', '理由', CellType::Text)],
                self::listingRows(array_map(static fn (array $s): string => $s['title'], $summary['skipped']), array_map(static fn (array $s): string => FeedBuilder::REASONS[$s['reason']] ?? $s['reason'], $summary['skipped']))),
        ], $actions);
    }

    public function act(string $id, string $action, string $actor): void
    {
        $tokens = app(FeedTokens::class);

        match ($action) {
            'issue' => $tokens->issue($id, $actor),
            'rotate' => $tokens->rotate($id, $actor),
            'stop' => $tokens->stop($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    /**
     * ショップの 1 つ目のドメインのフィードの URL（まだ作っていなければ null）
     */
    public static function url(Feed $feed): ?string
    {
        $tenant = tenant();

        if ($feed->token === null || ! $tenant instanceof Tenant) {
            return null;
        }

        return StorefrontUrls::of($tenant, '/p/'.ProductFeedServiceProvider::NAME."/{$feed->format->value}/{$feed->token}.xml");
    }

    /**
     * @return list<Feed> Google・Meta の順
     */
    private static function feeds(): array
    {
        return Feed::query()->get()->sortBy(static fn (Feed $feed): int => $feed->format === FeedFormat::Google ? 0 : 1)->values()->all();
    }

    private static function cache(): FeedCache
    {
        return app(FeedCache::class);
    }

    /**
     * @param  array<string, string>  $titles  商品の ID => 名前
     * @param  array<string, string>|null  $reasons  商品の ID => 理由
     * @return list<AdminRow>
     */
    private static function listingRows(array $titles, ?array $reasons): array
    {
        $rows = [];

        foreach (array_slice($titles, 0, self::TABLE_ROWS, true) as $id => $title) {
            $cells = ['listing' => ['label' => self::cut($title), 'kind' => 'listing', 'id' => (string) $id]];

            if ($reasons !== null) {
                $cells['reason'] = self::cut($reasons[$id] ?? '');
            }

            $rows[] = new AdminRow(null, $cells);
        }

        return $rows;
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function status(Feed $feed): array
    {
        return $feed->token_hash === null ? ['label' => 'URL が未作成', 'tone' => 'muted'] : ['label' => '使えます', 'tone' => 'good'];
    }

    private static function cut(string $text): string
    {
        return mb_strlen($text) > CellType::TEXT_MAX ? mb_substr($text, 0, CellType::TEXT_MAX - 1).'…' : $text;
    }
}
