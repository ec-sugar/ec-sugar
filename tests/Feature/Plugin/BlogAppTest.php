<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Media\Models\ShopMedia;
use App\Core\Plugin\Contracts\ShopPagesProvider;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PluginRoutes;
use App\Core\Plugin\Services\PluginStates;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use EcSugar\PluginBlog\BlogPosts;
use EcSugar\PluginBlog\Models\BlogCategory;
use EcSugar\PluginBlog\Models\BlogPost;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「お知らせ・ブログ」（ADR 0072）。管理画面の App の画面で書く・直す・公開・予約・取り下げ・消す（写真・ブロック・日時の欄は核の形）と、
 * ショップの /news（一覧・記事・Atom）・サイトマップ・区画「新着のお知らせ」・署名つきのプレビュー。
 * 下書き・予約の記事はどこにも出ない。予約は日時の境界で出る。店をまたいで読めない・書けない。文は HTML として出ない
 */
final class BlogAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BASE = '/api/tenants/blg/apps/blog/records';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->tenant('blg', 'こもれび雑貨店');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_an_unused_app_shows_nothing_in_the_admin_or_the_shop(): void
    {
        $this->enrolledOperator();
        $this->inside('blg', fn () => BlogPost::query()->create(['category_id' => BlogCategory::query()->value('id'), 'title' => '出ない記事', 'slug' => 'hidden', 'status' => 'published', 'published_at' => now()->subDay()]));

        $this->send('GET', '/api/tenants/blg/app-pages')->assertOk()->assertExactJson(['pages' => []]);
        $this->send('GET', self::BASE)->assertNotFound();
        $this->shop('/news')->assertNotFound();
        $this->shop('/news/hidden')->assertNotFound();
        $this->shop('/news/feed')->assertNotFound();
        $this->assertStringNotContainsString('/news', (string) $this->shop('/sitemap.xml')->assertOk()->getContent());

        $this->use('blg');
        $this->send('GET', '/api/tenants/blg/app-pages')->assertOk()->assertJsonFragment(['plugin' => 'blog', 'title' => 'お知らせ・ブログ']);
        $this->shop('/news/hidden')->assertOk()->assertSee('出ない記事');
        $this->assertStringContainsString('http://blg.localhost/news/hidden', (string) $this->shop('/sitemap.xml')->getContent());
    }

    public function test_writing_publishing_and_the_post_page_with_its_search_and_share_view(): void
    {
        $this->use('blg');
        [$owner] = $this->enrolledOperator();
        $photo = $this->photo('blg', '冬の店先');

        // 足す欄: 種類は「お知らせ」が先頭で既定。本文はブロックの欄（エディタの上限つき）、写真・日時の欄、有料の記事の 2 つ
        $form = $this->send('GET', self::BASE)->assertOk()->json('form');
        $this->assertSame('記事を書く', $form['label']);
        $this->assertSame(['title', 'slug', 'category', 'new_category', 'excerpt', 'image', 'body', 'access_keys', 'paid_body', 'publish_at'], array_column($form['fields'], 'key'));
        $this->assertSame(['text', 'text', 'choice', 'text', 'textarea', 'media', 'blocks', 'textarea', 'blocks', 'datetime'], array_column($form['fields'], 'type'));
        $this->assertSame('お知らせ', $form['fields'][2]['options'][0][1]);
        $this->assertSame(100, $form['fields'][6]['limits']['blocks']);
        $this->assertSame([], $form['values']['body']);

        $id = $this->create([
            'title' => '<b>冬の休み</b>のお知らせ',
            'slug' => 'winter-holiday',
            'excerpt' => '年末年始のお休みのご案内です。',
            'image' => strtoupper($photo),
            'body' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'お休みの日'],
                ['type' => 'paragraph', 'text' => "<script>alert(1)</script>\n<img src=x onerror=alert(2)>\n<a href=\"javascript:alert(3)\">押す</a>"],
                ['type' => 'image', 'media_id' => $photo, 'caption' => '店先'],
            ],
        ]);

        // 下書きはどこにも出ない
        $this->shop('/news')->assertOk()->assertSee('まだお知らせはありません');
        $this->shop('/news/winter-holiday')->assertNotFound();
        $this->assertStringNotContainsString('winter-holiday', (string) $this->shop('/sitemap.xml')->getContent());
        $this->assertStringNotContainsString('winter-holiday', (string) $this->shop('/news/feed')->assertOk()->getContent());
        $this->send('GET', self::BASE.'/'.$id)->assertOk()->assertJsonPath('record.facts.0.value.label', '下書き')
            ->assertJsonPath('form.values.body.1.text', "<script>alert(1)</script>\n<img src=x onerror=alert(2)>\n<a href=\"javascript:alert(3)\">押す</a>")
            ->assertJsonPath('form.values.image', $photo);

        $this->send('POST', self::BASE.'/'.$id.'/actions/publish')->assertOk()->assertJsonPath('record.facts.0.value.label', '公開中');

        // 記録は actor つきで、題・本文の文は入らない
        $events = $this->inside('blg', fn () => DomainEvent::query()->whereIn('type', ['blog.post_created', 'blog.post_published'])->orderBy('id')->get());
        $this->assertSame(['blog.post_created', 'blog.post_published'], $events->pluck('type')->all());
        $this->assertSame([$owner->actor(), $owner->actor()], $events->pluck('actor')->all());
        $this->assertStringNotContainsString('冬の休み', json_encode($events->pluck('payload')->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // 記事の画面: 題・説明・canonical・共有の見え方・構造化データ（Article とパンくず）は核の部品が出す
        $html = (string) $this->shop('/news/winter-holiday')->assertOk()->getContent();
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $this->assertSame('<b>冬の休み</b>のお知らせ | こもれび雑貨店', $document->querySelector('title')?->textContent);
        $this->assertSame('http://blg.localhost/news/winter-holiday', $document->querySelector('link[rel="canonical"]')?->getAttribute('href'));
        $this->assertSame('article', $document->querySelector('meta[property="og:type"]')?->getAttribute('content'));
        $this->assertSame('年末年始のお休みのご案内です。', $document->querySelector('meta[name="description"]')?->getAttribute('content'));
        $this->assertStringContainsString("/media/shop/{$photo}/large", (string) $document->querySelector('meta[property="og:image"]')?->getAttribute('content'));
        $data = array_map(static fn ($s): array => json_decode((string) $s->textContent, true, 16, JSON_THROW_ON_ERROR), iterator_to_array($document->querySelectorAll('script[type="application/ld+json"]')));
        $article = array_values(array_filter($data, static fn (array $d): bool => $d['@type'] === 'Article'))[0] ?? null;
        $this->assertNotNull($article);
        $this->assertSame('<b>冬の休み</b>のお知らせ', $article['headline']);
        $this->assertSame('http://blg.localhost/news/winter-holiday', $article['mainEntityOfPage']);
        $this->assertSame('お知らせ', $article['articleSection']);
        $this->assertContains('BreadcrumbList', array_column($data, '@type'));

        // 本文は文字として出す（スクリプト・イベント属性・javascript: のリンクは要素にならない）
        $body = $document->querySelector('[data-test="news-post-body"]');
        $this->assertNotNull($body);
        $this->assertNull($body->querySelector('script, a, [onerror], b'));
        $this->assertSame("<script>alert(1)</script>\n<img src=x onerror=alert(2)>\n<a href=\"javascript:alert(3)\">押す</a>", $body->querySelector('[data-block="paragraph"]')?->textContent);
        $this->assertSame(1, $body->querySelectorAll('img')->length);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertNull($document->querySelector('[data-test="news-post-title"] b'));

        // 一覧・サイトマップ・Atom に出る
        $this->shop('/news')->assertOk()->assertSee('winter-holiday')->assertSee('年末年始のお休みのご案内です。');
        $this->assertStringContainsString('<loc>http://blg.localhost/news/winter-holiday</loc>', (string) $this->shop('/sitemap.xml')->getContent());
        $feed = $this->shop('/news/feed')->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8');
        $atom = simplexml_load_string((string) $feed->getContent());
        $this->assertNotFalse($atom);
        $this->assertSame('<b>冬の休み</b>のお知らせ', (string) $atom->entry[0]->title);
        $this->assertSame('http://blg.localhost/news/winter-holiday', (string) $atom->entry[0]->id);

        // 取り下げると、どこからも消える
        $this->send('POST', self::BASE.'/'.$id.'/actions/unpublish')->assertOk()->assertJsonPath('record.facts.0.value.label', '下書き');
        $this->shop('/news/winter-holiday')->assertNotFound();
        $this->assertStringNotContainsString('winter-holiday', (string) $this->shop('/sitemap.xml')->getContent());
        $this->assertStringNotContainsString('winter-holiday', (string) $this->shop('/news/feed')->getContent());
        $this->shop('/news')->assertSee('まだお知らせはありません');
    }

    public function test_values_are_checked_by_the_core_and_the_app(): void
    {
        $this->use('blg');
        $this->tenant('blh');
        $this->use('blh');
        $this->enrolledOperator();
        $this->create(['title' => '最初の記事', 'slug' => 'first']);
        $foreign = $this->photo('blh', 'ほかの店の写真');

        $bad = [
            // URL の名前の形・予約語・同じ店の重なり
            [['slug' => 'Winter'], 'values.slug'],
            [['slug' => 'winter holiday'], 'values.slug'],
            [['slug' => '-winter'], 'values.slug'],
            [['slug' => 'winter--sale'], 'values.slug'],
            [['slug' => 'ふゆ'], 'values.slug'],
            [['slug' => str_repeat('a', 61)], 'values.slug'],
            [['slug' => 'feed'], 'values.slug'],
            [['slug' => 'first'], 'values.slug'],
            // 題・抜き書きの長さ（核の欄の上限）
            [['title' => str_repeat('あ', 101)], 'values.title'],
            [['title' => "1 行目\n2 行目"], 'values.title'],
            [['excerpt' => str_repeat('あ', 201)], 'values.excerpt'],
            // 本文は核のブロックの決まり（知らない種類・HTML の種類・向きを変える印・形の違う並び）
            [['body' => [['type' => 'html', 'html' => '<script>alert(1)</script>']]], 'values.body.0'],
            [['body' => [['type' => 'paragraph', 'text' => "見えない\u{202E}文字"]]], 'values.body.0.text'],
            [['body' => 'not-a-list'], 'values.body'],
            // 写真は今の店のショップの写真だけ（ほかの店の写真・形の違う ID）
            [['image' => $foreign], 'values.image'],
            [['image' => '../../etc/passwd'], 'values.image'],
            [['body' => [['type' => 'image', 'media_id' => $foreign, 'caption' => null]]], 'values.body'],
            // 日時の形（時差の印が要る・決まった範囲）
            [['publish_at' => '2026-11-10 10:00'], 'values.publish_at'],
            [['publish_at' => 'tomorrow'], 'values.publish_at'],
            [['publish_at' => '1999-12-31T23:59:00Z'], 'values.publish_at'],
            // 知らない種類・知らない欄
            [['category' => '01JBQ0000000000000000000ZZ'], 'values.category'],
            [['status' => 'published'], 'values'],
        ];

        foreach ($bad as [$values, $error]) {
            $this->send('POST', self::BASE, ['values' => $values + ['title' => 'タイトル', 'slug' => 'second']])
                ->assertUnprocessable()->assertJsonValidationErrors([$error]);
        }

        $this->assertSame(1, $this->inside('blg', fn () => BlogPost::query()->count()));

        // ほかの店とは URL の名前が重なってよい
        $this->send('POST', '/api/tenants/blh/apps/blog/records', ['values' => ['title' => 'ほかの店の最初', 'slug' => 'first']])->assertCreated();

        // 日時は時差を UTC に直して分までで持つ
        $id = $this->create(['title' => '予約の記事', 'slug' => 'later', 'publish_at' => '2030-01-02T09:30:45+09:00']);
        $this->send('GET', self::BASE.'/'.$id)->assertOk()->assertJsonPath('form.values.publish_at', '2030-01-02T00:30:00Z');

        // 記事の数の上限（App の決まり。誤りは欄の全体に）
        $this->inside('blg', function (): void {
            $category = BlogCategory::query()->value('id');
            $rows = [];

            for ($i = 3; $i <= BlogPosts::MAX_POSTS; $i++) {
                $rows[] = ['id' => strtolower((string) Str::ulid()), 'category_id' => $category, 'title' => "記事 {$i}", 'slug' => "post-{$i}", 'body' => '[]', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                BlogPost::query()->insert($chunk);
            }
        });
        $this->send('POST', self::BASE, ['values' => ['title' => 'あふれる', 'slug' => 'overflow']])->assertUnprocessable()->assertJsonValidationErrors(['values']);
    }

    public function test_a_scheduled_post_appears_exactly_at_its_time_and_not_before(): void
    {
        $this->use('blg');
        $this->enrolledOperator();
        $now = CarbonImmutable::parse('2026-11-05T03:00:00Z');
        $this->travelTo($now);
        $this->publishHome([['type' => 'app_blog', 'pattern' => 'list', 'values' => ['heading' => '新着', 'count' => 'n3']]]);

        $id = $this->create(['title' => '春の新作', 'slug' => 'spring', 'publish_at' => '2026-11-05T13:00:00+09:00']);
        // 予約の日時が過ぎた記事・入っていない記事は予約できない
        $past = $this->create(['title' => '過ぎた予約', 'slug' => 'past', 'publish_at' => '2026-11-05T02:00:00Z']);
        $this->send('POST', self::BASE.'/'.$past.'/actions/schedule')->assertNotFound();
        $this->send('POST', self::BASE.'/'.$id.'/actions/schedule')->assertOk()->assertJsonPath('record.facts.0.value.label', '予約');
        $this->send('GET', self::BASE.'?filter=scheduled')->assertOk()->assertJsonPath('rows.0.id', $id)->assertJsonCount(1, 'rows');

        // 1 秒前まではどこにも出ない（一覧・記事・サイトマップ・Atom・区画）
        $this->travelTo($now->addHour()->subSecond());
        $this->assertHidden('spring');

        // 予約を直すと、出る日時も動く（30 分あと）
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['publish_at' => '2026-11-05T04:30:00Z']])->assertOk();
        $this->travelTo($now->addHour());
        $this->assertHidden('spring');
        // 予約中の記事の予約の日時を空にはできない（取り下げてから）
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['publish_at' => null]])->assertUnprocessable()->assertJsonValidationErrors(['values.publish_at']);

        // ちょうどの時刻から出る
        $this->travelTo(CarbonImmutable::parse('2026-11-05T04:30:00Z'));
        $this->shop('/news/spring')->assertOk()->assertSee('春の新作');
        $this->shop('/news')->assertSee('/news/spring');
        $this->assertStringContainsString('/news/spring', (string) $this->shop('/sitemap.xml')->getContent());
        $this->assertStringContainsString('/news/spring', (string) $this->shop('/news/feed')->getContent());
        $section = HTMLDocument::createFromString((string) $this->shop('/')->getContent(), LIBXML_NOERROR)->querySelector('[data-test="news-section"]');
        $this->assertNotNull($section);
        $this->assertSame('春の新作', trim((string) $section->querySelector('.sugar-news__title')?->textContent));
        $this->send('GET', self::BASE.'/'.$id)->assertOk()->assertJsonPath('record.facts.0.value.label', '公開中');
    }

    public function test_the_news_section_lists_only_shown_posts_and_checks_the_category_name(): void
    {
        $this->use('blg');
        $this->enrolledOperator();

        // 記事が無い間は、ショップに区画を出さない
        $this->publishHome([['type' => 'app_blog', 'pattern' => 'cards', 'values' => ['heading' => 'お知らせ', 'count' => 'n3']]]);
        $this->assertNull(HTMLDocument::createFromString((string) $this->shop('/')->getContent(), LIBXML_NOERROR)->querySelector('[data-test="news-section"]'));

        foreach (['one', 'two', 'three', 'four'] as $i => $slug) {
            $this->placePost($slug, "記事 {$slug}", now()->subMinutes(10 - $i));
        }
        $this->placePost('draft-one', '下書きの記事', null);
        $this->placePost('future-one', '予約の記事', now()->addDay());

        $section = HTMLDocument::createFromString((string) $this->shop('/')->getContent(), LIBXML_NOERROR)->querySelector('[data-test="news-section"]');
        $this->assertNotNull($section);
        $this->assertSame(['記事 four', '記事 three', '記事 two'], array_map(static fn ($n): string => trim($n->textContent), iterator_to_array($section->querySelectorAll('.sugar-news__title'))));

        // 種類で絞る（名前は今ある種類だけ。無い名前は保存で断る）
        $blog = $this->inside('blg', fn (): string => (string) BlogCategory::query()->where('slug', 'blog')->value('id'));
        $this->inside('blg', fn () => BlogPost::query()->where('slug', 'one')->update(['category_id' => $blog]));
        $page = $this->send('GET', '/api/tenants/blg/themes/default/pages/home')->assertOk();
        $sections = $page->json('sections');
        $at = count($sections) - 1;
        $sections[$at]['values']['category'] = '無い種類';
        $this->send('PUT', '/api/tenants/blg/themes/default/pages/home/draft', ['version_id' => $page->json('version.id'), 'revision' => (int) $page->json('version.revision'), 'sections' => $sections])
            ->assertUnprocessable()->assertJsonValidationErrors(["sections.{$at}.values.category"]);
        $sections[$at]['values']['category'] = 'ブログ';
        $this->publishHome($sections, replace: true);
        $section = HTMLDocument::createFromString((string) $this->shop('/')->getContent(), LIBXML_NOERROR)->querySelector('[data-test="news-section"]');
        $this->assertSame(['記事 one'], array_map(static fn ($n): string => trim($n->textContent), iterator_to_array($section?->querySelectorAll('.sugar-news__title') ?? [])));
    }

    public function test_the_list_pages_by_twenty_and_filters_by_category(): void
    {
        $this->use('blg');
        $this->enrolledOperator();

        for ($i = 1; $i <= 21; $i++) {
            $this->placePost("post-{$i}", "記事 {$i}", now()->subMinutes(100 - $i));
        }
        // 管理画面の一覧は 21 件（ショップは 20 件ずつ）
        $this->send('GET', self::BASE)->assertOk()->assertJsonPath('meta.total', 21);

        $first = HTMLDocument::createFromString((string) $this->shop('/news')->assertOk()->getContent(), LIBXML_NOERROR);
        $this->assertSame(20, $first->querySelectorAll('[data-test="news-item"]')->length);
        $this->assertSame('記事 21', trim((string) $first->querySelector('.sugar-news__title')?->textContent));
        $this->assertSame('/news?page=2', $first->querySelector('a[rel="next"]')?->getAttribute('href'));
        $this->assertNull($first->querySelector('a[rel="prev"]'));
        $this->assertSame('http://blg.localhost/news', $first->querySelector('link[rel="canonical"]')?->getAttribute('href'));
        $this->assertSame('/news/feed', $first->querySelector('link[rel="alternate"][type="application/atom+xml"]')?->getAttribute('href'));

        $second = HTMLDocument::createFromString((string) $this->shop('/news?page=2')->assertOk()->getContent(), LIBXML_NOERROR);
        $this->assertSame(1, $second->querySelectorAll('[data-test="news-item"]')->length);
        $this->assertSame('記事 1', trim((string) $second->querySelector('.sugar-news__title')?->textContent));
        $this->assertNull($second->querySelector('a[rel="next"]'));
        $this->assertSame('http://blg.localhost/news?page=2', $second->querySelector('link[rel="canonical"]')?->getAttribute('href'));

        foreach (['/news?page=3', '/news?page=0', '/news?page=abc', '/news?page[]=1', '/news?category=nope', '/news?category[]=news'] as $path) {
            $this->shop($path)->assertNotFound();
        }

        // 種類で絞る（ブログの記事だけ）。新しい種類は欄から足し、10 個まで
        $blog = $this->inside('blg', fn (): string => (string) BlogCategory::query()->where('slug', 'blog')->value('id'));
        $this->inside('blg', fn () => BlogPost::query()->whereIn('slug', ['post-3', 'post-5'])->update(['category_id' => $blog]));
        $filtered = HTMLDocument::createFromString((string) $this->shop('/news?category=blog')->assertOk()->getContent(), LIBXML_NOERROR);
        $this->assertSame(['記事 5', '記事 3'], array_map(static fn ($n): string => trim($n->textContent), iterator_to_array($filtered->querySelectorAll('.sugar-news__title'))));
        $this->assertSame('ブログ', $filtered->querySelector('[aria-current="page"]')?->textContent);

        $id = $this->create(['title' => 'セールの記事', 'slug' => 'sale', 'new_category' => 'セール']);
        $this->assertSame(['セール', 'category-3'], $this->inside('blg', fn (): array => [BlogCategory::query()->findOrFail(BlogPost::query()->findOrFail($id)->category_id)->name, BlogCategory::query()->where('name', 'セール')->value('slug')]));
        // 同じ名前なら今の種類を使う
        $again = $this->create(['title' => 'セールの記事 2', 'slug' => 'sale-2', 'new_category' => 'セール']);
        $this->assertSame(3, $this->inside('blg', fn (): int => BlogCategory::query()->count()));

        for ($n = 4; $n <= BlogPosts::MAX_CATEGORIES; $n++) {
            $this->send('PUT', self::BASE.'/'.$again, ['values' => ['new_category' => "種類 {$n}"]])->assertOk();
        }

        $this->send('PUT', self::BASE.'/'.$again, ['values' => ['new_category' => 'あふれる種類']])->assertUnprocessable()->assertJsonValidationErrors(['values.new_category']);
        $this->assertSame(BlogPosts::MAX_CATEGORIES, $this->inside('blg', fn (): int => BlogCategory::query()->count()));
    }

    public function test_the_preview_link_opens_a_draft_only_with_its_signature_in_its_shop_for_a_while(): void
    {
        $this->use('blg');
        $this->tenant('blh');
        $this->use('blh');
        $this->enrolledOperator();
        $id = $this->create(['title' => '下書きの見本', 'slug' => 'draft-sample', 'body' => [['type' => 'paragraph', 'text' => 'まだ出さない本文']]]);
        $other = $this->create(['title' => 'ほかの下書き', 'slug' => 'other-draft']);

        $facts = $this->send('GET', self::BASE.'/'.$id)->assertOk()->collect('record.facts')->keyBy('label');
        $preview = $facts['プレビューの URL（30 分間だけ開けます）'] ?? null;
        $this->assertIsArray($preview);
        $this->assertTrue($preview['copy']);
        $url = (string) $preview['value'];
        $this->assertStringStartsWith("http://blg.localhost/p/blog/preview/{$id}?expires=", $url);
        // 下書きにはショップの URL は無い
        $this->assertNull($facts['ショップの URL']['value'] ?? null);

        // 開ける: 下書きでも中身が出る。検索に載せず・残さず・送り元を渡さない
        $response = $this->shopUrl($url)->assertOk()->assertSee('下書きの見本')->assertSee('まだ出さない本文');
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertNull(HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR)->querySelector('link[rel="canonical"]'));

        // 署名を書き換えた・ほかの記事へ付け替えた・ほかの店のショップで開いた・期限が過ぎた、はどれも「見つかりません」
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = (string) $query['token'];
        $expires = (string) $query['expires'];
        $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $this->shopUrl("http://blg.localhost/p/blog/preview/{$id}?expires={$expires}&token={$tampered}")->assertNotFound()->assertDontSee('まだ出さない本文');
        $this->shopUrl("http://blg.localhost/p/blog/preview/{$id}?expires=".((int) $expires + 3600)."&token={$token}")->assertNotFound();
        $this->shopUrl("http://blg.localhost/p/blog/preview/{$other}?expires={$expires}&token={$token}")->assertNotFound();
        $this->shopUrl("http://blg.localhost/p/blog/preview/{$id}")->assertNotFound();
        $this->shopUrl(str_replace('blg.localhost', 'blh.localhost', $url))->assertNotFound()->assertDontSee('まだ出さない本文');
        $this->travel(31)->minutes();
        $this->shopUrl($url)->assertNotFound()->assertDontSee('まだ出さない本文');
    }

    public function test_viewers_only_read_another_shop_cannot_reach_and_deleting_needs_the_recent_confirmation(): void
    {
        $this->use('blg');
        $this->tenant('blh');
        $this->use('blh');
        [$owner, $key] = $this->enrolledOperator();
        $id = $this->create(['title' => 'A の店の記事', 'slug' => 'shop-a']);
        $this->send('POST', self::BASE.'/'.$id.'/actions/publish')->assertOk();

        // ほかの店の経路からは無いのと同じ（読む・直す・操作）。ほかの店のショップにも出ない
        $other = '/api/tenants/blh/apps/blog/records';
        $this->send('GET', $other)->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', "{$other}/{$id}")->assertNotFound();
        $this->send('PUT', "{$other}/{$id}", ['values' => ['title' => '書き換え']])->assertNotFound();
        $this->send('POST', "{$other}/{$id}/actions/unpublish")->assertNotFound();
        $this->shopUrl('http://blh.localhost/news/shop-a')->assertNotFound();
        $this->shopUrl('http://blh.localhost/news')->assertOk()->assertDontSee('A の店の記事');
        $this->shop('/news/shop-a')->assertOk();

        // 消すのは取り消せないので、直前の再確認の前は 423
        $this->send('POST', self::BASE.'/'.$id.'/actions/delete')->assertStatus(423);
        $kept = $this->create(['title' => '残す記事', 'slug' => 'kept']);
        $this->reconfirm($owner, $key);
        $this->send('POST', self::BASE.'/'.$id.'/actions/delete')->assertOk()->assertExactJson(['record' => null, 'form' => null]);
        $this->shop('/news/shop-a')->assertNotFound();
        $this->assertSame(1, $this->inside('blg', fn () => DomainEvent::query()->where('type', 'blog.post_deleted')->where('aggregate_id', $id)->count()));

        // 閲覧の段階の人は見られるが、書く・直す・公開する・消すはできない
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'blg', 'read', 'test');
        $this->send('GET', self::BASE.'/'.$kept)->assertOk();
        $this->send('POST', self::BASE, ['values' => ['title' => 'x', 'slug' => 'x']])->assertForbidden();
        $this->send('PUT', self::BASE.'/'.$kept, ['values' => ['title' => 'x']])->assertForbidden();
        $this->send('POST', self::BASE.'/'.$kept.'/actions/publish')->assertForbidden();
        $this->assertSame(BlogPost::DRAFT, $this->inside('blg', fn () => BlogPost::query()->findOrFail($kept)->status));
    }

    public function test_the_shop_pages_prefix_cannot_take_a_core_path_or_another_apps_prefix(): void
    {
        $registry = app(PluginRegistry::class);

        foreach (['products', 'p', 'pages', 'account', 'sitemap', 'news', 'News', 'a/b', ''] as $prefix) {
            try {
                $registry->register(new PluginDefinition(name: 'prefix-test', title: '頭の見本', description: '見本', pluginApi: 1, shopPages: self::pages($prefix)));
                $this->fail("{$prefix} を受け付けた");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('ショップのページの頭', $e->getMessage());
            }
        }

        // 核のショップの経路の頭は、どれも使えない言葉の一覧に入っている（経路を足したら CORE_PREFIXES にも足す）
        $reserved = PluginRoutes::reservedPrefixes();
        $news = app(PluginRegistry::class)->get('blog')->shopPages?->prefix();
        $routes = array_filter(app('router')->getRoutes()->getRoutes(), static fn (Route $route): bool => ! $route->isFallback && in_array(InitializeTenancyByDomain::class, app('router')->gatherRouteMiddleware($route), true));
        $this->assertNotSame([], $routes);

        foreach ($routes as $route) {
            $head = explode('/', ltrim($route->uri(), '/'))[0];
            $this->assertTrue($head === '' || $head === $news || in_array($head, $reserved, true), "{$route->uri()} の頭 {$head} が CORE_PREFIXES に無い");
        }
    }

    public function test_the_sitemap_takes_only_this_shops_urls_under_the_apps_prefix(): void
    {
        // App が返した URL のうち、このショップの頭の下だけを載せる（ほかのホスト・ほかの道筋・頭に似せた道筋は捨てる）。落ちる App があってもサイトマップは出る
        app(PluginRegistry::class)->register(new PluginDefinition(name: 'sitemap-test', title: '見本', description: '見本', pluginApi: 1, shopPages: self::pages('stories', [
            ['http://blg.localhost/stories/ok', null],
            ['http://evil.example/stories/x', null],
            ['http://blg.localhost/products/x', null],
            ['http://blg.localhost/storiesfake', null],
            ['http://blg.localhost/stories?page=2', null],
        ])));
        app(PluginRegistry::class)->register(new PluginDefinition(name: 'sitemap-broken', title: '見本', description: '見本', pluginApi: 1, shopPages: self::pages('broken', null)));
        $this->inside('blg', function (): void {
            app(PluginStates::class)->set('sitemap-test', true, 'test');
            app(PluginStates::class)->set('sitemap-broken', true, 'test');
        });

        $xml = (string) $this->shop('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>http://blg.localhost/stories/ok</loc>', $xml);
        $this->assertStringContainsString('<loc>http://blg.localhost/stories?page=2</loc>', $xml);
        $this->assertStringNotContainsString('evil.example', $xml);
        $this->assertStringNotContainsString('/products/x', $xml);
        $this->assertStringNotContainsString('storiesfake', $xml);
    }

    /**
     * @param  list<array{0: string, 1: null}>|null  $sitemap  null なら落ちる App
     */
    private static function pages(string $prefix, ?array $sitemap = []): ShopPagesProvider
    {
        return new class($prefix, $sitemap) implements ShopPagesProvider
        {
            /**
             * @param  list<array{0: string, 1: null}>|null  $sitemap
             */
            public function __construct(private readonly string $prefix, private readonly ?array $sitemap) {}

            public function prefix(): string
            {
                return $this->prefix;
            }

            public function routes(): void {}

            public function sitemap(int $limit): array
            {
                return $this->sitemap ?? throw new \RuntimeException('落ちる App');
            }
        };
    }

    private function assertHidden(string $slug): void
    {
        $this->shop("/news/{$slug}")->assertNotFound();
        $this->assertStringNotContainsString("/news/{$slug}", (string) $this->shop('/news')->getContent());
        $this->assertStringNotContainsString("/news/{$slug}", (string) $this->shop('/sitemap.xml')->getContent());
        $this->assertStringNotContainsString("/news/{$slug}", (string) $this->shop('/news/feed')->getContent());
        $this->assertNull(HTMLDocument::createFromString((string) $this->shop('/')->getContent(), LIBXML_NOERROR)->querySelector('[data-test="news-section"]'));
    }

    /**
     * 店の DB に直に記事を置く（公開の日時が null なら下書き）
     */
    private function placePost(string $slug, string $title, ?\DateTimeInterface $publishedAt): void
    {
        $this->inside('blg', fn () => BlogPost::query()->create([
            'category_id' => BlogCategory::query()->where('slug', 'news')->value('id'),
            'title' => $title,
            'slug' => $slug,
            'status' => $publishedAt === null ? BlogPost::DRAFT : BlogPost::PUBLISHED,
            'published_at' => $publishedAt,
        ]));
    }

    private function use(string $shop): void
    {
        $this->inside($shop, fn () => app(PluginStates::class)->set('blog', true, 'test'));
    }

    private function photo(string $shop, string $alt): string
    {
        return $this->inside($shop, fn (): string => ShopMedia::query()->create(['alt' => $alt, 'width' => 1200, 'height' => 800, 'checksum' => str_repeat('a', 64), 'bytes' => 1000])->id);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(array $values): string
    {
        $id = $this->send('POST', self::BASE, ['values' => $values])->assertCreated()->json('id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * トップの今の並びの後ろに足して（$replace なら置き換えて）公開する
     *
     * @param  list<array<string, mixed>>  $add
     */
    private function publishHome(array $add, bool $replace = false): void
    {
        $page = $this->send('GET', '/api/tenants/blg/themes/default/pages/home')->assertOk();
        $saved = $this->send('PUT', '/api/tenants/blg/themes/default/pages/home/draft', [
            'version_id' => $page->json('version.id'), 'revision' => (int) ($page->json('version.revision') ?? 0),
            'sections' => $replace ? $add : [...$page->json('sections'), ...$add],
        ])->assertOk();
        $this->send('POST', '/api/tenants/blg/themes/default/pages/home/publish', ['version_id' => $saved->json('version.id'), 'revision' => $saved->json('version.revision')])->assertOk();
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $path): TestResponse
    {
        return $this->shopUrl("http://blg.localhost{$path}");
    }

    /**
     * @return TestResponse<Response>
     */
    private function shopUrl(string $url): TestResponse
    {
        $response = $this->get($url);
        tenancy()->end();

        return $response;
    }
}
