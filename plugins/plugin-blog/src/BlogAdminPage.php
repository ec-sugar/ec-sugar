<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog;

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
use App\Core\Plugin\Services\AdminForms;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginBlog\Models\BlogCategory;
use EcSugar\PluginBlog\Models\BlogPost;
use Illuminate\Database\Eloquent\Builder;

/**
 * 管理画面の「お知らせ・ブログ」（ADR 0051 の App の画面と、足す・直すの欄。ADR 0072）。
 * 一覧（すべて・公開中・予約・下書き。新しく直した順）・1 件（状態・URL・プレビューの URL など）・作る・直す（保存のボタン。自動の保存は無い）・
 * 公開する・予約する・取り下げる・消す（取り消せないので直前の再確認の後）。見るのは閲覧の段階から、作る・直す・操作は運用の段階から（核の経路が守る）。
 * 記事は店の人が書く文で、お客さんの個人情報は扱わない。
 * 有料の記事は、欄の「読める条件（鍵）」と「ここから有料（続きの本文）」で決める（1 件の画面に、どこから有料かを出す）
 */
final class BlogAdminPage implements AdminFormHandler, AdminPageHandler
{
    private const array FILTERS = ['all' => 'すべて', 'live' => '公開中', 'scheduled' => '予約', 'draft' => '下書き'];

    /** 「読める条件」の欄の長さ（鍵 10 個 × 97 文字 ＋ 改行に足りる長さ。数と形は BlogPosts が確かめる） */
    private const int ACCESS_KEYS_MAX = 1000;

    public function title(): string
    {
        return 'お知らせ・ブログ';
    }

    public function filters(): array
    {
        $counts = array_map(static fn (string $key): int => self::filtered($key)->count(), array_combine(array_keys(self::FILTERS), array_keys(self::FILTERS)));

        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], $counts[$key]), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('title', 'タイトル', CellType::Text),
            new AdminColumn('category', '種類', CellType::Text),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('published', '公開の日時', CellType::DateTime),
            new AdminColumn('updated', '更新した日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $posts = $query->orderByDesc('updated_at')->orderByDesc('id')->forPage($page, $perPage)->get();
        $names = self::categoryNames();

        return new AdminRows($posts->map(static fn (BlogPost $post): AdminRow => new AdminRow($post->id, [
            'title' => self::cut($post->title),
            'category' => $names[$post->category_id] ?? '—',
            'status' => self::status($post),
            'published' => $post->published_at,
            'updated' => $post->updated_at,
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $post = BlogPost::query()->find($id);

        if ($post === null) {
            return null;
        }

        $tenant = tenant();
        $publicUrl = $post->isLive() && $tenant instanceof Tenant ? StorefrontUrls::of($tenant, route('storefront.plugin.blog.pages.show', ['slug' => $post->slug], false)) : null;
        $preview = $tenant instanceof Tenant ? BlogPreviewLink::issue($tenant, $post->id) : null;
        $actions = [];

        if ($post->status === BlogPost::DRAFT) {
            $actions[] = new AdminAction('publish', '公開する', '今すぐショップの「お知らせ」に出します（一覧・サイトマップ・新着のお知らせのセクションにも出ます）。');

            if ($post->publish_at?->isFuture() === true) {
                $actions[] = new AdminAction('schedule', '予約する', self::local($post->publish_at).' にショップに出します（その時まで、ショップには出ません）。');
            }
        } else {
            $actions[] = new AdminAction('unpublish', '取り下げる', $post->isScheduled()
                ? '予約をやめて下書きに戻します。'
                : 'ショップから外して下書きに戻します（記事の URL を開くと「ページが見つかりません」になります）。');
        }

        $actions[] = new AdminAction('delete', '削除', 'この記事を削除します。削除すると元に戻せません（ショップからも消えます）。', danger: true);

        return new AdminRecord($post->title, [
            new AdminFact('状態', CellType::Status, self::status($post)),
            new AdminFact('種類', CellType::Text, self::categoryNames()[$post->category_id] ?? '—'),
            new AdminFact('ショップの URL', CellType::Text, self::url($publicUrl), copy: true),
            new AdminFact('プレビューの URL（'.BlogPreviewLink::MINUTES.' 分間だけ開けます）', CellType::Text, self::url($preview), copy: true),
            new AdminFact('公開の日時', CellType::DateTime, $post->published_at),
            new AdminFact('予約の日時（設定した値）', CellType::DateTime, $post->publish_at),
            new AdminFact('読める条件（鍵）', CellType::Text, BlogPaywall::keysOf($post) === [] ? null : self::cut(implode('、', BlogPaywall::keysOf($post)))),
            new AdminFact('有料の部分', CellType::Text, self::paidPart($post)),
            new AdminFact('抜粋', CellType::Text, $post->excerpt),
            new AdminFact('作った日時', CellType::DateTime, $post->created_at),
            new AdminFact('更新した日時', CellType::DateTime, $post->updated_at),
        ], [], $actions);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'publish' => self::posts()->publish($id, $actor),
            'schedule' => self::posts()->schedule($id, $actor),
            'unpublish' => self::posts()->unpublish($id, $actor),
            'delete' => self::posts()->delete($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    public function createLabel(): string
    {
        return '記事を書く';
    }

    public function fields(): array
    {
        $categories = BlogCategory::ordered();
        $options = $categories->mapWithKeys(static fn (BlogCategory $c): array => [$c->id => $c->name])->all();

        return [
            new AdminField('title', FieldType::Text, 'タイトル', required: true, max: BlogPosts::TITLE_MAX),
            new AdminField('slug', FieldType::Text, 'URL の末尾', help: '記事の URL「/news/〇〇」の〇〇の部分です。英小文字・数字・ハイフン（例: 2026-winter-sale）。ほかの記事と同じ名前は使えません。', required: true, max: BlogPosts::SLUG_MAX),
            new AdminField('category', FieldType::Choice, '種類', required: true, options: $options, default: $categories->first()?->id),
            new AdminField('new_category', FieldType::Text, '新しい種類', help: '名前を入れると、その種類を作ってこの記事に付けます（種類は '.BlogPosts::MAX_CATEGORIES.' 個まで。同じ名前があればそれを使います）。', max: BlogPosts::CATEGORY_MAX),
            new AdminField('excerpt', FieldType::Textarea, '抜粋', help: '記事の一覧や、検索結果・SNS での共有に表示する短い紹介文です。空なら本文のはじめを使います。', max: BlogPosts::EXCERPT_MAX),
            new AdminField('image', FieldType::Media, '一覧の写真', help: '記事の一覧のカードと、SNS で共有したときに表示される写真です。'),
            new AdminField('body', FieldType::Blocks, '本文', help: '商品の説明と同じく、段落・見出し・箇条書き・写真・表・注意書きを並べて書きます。'),
            new AdminField('access_keys', FieldType::Textarea, '読める条件（鍵）', help: '1 行に 1 つ。入れた鍵のどれかを持つお客さんだけが「ここから有料」より後ろを読めます（例: membership:standard）。空なら誰でも読めます。'.BlogPaywall::MAX_KEYS.' 個まで。', max: self::ACCESS_KEYS_MAX),
            new AdminField('paid_body', FieldType::Blocks, 'ここから有料（続きの本文）', help: '「読める条件」を持つお客さんにだけ出す本文です。持っていない人には、ここから後ろを送りません。空のままで「読める条件」を入れると、本文の全部が有料になります。'),
            new AdminField('publish_at', FieldType::DateTime, '予約の日時', help: '日時を入れて保存すると「予約する」を押せるようになります。その日時になると、ショップに公開されます。'),
        ];
    }

    public function values(string $id): ?array
    {
        $post = BlogPost::query()->find($id);

        return $post === null ? null : [
            'title' => $post->title,
            'slug' => $post->slug,
            'category' => $post->category_id,
            'new_category' => null,
            'excerpt' => $post->excerpt,
            'image' => $post->image_id,
            'body' => self::blocks($post->body),
            'access_keys' => BlogPaywall::keysOf($post) === [] ? null : implode("\n", BlogPaywall::keysOf($post)),
            'paid_body' => self::blocks($post->paid_body),
            'publish_at' => $post->publish_at?->utc()->format(AdminForms::DATETIME_FORMAT),
        ];
    }

    public function create(array $values, string $actor): string
    {
        return self::posts()->create($values, $actor);
    }

    public function update(string $id, array $values, string $actor): void
    {
        self::posts()->update($id, $values, $actor);
    }

    /**
     * 有料の分の様子（どこから有料になるかを 1 行で）
     */
    private static function paidPart(BlogPost $post): string
    {
        if (BlogPaywall::keysOf($post) === []) {
            return '無し（誰でも読めます）';
        }

        $blocks = count($post->paid_body);

        return $blocks === 0
            ? '本文のすべて（「ここから有料」が空なので、本文は読める条件を持つ人だけに表示します）'
            : "「ここから有料」より後ろの {$blocks} ブロック";
    }

    /**
     * ブロックの欄の今の値（係との間は確かめた並びの JSON の文字。空の並びは null）
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private static function blocks(array $blocks): ?string
    {
        return $blocks === [] ? null : json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 書く係（店の文脈の中で呼ばれた時に作る。登録の時には作らない）
     */
    private static function posts(): BlogPosts
    {
        return app(BlogPosts::class);
    }

    /**
     * @return Builder<BlogPost>
     */
    private static function filtered(string $filter): Builder
    {
        $now = CarbonImmutable::now();

        return match ($filter) {
            'live' => BlogPost::visible(BlogPost::query(), $now),
            'scheduled' => BlogPost::query()->where('status', BlogPost::PUBLISHED)->where('published_at', '>', $now),
            'draft' => BlogPost::query()->where('status', BlogPost::DRAFT),
            default => BlogPost::query(),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function categoryNames(): array
    {
        /** @var array<string, string> */
        return BlogCategory::query()->pluck('name', 'id')->all();
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function status(BlogPost $post): array
    {
        return match (true) {
            $post->isLive() => ['label' => '公開中', 'tone' => 'good'],
            $post->isScheduled() => ['label' => '予約', 'tone' => 'warn'],
            default => ['label' => '下書き', 'tone' => 'muted'],
        };
    }

    private static function local(CarbonImmutable $at): string
    {
        return $at->setTimezone(View\BlogReader::TIMEZONE)->format('Y/m/d H:i');
    }

    /**
     * 写す URL（切ると開けないので、画面の文の上限を超える長さなら出さない。店のドメインがとても長いときだけ）
     */
    private static function url(?string $url): ?string
    {
        return $url !== null && mb_strlen($url) <= CellType::TEXT_MAX ? $url : null;
    }

    /**
     * 画面の文の上限（CellType::TEXT_MAX 文字）に収める
     */
    private static function cut(string $text): string
    {
        return mb_strlen($text) > CellType::TEXT_MAX ? mb_substr($text, 0, CellType::TEXT_MAX - 1).'…' : $text;
    }
}
