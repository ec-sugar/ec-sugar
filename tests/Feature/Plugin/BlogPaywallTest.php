<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Media\Models\ShopMedia;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Plugin\Contracts\ContentAccessProvider;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use EcSugar\PluginBlog\BlogPaywall;
use EcSugar\PluginBlog\BlogPreviewLink;
use EcSugar\PluginBlog\BlogSections;
use EcSugar\PluginBlog\Models\BlogCategory;
use EcSugar\PluginBlog\Models\BlogPost;
use EcSugar\PluginBlog\View\NewsSection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 有料のコンテンツ。App「お知らせ・ブログ」の記事に「読める条件（鍵）」と「ここから有料」の区切りを付けたとき、
 * **権利の無い人に有料の本文を 1 バイトも送らない**ことを出口ごとに確かめる（記事の画面・一覧の抜き書き・新着のお知らせの区画・
 * サイトマップ・Atom・プレビュー）。無料の分は誰にでも出す。鍵の形と数は保存の時に断る
 */
final class BlogPaywallTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BASE = '/api/tenants/bpw/apps/blog/records';

    /** 無料の分と有料の分の文（レスポンスに入っているかを、見間違えない文字で調べる） */
    private const string FREE = '冒頭は無料です FREEPART';

    private const string PAID = 'ここから先の本文 PAIDONLY';

    private ?string $member = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->configureAdminHost();
        $this->tenant('bpw', '読み物の店');
        $this->use('bpw');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_paid_blocks_never_reach_a_reader_without_a_key(): void
    {
        $photo = $this->photo('bpw');
        $id = $this->place('bpw', 'first-half-free', [$this->paragraph(self::FREE)], [$this->paragraph(self::PAID), ['type' => 'image', 'media_id' => $photo, 'caption' => '有料の写真 PAIDPHOTO']], ['membership:standard']);

        // ログインしていない人: 無料の分は出て、有料の分は文も写真も出ない（案内だけ）
        $guest = $this->visit('bpw', '/news/first-half-free')->assertOk()->assertSee('FREEPART')
            ->assertDontSee('PAIDONLY')->assertDontSee('PAIDPHOTO')->assertSee('ここから先は、お買い上げ', false);
        $this->assertLacksPaid($guest);
        $this->assertStringNotContainsString($photo, (string) $guest->getContent(), '有料の写真の ID が出ている');
        // 会員でない人にはログインの案内を出す（手間は増やさない。入口は無料の分）
        $this->assertStringContainsString('/account/login?return=%2Fp%2Fblog%2Fread%2F', (string) $guest->getContent());

        // ログインの案内から入ると、読みたかった記事へ戻る（会員のログインの戻り先は App の道筋だけ受ける）
        $this->visit('bpw', "/account/login?return=/p/blog/read/{$id}")->assertOk();
        $this->login('bpw', 'hanako@example.com', back: "/p/blog/read/{$id}");

        // 会員になっても、鍵を持たなければ同じ（答える App が 1 つも無い＝誰も権利を持たない）
        $this->assertLacksPaid($this->visit('bpw', '/news/first-half-free')->assertOk()->assertSee('FREEPART')->assertDontSee('PAIDONLY'));

        // 鍵を持つ人には全部出す（売る側の App が答える）
        $this->sell('note-sample', [$this->account('bpw', 'hanako@example.com') => ['membership:standard']]);
        $this->enable('bpw', 'note-sample');
        $this->visit('bpw', '/news/first-half-free')->assertOk()->assertSee('FREEPART')->assertSee('PAIDONLY')
            ->assertSee('PAIDPHOTO')->assertDontSee('ここから先は、お買い上げ', false);

        // ほかの鍵しか持っていない人には出さない（1 つでも持っていれば読める＝持っていなければ読めない）
        $this->login('bpw', 'taro@example.com');
        $this->sell('other-sample', [$this->account('bpw', 'taro@example.com') => ['membership:gold']]);
        $this->enable('bpw', 'other-sample');
        $this->assertLacksPaid($this->visit('bpw', '/news/first-half-free')->assertOk()->assertDontSee('PAIDONLY'));
    }

    public function test_a_post_without_a_cut_is_whole_for_everyone_or_paid_for_everyone(): void
    {
        // 読める条件の無い記事は、今までどおり誰でも全部読める
        $this->place('bpw', 'open-post', [$this->paragraph(self::FREE)]);
        $this->visit('bpw', '/news/open-post')->assertOk()->assertSee('FREEPART')->assertDontSee('ここから先は、お買い上げ', false);

        // 読める条件があって「ここから有料」が空なら、本文の全部が有料（条件を付けたのに全部読めてしまう方が事故）
        $this->place('bpw', 'all-paid', [$this->paragraph(self::PAID)], [], ['post:allpaid']);
        $all = $this->visit('bpw', '/news/all-paid')->assertOk()->assertDontSee('PAIDONLY')
            ->assertSee('記事 all-paid')->assertSee('この記事は、お買い上げ', false);
        $this->assertLacksPaid($all);

        // 鍵を持てば読める
        $this->login('bpw', 'hanako@example.com');
        $this->sell('note-sample', [$this->account('bpw', 'hanako@example.com') => ['post:allpaid']]);
        $this->enable('bpw', 'note-sample');
        $this->visit('bpw', '/news/all-paid')->assertOk()->assertSee('PAIDONLY');

        // 条件の無い「ここから有料」は DB も断る（誰にも出せない行を置かせない）
        try {
            $this->place('bpw', 'no-keys', [$this->paragraph(self::FREE)], [$this->paragraph(self::PAID)]);
            $this->fail('読める条件の無い有料の分が保存できてしまった');
        } catch (QueryException $e) {
            $this->assertStringContainsString('blog_posts_paywall_check', $e->getMessage());
        }
    }

    public function test_the_shop_boundary_holds_even_for_a_member_who_has_the_key(): void
    {
        $this->tenant('bpx', 'よその店');
        $this->use('bpx');
        $this->place('bpw', 'first-half-free', [$this->paragraph(self::FREE)], [$this->paragraph(self::PAID)], ['membership:standard']);
        // 聞かれた鍵をそのまま「持っている」と答える App（店の境界の検査が抜けていれば、誰でも読めてしまう）
        $this->sellToAnyone('anyone-sample');

        // よその店で会員になった人は、この店の有料の分を読めない（会員の台帳は店ごと。印を持ち回っても今の店の会員ではない）。
        // 「ログインするとお読みいただけます」が出る＝この店では会員でない人として扱っている（台帳の ID を持ち込めていない）
        $this->login('bpx', 'hanako@example.com');
        $this->enable('bpw', 'anyone-sample');
        $this->enable('bpx', 'anyone-sample');
        $this->assertLacksPaid($this->visit('bpw', '/news/first-half-free')->assertOk()->assertSee('FREEPART')
            ->assertDontSee('PAIDONLY')->assertSee('すでにお買い上げ・ご購読の方は', false));
        // よその店では、同じ記事の URL がそもそも無い
        $this->visit('bpx', '/news/first-half-free')->assertNotFound();

        // 同じ人がこの店で会員になれば読める（App が答えるのは今の店の台帳の ID）
        $this->login('bpw', 'hanako@example.com');
        $this->visit('bpw', '/news/first-half-free')->assertOk()->assertSee('PAIDONLY');

        // 答える App を止めた店では、鍵を持っていても読めない
        $this->inside('bpw', fn () => app(PluginStates::class)->set('anyone-sample', false, 'test'));
        $this->assertLacksPaid($this->visit('bpw', '/news/first-half-free')->assertOk()->assertDontSee('PAIDONLY'));

        // ブログの App を止めた店では、記事そのものが無いのと同じ
        $this->enable('bpw', 'anyone-sample');
        $this->inside('bpw', fn () => app(PluginStates::class)->set('blog', false, 'test'));
        $this->assertLacksPaid($this->visit('bpw', '/news/first-half-free')->assertNotFound());
    }

    public function test_no_other_outlet_leaks_the_paid_part(): void
    {
        $photo = $this->photo('bpw');
        $id = $this->place('bpw', 'first-half-free', [$this->paragraph(self::FREE)], [$this->paragraph(self::PAID)], ['membership:standard']);
        // 抜き書きの無い記事（Atom と検索の説明が本文のはじめから作られる道）
        $this->place('bpw', 'all-paid', [$this->paragraph(self::PAID)], [], ['post:allpaid'], excerpt: null);

        // 一覧: 札は題と抜き書きだけ（本文を持たない形）
        $this->assertLacksPaid($this->visit('bpw', '/news')->assertOk()->assertSee('記事 first-half-free')->assertSee('記事 all-paid')->assertDontSee('PAIDONLY'));

        // 記事の画面の検索と共有の説明も、無料の分から（全部有料の記事は説明に何も出さない）
        $html = (string) $this->visit('bpw', '/news/all-paid')->assertOk()->getContent();
        $this->assertStringNotContainsString('PAIDONLY', $html);

        // Atom: 要約は抜き書きか無料の分だけ
        $this->assertLacksPaid($this->visit('bpw', '/news/feed')->assertOk()->assertSee('記事 first-half-free', false)->assertDontSee('PAIDONLY', false));

        // サイトマップ: 無料の分がある記事は載せる（検索の入口）。全部有料の記事は載せない
        $sitemap = (string) $this->visit('bpw', '/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/news/first-half-free', $sitemap);
        $this->assertStringNotContainsString('/news/all-paid', $sitemap);
        $this->assertStringNotContainsString('PAIDONLY', $sitemap);

        // 新着のお知らせの区画: 並べるのは札（PostCard）だけで、本文を持ち回らない
        $section = $this->inside('bpw', fn (): ?NewsSection => app(BlogSections::class)->content('app_blog', ['count' => 'n3']));
        $this->assertNotNull($section);
        $this->assertSame(['記事 all-paid', '記事 first-half-free'], array_map(static fn ($card): string => $card->title, $section->items));
        $this->assertStringNotContainsString('PAIDONLY', json_encode($section, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // プレビュー: 署名が合えば書いた本人として全部見える。署名を書き換えた・無いものは「見つかりません」で本文も出ない
        $url = BlogPreviewLink::issue($this->tenants[0], $id);
        $this->assertIsString($url);
        $this->visit('bpw', (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY))->assertOk()->assertSee('PAIDONLY');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = (string) $query['token'];
        $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $this->assertLacksPaid($this->visit('bpw', "/p/blog/preview/{$id}?expires={$query['expires']}&token={$tampered}")->assertNotFound());
        $this->assertLacksPaid($this->visit('bpw', "/p/blog/preview/{$id}")->assertNotFound());

        // ログインの後に記事へ戻る道は、ショップに出ている記事だけ
        $this->visit('bpw', "/p/blog/read/{$id}")->assertRedirect('/news/first-half-free');
        $this->inside('bpw', fn () => BlogPost::query()->whereKey($id)->update(['status' => BlogPost::DRAFT, 'published_at' => null]));
        $this->visit('bpw', "/p/blog/read/{$id}")->assertNotFound();
    }

    public function test_the_form_refuses_keys_the_core_would_throw_away(): void
    {
        $this->enrolledOperator();
        $many = implode("\n", array_map(static fn (int $i): string => "post:P{$i}", range(1, BlogPaywall::MAX_KEYS + 1)));

        foreach ([
            '種類が無い' => ['access_keys' => 'standard'],
            '大文字の種類' => ['access_keys' => 'Membership:standard'],
            '区切りが 2 つ' => ['access_keys' => 'a:b:c'],
            '名前が無い' => ['access_keys' => 'membership:'],
            '道筋' => ['access_keys' => 'membership:../../etc/passwd'],
            '長すぎる名前' => ['access_keys' => 'membership:'.str_repeat('a', 100)],
            '2 行目が違う' => ['access_keys' => "membership:standard\nBAD"],
            '上限を超えた数' => ['access_keys' => $many],
            '条件の無い「ここから有料」' => ['paid_body' => [['type' => 'paragraph', 'text' => self::PAID]]],
        ] as $case => $values) {
            $this->send('POST', self::BASE, ['values' => $values + ['title' => 'タイトル', 'slug' => 'paid-post']])
                ->assertUnprocessable()->assertJsonValidationErrors(['values.access_keys']);
            $this->assertSame(0, $this->inside('bpw', fn (): int => BlogPost::query()->count()), $case);
        }

        // 本文と有料の分は合わせて 100 ブロックまで
        $blocks = array_fill(0, 51, ['type' => 'paragraph', 'text' => 'あ']);
        $this->send('POST', self::BASE, ['values' => ['title' => 'タイトル', 'slug' => 'paid-post', 'body' => $blocks, 'paid_body' => $blocks, 'access_keys' => 'membership:standard']])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.paid_body']);

        // 正しい形は保存でき、画面に読み直せる（重なった鍵は 1 つに）
        $id = $this->send('POST', self::BASE, ['values' => [
            'title' => '有料の記事', 'slug' => 'paid-post', 'body' => [['type' => 'paragraph', 'text' => self::FREE]],
            'paid_body' => [['type' => 'paragraph', 'text' => self::PAID]], 'access_keys' => " membership:standard \npost:01jbq\nmembership:standard\n",
        ]])->assertCreated()->json('id');
        $this->assertIsString($id);
        $this->send('GET', self::BASE.'/'.$id)->assertOk()
            ->assertJsonPath('form.values.access_keys', "membership:standard\npost:01jbq")
            ->assertJsonPath('form.values.paid_body.0.text', self::PAID)
            ->assertJsonFragment(['label' => '読める条件（鍵）', 'value' => 'membership:standard、post:01jbq'])
            ->assertJsonFragment(['label' => '有料の部分', 'value' => '「ここから有料」より後ろの 1 ブロック']);

        // 「読める条件」を外すと、有料の分も空にしないと保存できない（半端な行を作らない）
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['access_keys' => null]])->assertUnprocessable()->assertJsonValidationErrors(['values.access_keys']);
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['access_keys' => null, 'paid_body' => []]])->assertOk();
        $this->assertSame([[], []], $this->inside('bpw', function () use ($id): array {
            $post = BlogPost::query()->findOrFail($id);

            return [$post->access_keys, $post->paid_body];
        }));
    }

    /**
     * 有料の文がレスポンスのどこにも無いこと（本文・JSON・ヘッダ）
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertLacksPaid(TestResponse $response): void
    {
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('PAIDONLY', $body);
        $this->assertStringNotContainsString('PAIDONLY', json_encode($response->headers->all(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'text' => $text];
    }

    /**
     * 店の DB に直に記事を置く（公開・1 分前から）
     *
     * @param  list<array<string, mixed>>  $body
     * @param  list<array<string, mixed>>  $paid
     * @param  list<string>  $keys
     */
    private function place(string $shop, string $slug, array $body, array $paid = [], array $keys = [], ?string $excerpt = '抜き書きです。'): string
    {
        return $this->inside($shop, fn (): string => BlogPost::query()->create([
            'category_id' => BlogCategory::query()->where('slug', 'news')->value('id'),
            'title' => "記事 {$slug}",
            'slug' => $slug,
            'excerpt' => $excerpt,
            'body' => $body,
            'access_keys' => $keys,
            'paid_body' => $paid,
            'status' => BlogPost::PUBLISHED,
            'published_at' => now()->subMinute(),
        ])->id);
    }

    /**
     * 決まったお客さんに決まった鍵を答える見本の App（売る側。区切り 3・4 の代わり）
     *
     * @param  array<string, list<string>>  $holders  お客さんの台帳の ID => 持っている鍵
     */
    private function sell(string $name, array $holders): void
    {
        app(PluginRegistry::class)->register(new PluginDefinition(
            name: $name, title: '見本の売る App', description: '', pluginApi: PluginRegistry::API_VERSION,
            contentAccess: new class($holders) implements ContentAccessProvider
            {
                /** @param array<string, list<string>> $holders */
                public function __construct(private readonly array $holders) {}

                public function held(string $accountId, array $keys): array
                {
                    return array_values(array_intersect($keys, $this->holders[$accountId] ?? []));
                }
            },
        ));
    }

    /**
     * 誰にでも「持っている」と答える見本の App（店の境界・聞かない決まりが抜けていれば、これで漏れる）
     */
    private function sellToAnyone(string $name): void
    {
        app(PluginRegistry::class)->register(new PluginDefinition(
            name: $name, title: '見本の売る App', description: '', pluginApi: PluginRegistry::API_VERSION,
            contentAccess: new class implements ContentAccessProvider
            {
                public function held(string $accountId, array $keys): array
                {
                    return $keys;
                }
            },
        ));
    }

    private function use(string $shop): void
    {
        $this->enable($shop, 'blog');
    }

    private function enable(string $shop, string $plugin): void
    {
        $this->inside($shop, fn () => app(PluginStates::class)->set($plugin, true, 'test'));
    }

    private function photo(string $shop): string
    {
        return $this->inside($shop, fn (): string => ShopMedia::query()->create(['alt' => '有料の写真 PAIDPHOTO', 'width' => 1200, 'height' => 800, 'checksum' => str_repeat('b', 64), 'bytes' => 1000])->id);
    }

    private function account(string $shop, string $email): string
    {
        return $this->inside($shop, fn (): string => (string) CustomerAccount::query()->get()->first(static fn (CustomerAccount $a): bool => $a->email === $email)?->id);
    }

    /**
     * ショップで会員になる（メールのコードのログイン）
     */
    private function login(string $shop, string $email, ?string $back = null): void
    {
        $this->member = null;
        $this->visit($shop, '/account/login', ['email' => $email]);
        $code = Mail::sent(LoginCodeMail::class)->last()?->code;
        $this->assertIsString($code);
        $this->visit($shop, '/account/login/code', ['code' => $code])->assertRedirect($back);
    }

    /**
     * ショップへ送る（会員の印を持ち回る）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function visit(string $shop, string $path, array $data = []): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $response = $data === [] ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }
}
