<?php

declare(strict_types=1);

namespace EcSugar\PluginFaq;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminAction;
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
use EcSugar\PluginFaq\Models\FaqItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 管理画面の「よくある質問」（ADR 0051 の App の画面と、足す・直すの欄）。
 * 一覧（すべて・公開・非公開。並びの順）・1 件（足した日時など）・足す・直す・上へ・下へ・消す（取り消せないので直前の再確認の後）。
 * 質問と答えは店の人が書く文で、お客さんの個人情報は扱わない
 */
final class FaqAdminPage implements AdminFormHandler, AdminPageHandler
{
    /** 商品の選択肢に出す数（全部の商品の 1 つを足して、核の上限 FieldType::OPTIONS_MAX に収める） */
    private const int LISTING_OPTIONS = FieldType::OPTIONS_MAX - 1;

    private const array FILTERS = ['all' => 'すべて', 'published' => '公開', 'hidden' => '非公開'];

    public function title(): string
    {
        return 'よくある質問';
    }

    public function filters(): array
    {
        $published = FaqItem::query()->where('published', true)->count();
        $all = FaqItem::query()->count();
        $counts = ['all' => $all, 'published' => $published, 'hidden' => $all - $published];

        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], $counts[$key]), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('question', '質問', CellType::Text),
            new AdminColumn('listing', '表示する商品', CellType::Text),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('updated', '更新した日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $items = $query->orderBy('position')->forPage($page, $perPage)->get();
        $titles = self::titles($items);

        return new AdminRows($items->map(static fn (FaqItem $item): AdminRow => new AdminRow($item->id, [
            'question' => $item->question,
            'listing' => self::listingLabel($item, $titles),
            'status' => self::status($item),
            'updated' => $item->getAttribute('updated_at'),
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $item = FaqItem::query()->find($id);

        if ($item === null) {
            return null;
        }

        $titles = self::titles(collect([$item]));
        $rank = FaqItem::query()->where('position', '<=', $item->position)->count();
        $last = $rank === FaqItem::query()->count();
        $actions = [];

        if ($rank > 1) {
            $actions[] = new AdminAction('move_up', '上へ', '1 つ上へ移します。', repeatable: true);
        }

        if (! $last) {
            $actions[] = new AdminAction('move_down', '下へ', '1 つ下へ移します。', repeatable: true);
        }

        $actions[] = new AdminAction('delete', '削除する', 'この質問を削除します。削除すると元に戻せません（ショップの商品の画面からも消えます）。', danger: true);

        return new AdminRecord($item->question, [
            new AdminFact('状態', CellType::Status, self::status($item)),
            new AdminFact('表示する商品', CellType::Text, self::listingLabel($item, $titles)),
            new AdminFact('商品の画面', CellType::Link, $item->listing_id === null || ! isset($titles[$item->listing_id]) ? null : ['label' => '商品を開く', 'kind' => 'listing', 'id' => $item->listing_id]),
            new AdminFact('並び', CellType::Number, $rank),
            new AdminFact('答え（はじめの部分）', CellType::Text, self::cut(str_replace("\n", ' ', $item->answer))),
            new AdminFact('追加した日時', CellType::DateTime, $item->getAttribute('created_at')),
            new AdminFact('更新した日時', CellType::DateTime, $item->getAttribute('updated_at')),
        ], [], $actions);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'move_up' => self::items()->move($id, 'up', $actor),
            'move_down' => self::items()->move($id, 'down', $actor),
            'delete' => self::items()->delete($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    public function createLabel(): string
    {
        return '質問を追加';
    }

    public function fields(): array
    {
        $options = [FaqItems::ALL => 'すべての商品（共通）'];

        foreach (Listing::query()->orderBy('title')->orderBy('id')->limit(self::LISTING_OPTIONS)->get(['id', 'title']) as $listing) {
            $options[$listing->id] = self::cut($listing->title);
        }

        return [
            new AdminField('question', FieldType::Text, '質問', help: 'お客さんからよく聞かれることを、1 文で書きます。', required: true, max: FaqItems::QUESTION_MAX),
            new AdminField('answer', FieldType::Textarea, '答え', help: '改行はそのまま表示されます。HTML は使えません（タグも文字のまま表示されます）。', required: true, max: FaqItems::ANSWER_MAX),
            new AdminField('listing', FieldType::Choice, '表示する商品', help: '「すべての商品（共通）」にすると、どの商品の画面にも表示されます。', required: true, options: $options, default: FaqItems::ALL),
            new AdminField('published', FieldType::Toggle, 'ショップに表示する', help: 'オフにすると、質問を残したまま、ショップに表示しなくなります。', default: true),
        ];
    }

    public function values(string $id): ?array
    {
        $item = FaqItem::query()->find($id);

        return $item === null ? null : [
            'question' => $item->question,
            'answer' => $item->answer,
            'listing' => $item->listing_id ?? FaqItems::ALL,
            'published' => $item->published,
        ];
    }

    public function create(array $values, string $actor): string
    {
        return self::items()->create($values, $actor);
    }

    public function update(string $id, array $values, string $actor): void
    {
        self::items()->update($id, $values, $actor);
    }

    /**
     * 書く係（店の文脈の中で呼ばれた時に作る。登録の時には作らない）
     */
    private static function items(): FaqItems
    {
        return app(FaqItems::class);
    }

    /**
     * @return Builder<FaqItem>
     */
    private static function filtered(string $filter): Builder
    {
        $query = FaqItem::query();

        return match ($filter) {
            'published' => $query->where('published', true),
            'hidden' => $query->where('published', false),
            default => $query,
        };
    }

    /**
     * 商品の名前（消した商品は入らない）
     *
     * @param  Collection<int, FaqItem>  $items
     * @return array<string, string>
     */
    private static function titles(Collection $items): array
    {
        $ids = $items->pluck('listing_id')->filter()->unique()->values()->all();

        /** @var array<string, string> */
        return $ids === [] ? [] : Listing::query()->whereKey($ids)->pluck('title', 'id')->all();
    }

    /**
     * @param  array<string, string>  $titles
     */
    private static function listingLabel(FaqItem $item, array $titles): string
    {
        if ($item->listing_id === null) {
            return 'すべての商品';
        }

        return isset($titles[$item->listing_id]) ? self::cut($titles[$item->listing_id]) : '（削除した商品）';
    }

    /**
     * 画面の文の上限（CellType::TEXT_MAX 文字）に収める
     */
    private static function cut(string $text): string
    {
        return mb_strlen($text) > CellType::TEXT_MAX ? mb_substr($text, 0, CellType::TEXT_MAX - 1).'…' : $text;
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function status(FaqItem $item): array
    {
        return $item->published ? ['label' => '公開', 'tone' => 'good'] : ['label' => '非公開', 'tone' => 'muted'];
    }
}
