<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminAction;
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
use EcSugar\PluginReviews\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 管理画面の「レビュー」（ADR 0051 の App の画面と、直すだけの欄）。
 * 一覧（確認待ち・公開・非公開・すべて。新しい順）・1 件（星・題・表示名・商品・注文・本文）・公開する・非公開にする・消す（取り消せないので直前の再確認の後）・店からの返信。
 * 書くのはお客さんだけなので足す欄は無い（createLabel が null）。書いた人の名前・メール・届け先は出さない（注文はリンクで核の画面へ）
 */
final class ReviewAdminPage implements AdminFormHandler, AdminPageHandler
{
    public const int REPLY_MAX = 1000;

    private const array FILTERS = ['pending' => '確認待ち', 'published' => '公開', 'hidden' => '非公開', 'all' => 'すべて'];

    public function title(): string
    {
        return 'レビュー';
    }

    public function filters(): array
    {
        $counts = Review::query()->where('status', '!=', 'deleted')->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status')->all();
        $all = array_sum(array_map('intval', $counts));

        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], $key === 'all' ? $all : (int) ($counts[$key] ?? 0)), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [new AdminAttention('pending', '確認待ちのレビュー', 'お客さんが書いたレビューです。読んで、公開するか決めてください。', Review::query()->where('status', 'pending')->count())];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('title', 'タイトル', CellType::Text),
            new AdminColumn('rating', '星', CellType::Number),
            new AdminColumn('listing', '商品', CellType::Text),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('written', '書いた日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $reviews = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();
        $titles = self::titles($reviews);

        return new AdminRows($reviews->map(static fn (Review $review): AdminRow => new AdminRow($review->id, [
            'title' => self::cut((string) $review->title),
            'rating' => $review->rating,
            'listing' => self::cut($titles[$review->listing_id] ?? '（削除した商品）'),
            'status' => self::status($review),
            'written' => $review->created_at,
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $review = Review::query()->find(strtolower($id));

        if ($review === null || $review->status === 'deleted') {
            return null;
        }

        $title = self::titles(collect([$review]))[$review->listing_id] ?? null;
        $actions = [];

        if ($review->status !== 'published') {
            $actions[] = new AdminAction('publish', '公開する', 'ショップの商品ページに掲載します。', repeatable: true);
        }

        if ($review->status !== 'hidden') {
            $actions[] = new AdminAction('hide', '非公開にする', 'ショップに表示しないようにします（レビューは削除されずに残ります）。', repeatable: true);
        }

        $actions[] = new AdminAction('delete', '削除', 'このレビューを削除します。元に戻せません。同じご注文の同じ商品には、お客さんはもう書けません。', danger: true);

        return new AdminRecord(self::cut((string) $review->title), [
            new AdminFact('状態', CellType::Status, self::status($review)),
            new AdminFact('星', CellType::Number, $review->rating),
            new AdminFact('表示名', CellType::Text, $review->display_name),
            new AdminFact('商品', CellType::Link, $title === null ? null : ['label' => self::cut($title), 'kind' => 'listing', 'id' => $review->listing_id]),
            new AdminFact('ご注文', CellType::Link, ['label' => '注文を開く', 'kind' => 'transaction', 'id' => $review->transaction_id]),
            new AdminFact('書いた日時', CellType::DateTime, $review->created_at),
            new AdminFact('公開した日時', CellType::DateTime, $review->published_at),
            new AdminFact('店からの返信', CellType::Text, $review->reply === null ? null : self::cut(str_replace("\n", ' ', $review->reply))),
            new AdminFact('返信した日時', CellType::DateTime, $review->replied_at),
        ], [
            // 本文は 1 つの値の上限（200 文字）を超えるので、表の行に分けて全部を見せる（改行は空白に）
            new AdminTable('本文', [new AdminColumn('text', '本文', CellType::Text)], array_map(
                static fn (string $part): AdminRow => new AdminRow(null, ['text' => $part]),
                mb_str_split(str_replace("\n", ' ', (string) $review->body), CellType::TEXT_MAX),
            )),
        ], $actions);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'publish' => self::reviews()->publish($id, $actor),
            'hide' => self::reviews()->hide($id, $actor),
            'delete' => self::reviews()->delete($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    /**
     * 足す欄は無い（レビューを書くのはお客さんだけ。店の人が書いたレビューを混ぜない）
     */
    public function createLabel(): ?string
    {
        return null;
    }

    public function fields(): array
    {
        return [
            new AdminField('reply', FieldType::Textarea, '店からの返信', help: 'ショップのレビューの下に「ショップから」として出ます。空にすると返信を削除します。HTML は使えません。', max: self::REPLY_MAX),
        ];
    }

    public function values(string $id): ?array
    {
        $review = Review::query()->find(strtolower($id));

        return $review === null || $review->status === 'deleted' ? null : ['reply' => $review->reply];
    }

    public function create(array $values, string $actor): string
    {
        throw new AdminRecordNotFound;
    }

    public function update(string $id, array $values, string $actor): void
    {
        $reply = $values['reply'] ?? null;
        self::reviews()->reply($id, is_string($reply) ? $reply : null, $actor);
    }

    /**
     * 書く係（店の文脈の中で呼ばれた時に作る。登録の時には作らない）
     */
    private static function reviews(): Reviews
    {
        return app(Reviews::class);
    }

    /**
     * @return Builder<Review>
     */
    private static function filtered(string $filter): Builder
    {
        $query = Review::query()->where('status', '!=', 'deleted');

        return in_array($filter, ['pending', 'published', 'hidden'], true) ? $query->where('status', $filter) : $query;
    }

    /**
     * 商品の名前
     *
     * @param  Collection<int, Review>  $reviews
     * @return array<string, string>
     */
    private static function titles(Collection $reviews): array
    {
        $ids = $reviews->pluck('listing_id')->unique()->values()->all();

        /** @var array<string, string> */
        return $ids === [] ? [] : Listing::query()->whereKey($ids)->pluck('title', 'id')->all();
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
    private static function status(Review $review): array
    {
        return match ($review->status) {
            'published' => ['label' => '公開', 'tone' => 'good'],
            'hidden' => ['label' => '非公開', 'tone' => 'muted'],
            default => ['label' => '確認待ち', 'tone' => 'warn'],
        };
    }
}
