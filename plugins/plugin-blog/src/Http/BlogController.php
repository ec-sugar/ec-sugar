<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog\Http;

use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Storefront\Seo\SeoArticle;
use App\Storefront\Seo\SeoBuilder;
use App\Storefront\Seo\SeoMeta;
use App\Storefront\View\StorefrontView;
use Carbon\CarbonImmutable;
use EcSugar\PluginBlog\BlogPreviewLink;
use EcSugar\PluginBlog\Models\BlogCategory;
use EcSugar\PluginBlog\Models\BlogPost;
use EcSugar\PluginBlog\View\BlogReader;
use EcSugar\PluginBlog\View\NewsList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ショップのお知らせ・ブログ（ADR 0072）。/news（一覧。?category=… で種類を絞り、?page=… で 20 件ずつ）・/news/{slug}（記事）・/news/feed（Atom）、
 * /p/blog/preview/{ID}（管理画面からの署名つきのプレビュー）・/p/blog/read/{ID}（ログインの後に記事へ戻る道）。
 * 出すのはショップに出ている記事だけ（公開で、公開の日時が今まで。BlogReader）。下書き・予約・取り下げた記事は、無いのと同じ「見つかりません」。
 * 画面はテーマの news・news-post の画面か、無ければ App の既定の画面（テーマの外枠の中）。検索と共有の見え方は核が作る（SeoBuilder::forAppPage）
 */
final class BlogController
{
    public function __construct(
        private readonly StorefrontView $view,
        private readonly BlogReader $reader,
        private readonly SeoBuilder $seo,
    ) {}

    public function index(Request $request): Response
    {
        $slug = $request->query('category');
        $category = null;

        if ($slug !== null) {
            $category = is_string($slug) && strlen($slug) <= 40 ? BlogCategory::query()->where('slug', $slug)->first() : null;

            if ($category === null) {
                return $this->missing();
            }
        }

        $page = $request->query('page', '1');

        if (! is_string($page) || ! ctype_digit($page) || strlen($page) > 4 || (int) $page < 1 || (int) $page > BlogReader::MAX_PAGE) {
            return $this->missing();
        }

        $page = (int) $page;
        ['items' => $items, 'total' => $total, 'lastPage' => $lastPage] = $this->reader->page($category, $page);

        // 1 ページ目は記事が無くても出す（「まだありません」）。2 ページ目からは、最後のページより先は無いのと同じ
        if ($page > $lastPage) {
            return $this->missing();
        }

        $link = static fn (int $to): string => route('storefront.plugin.blog.pages.index', array_filter(['category' => $category?->slug, 'page' => $to > 1 ? $to : null]), false);
        $categories = [['name' => 'すべて', 'url' => route('storefront.plugin.blog.pages.index', [], false), 'current' => $category === null]];

        foreach (BlogCategory::ordered() as $c) {
            $categories[] = ['name' => $c->name, 'url' => route('storefront.plugin.blog.pages.index', ['category' => $c->slug], false), 'current' => $c->id === $category?->id];
        }

        $list = new NewsList($items, $categories, $category?->name, $page, $lastPage, $total,
            $page > 1 ? $link($page - 1) : null, $page < $lastPage ? $link($page + 1) : null, route('storefront.plugin.blog.pages.feed', [], false));
        $title = $list->title().($page > 1 ? "（{$page} ページ目）" : '');
        $seo = $this->seo->forAppPage($title, null, url($link($page)),
            breadcrumbs: [['name' => 'お知らせ', 'url' => route('storefront.plugin.blog.pages.index', [], false)], ...($category === null ? [] : [['name' => $category->name, 'url' => $link(1)]])]);

        return $this->view->renderAppPage('news', 'blog::news', ['news' => $list], $seo);
    }

    public function show(string $slug): Response
    {
        $post = $this->reader->find($slug);

        if ($post === null || $post->published_at === null) {
            return $this->missing();
        }

        $view = $this->reader->post($post);
        $seo = $this->seo->forAppPage($post->title, $post->excerpt ?? $view->plainText, url($view->url), $post->image_id,
            new SeoArticle($post->published_at, $post->updated_at, $view->category === '' ? null : $view->category),
            [['name' => 'お知らせ', 'url' => route('storefront.plugin.blog.pages.index', [], false)], ['name' => $post->title, 'url' => $view->url]]);

        return $this->view->renderAppPage('news-post', 'blog::post', ['post' => $view], $seo);
    }

    /**
     * 新しい 20 件の Atom（RFC 4287）。本文は載せず、抜き書きか本文のはじめ（素の文字）だけ。準備中のショップでは出さない
     */
    public function feed(StorefrontAccessService $access): Response
    {
        if ($access->status() !== ShopStatus::Open) {
            return response('', 404, ['X-Robots-Tag' => StorefrontView::NO_INDEX]);
        }

        $shop = (string) tenant()?->getAttribute('name');
        $entries = $this->reader->feed();
        $updated = $entries === [] ? CarbonImmutable::now() : max(array_column($entries, 'updated'));
        $xml = static fn (string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $self = route('storefront.plugin.blog.pages.feed');
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="ja">',
            '<title>'.$xml("お知らせ — {$shop}").'</title>',
            '<id>'.$xml($self).'</id>',
            '<link rel="self" type="application/atom+xml" href="'.$xml($self).'"/>',
            '<link rel="alternate" type="text/html" href="'.$xml(route('storefront.plugin.blog.pages.index')).'"/>',
            '<updated>'.$updated->format(DATE_ATOM).'</updated>',
            '<author><name>'.$xml($shop).'</name></author>',
        ];

        foreach ($entries as $entry) {
            $lines[] = '<entry>';
            $lines[] = '<title>'.$xml($entry['title']).'</title>';
            $lines[] = '<id>'.$xml($entry['url']).'</id>';
            $lines[] = '<link rel="alternate" type="text/html" href="'.$xml($entry['url']).'"/>';
            $lines[] = '<published>'.$entry['published']->format(DATE_ATOM).'</published>';
            $lines[] = '<updated>'.$entry['updated']->format(DATE_ATOM).'</updated>';
            $lines[] = '<category term="'.$xml($entry['categorySlug']).'" label="'.$xml($entry['category']).'"/>';

            if ($entry['summary'] !== null) {
                $lines[] = '<summary type="text">'.$xml($entry['summary']).'</summary>';
            }

            $lines[] = '</entry>';
        }

        $lines[] = '</feed>';

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8', 'Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * 管理画面からのプレビュー（下書き・予約の記事も）。署名が違う・期限切れ・ほかの店・ほかの記事・無い記事は「見つかりません」
     * （どれで断ったかを見せない）。検索に載せず、どこにも残さず、URL を外へ渡さず、App のタグを差し込まない
     */
    public function preview(Request $request, string $postId): Response
    {
        $postId = strtolower($postId);
        $tenantId = (string) tenant()?->getTenantKey();
        $post = BlogPreviewLink::grants($tenantId, $postId, $request->query('expires'), $request->query('token'))
            ? BlogPost::query()->find($postId)
            : null;

        if ($post === null) {
            return $this->missing();
        }

        // 署名が「書いた本人」の証なので、有料の記事でも全部を見せる（切る前の見え方を確かめられる）
        return $this->view->renderAppPage('news-post', 'blog::post', ['post' => $this->reader->post($post, asAuthor: true)], SeoMeta::hidden((string) tenant()?->getAttribute('name')), preview: true);
    }

    /**
     * ログインの後に記事へ戻る道。会員のログインの戻り先は App の道筋だけなので、ここで記事の URL へ送る。
     * ショップに出ていない記事は「見つかりません」
     */
    public function read(string $postId): Response|RedirectResponse
    {
        $post = BlogPost::shown()->whereKey(strtolower($postId))->first(['slug']);

        return $post === null
            ? $this->missing()
            : redirect()->to(route('storefront.plugin.blog.pages.show', ['slug' => $post->slug], false));
    }

    private function missing(): Response
    {
        $response = $this->view->render('error', ['status' => 404], 404);
        $response->headers->set('X-Robots-Tag', StorefrontView::NO_INDEX);

        return $response;
    }
}
