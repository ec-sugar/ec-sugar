<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog\View;

use App\Core\Listing\Description\ProductDescription;
use App\Core\Media\Models\ShopMedia;
use App\Storefront\Catalog\DescriptionBlock;
use App\Storefront\Seo\SeoMeta;
use App\Storefront\View\ShopPhoto;
use Carbon\CarbonImmutable;
use EcSugar\PluginBlog\BlogPaywall;
use EcSugar\PluginBlog\Models\BlogCategory;
use EcSugar\PluginBlog\Models\BlogPost;
use Illuminate\Support\Collection;

/**
 * ショップに出す記事を読む（ADR 0072）。出すのはいつも BlogPost::visible（公開で、公開の日時が今まで）を通したものだけ
 * （プレビューの 1 件だけは呼ぶ側が署名を確かめてから post() に下書きを渡す）。
 * 渡すのは画面の形（PostCard・PostView）だけで、モデルは渡さない。
 *
 * 有料の記事の本文は、どこへ出すときも BlogPaywall を通す（一覧と区画の抜き書き・Atom の要約・検索と共有の説明も、
 * 無料の分からしか作らない）。切り方はこのクラスでは決めない
 */
final class BlogReader
{
    public const string TIMEZONE = 'Asia/Tokyo';

    /** 一覧の 1 ページの数 */
    public const int PER_PAGE = 20;

    /** 一覧のページ送りの上限（深すぎる要求で DB を引き回させない） */
    public const int MAX_PAGE = 500;

    /** Atom に載せる数 */
    public const int FEED_SIZE = 20;

    public function __construct(private readonly BlogPaywall $paywall) {}

    /**
     * 一覧の 1 ページ（新しい順。同じ日時は ID の順）
     *
     * @return array{items: list<PostCard>, total: int, lastPage: int}
     */
    public function page(?BlogCategory $category, int $page): array
    {
        $query = BlogPost::shown()->when($category !== null, static fn ($q) => $q->where('category_id', $category?->id));
        $total = (clone $query)->count();
        $posts = $query->orderByDesc('published_at')->orderByDesc('id')->forPage($page, self::PER_PAGE)->get();

        return ['items' => $this->cards($posts), 'total' => $total, 'lastPage' => max(1, (int) ceil($total / self::PER_PAGE))];
    }

    /**
     * 新しい $limit 件（区画と Atom）。種類の名前で絞るときは $categoryName（無い名前なら空）
     *
     * @return list<PostCard>
     */
    public function latest(int $limit, ?string $categoryName = null): array
    {
        $query = BlogPost::shown();

        if ($categoryName !== null) {
            $id = BlogCategory::query()->where('name', $categoryName)->value('id');

            if (! is_string($id)) {
                return [];
            }

            $query->where('category_id', $id);
        }

        return $this->cards($query->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get());
    }

    /**
     * Atom に載せる新しい 20 件（絶対の URL。要約は抜き書きか、本文のはじめの素の文字。本文そのものは載せない）
     *
     * @return list<array{title: string, url: string, published: CarbonImmutable, updated: CarbonImmutable, category: string, categorySlug: string, summary: ?string}>
     */
    public function feed(): array
    {
        $posts = BlogPost::shown()->orderByDesc('published_at')->orderByDesc('id')->limit(self::FEED_SIZE)->get();
        $categories = BlogCategory::query()->whereKey($posts->pluck('category_id')->unique()->all())->get()->keyBy('id');

        return $posts->map(static fn (BlogPost $post): array => [
            'title' => $post->title,
            'url' => route('storefront.plugin.blog.pages.show', ['slug' => $post->slug]),
            'published' => $post->published_at ?? $post->created_at,
            'updated' => $post->updated_at,
            'category' => $categories[$post->category_id]->name ?? '',
            'categorySlug' => $categories[$post->category_id]->slug ?? '',
            // 要約は店の人が書いた抜き書きか、**無料の分**の本文のはじめ（有料の分は読む機械にも出さない）
            'summary' => $post->excerpt ?? SeoMeta::excerpt(ProductDescription::plainText(BlogPaywall::free($post)), 200),
        ])->values()->all();
    }

    /**
     * ショップに出ている記事（URL の名前で）。出ていなければ null
     */
    public function find(string $slug): ?BlogPost
    {
        return BlogPost::shown()->where('slug', $slug)->first();
    }

    /**
     * 記事 1 つの画面の形（本文のブロックの写真は、ショップの写真にあるものだけ）。
     * 本文は BlogPaywall が「この人に送ってよい分」まで切ったもので、落とした分の文も写真の URL も PostView に入らない
     *
     * @param  bool  $asAuthor  管理画面から出した署名つきのプレビュー（書いた本人は有料の分も読める）
     */
    public function post(BlogPost $post, bool $asAuthor = false): PostView
    {
        $reading = $this->paywall->read($post, $asAuthor);
        $blocks = $reading->blocks;
        $ids = array_values(array_unique([...ProductDescription::mediaIds($blocks), ...($post->image_id === null ? [] : [$post->image_id])]));
        $photos = $this->photos($ids);
        $category = BlogCategory::query()->find($post->category_id);

        return new PostView(
            $post->title,
            route('storefront.plugin.blog.pages.show', ['slug' => $post->slug], false),
            $post->published_at,
            $category->name ?? '',
            $category->slug ?? '',
            $post->excerpt,
            $post->image_id === null ? null : ($photos[$post->image_id] ?? null),
            array_values(array_filter(array_map(static fn (array $block): ?DescriptionBlock => DescriptionBlock::from($block, $photos), $blocks))),
            // 検索と共有の説明は、読む人によらず無料の分だけから作る
            ProductDescription::plainText(BlogPaywall::free($post)),
            $post->updated_at,
            $reading->locked
                ? new PostPaywall($reading->keys, $reading->signedIn, $reading->hasFreePart, route('storefront.plugin.blog.read', ['postId' => $post->id], false))
                : null,
        );
    }

    /**
     * 一覧と区画の札（抜き書きは店の人が書いた文だけ。本文からは作らないので、有料の分が札から漏れない）
     *
     * @param  Collection<int, BlogPost>  $posts
     * @return list<PostCard>
     */
    private function cards(Collection $posts): array
    {
        $categories = BlogCategory::query()->whereKey($posts->pluck('category_id')->unique()->all())->get()->keyBy('id');
        $photos = $this->photos($posts->pluck('image_id')->filter()->unique()->values()->all());

        return $posts->map(static fn (BlogPost $post): PostCard => new PostCard(
            $post->title,
            route('storefront.plugin.blog.pages.show', ['slug' => $post->slug], false),
            $post->published_at ?? $post->created_at,
            $categories[$post->category_id]->name ?? '',
            $categories[$post->category_id]->slug ?? '',
            $post->excerpt,
            $post->image_id === null ? null : ($photos[$post->image_id] ?? null),
        ))->values()->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, ShopPhoto>
     */
    private function photos(array $ids): array
    {
        return $ids === [] ? [] : ShopMedia::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(static fn (ShopMedia $m): array => [$m->id => new ShopPhoto($m->id, $m->width, $m->height, $m->alt)])
            ->all();
    }
}
