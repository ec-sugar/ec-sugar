<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Carbon\CarbonImmutable;
use EcSugar\PluginFavorites\AddOutcome;
use EcSugar\PluginFavorites\FavoriteNotices;
use EcSugar\PluginFavorites\Favorites;
use EcSugar\PluginFavorites\Mail\FavoriteNoticeMail;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use EcSugar\PluginNewsletter\SubscriberStatus;
use EcSugar\PluginRestock\Models\RestockRequest;
use EcSugar\PluginRestock\RestockStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「お気に入り」（ADR 0071）。商品の画面と一覧の札のハート（JS あり・なし）、会員でない人はログインへ（戻り先は App の道筋だけ）、
 * 会員でない・ほかの会員の・ほかの店の・出ていない商品、500 件の境界、二重に押す、公開を終えた商品、
 * 値下げと再入荷の知らせ（同意なしは送らない・再入荷の App と 2 通にしない・1 日 1 通）、配信の停止、管理画面は数だけ、退会で消える
 */
final class FavoritesAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const array LEGAL = [
        ['label' => '販売業者', 'value' => '株式会社かくざとう'],
        ['label' => '所在地', 'value' => '東京都渋谷区神宮前 1-2-3'],
        ['label' => '電話番号', 'value' => '03-1234-5678'],
    ];

    private ?string $member = null;

    private string $mug;

    private string $tote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->configureAdminHost();
        $this->tenant('fva', 'かくざとう');
        [$this->mug, $this->tote] = $this->inside('fva', function (): array {
            app(PluginStates::class)->set('favorites', true, 'test');
            app(ShopSettingsService::class)->setLegal(['items' => self::LEGAL], 'test');
            $mug = $this->listing('白いマグカップ', 1800);
            $tote = $this->listing('帆布のトートバッグ', 5400);
            app(StockService::class)->receive(Listing::query()->findOrFail($mug), Location::query()->firstOrFail(), 5);

            return [$mug, $tote];
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_heart_is_on_the_product_page_and_every_card_only_when_the_app_is_used(): void
    {
        // 商品の画面: POST のフォーム（CSRF つき）。売り切れの商品（トート）にも出る
        foreach ([$this->mug, $this->tote] as $listing) {
            $html = $this->page("/products/{$listing}");
            $this->assertStringContainsString('data-test="toggle-favorites"', $html);
            $this->assertStringContainsString('action="/p/favorites/'.$listing.'"', $html);
            $this->assertStringContainsString('aria-pressed="false"', $html);
            $this->assertStringContainsString('name="_token"', $html);
        }

        // 4 つの公式テーマの一覧のどの札にも（札のリンクの外に）
        foreach (['default', 'kinari', 'fashion', 'botanica'] as $theme) {
            $this->tenants[0]->forceFill(['theme' => $theme])->save();
            $html = $this->page('/products');
            $this->assertSame(2, substr_count($html, 'data-test="card-options"'), $theme);
            $this->assertSame(0, preg_match('#<a [^>]*data-test="product-card"(?:(?!</a>).)*data-sugar-toggle#s', $html), "{$theme}: ボタンが札のリンクの中にある");
        }

        // 使わない店では出さず、受け口も 404
        $this->inside('fva', fn () => app(PluginStates::class)->set('favorites', false, 'test'));
        $this->assertStringNotContainsString('data-sugar-toggle', $this->page("/products/{$this->mug}"));
        $this->assertStringNotContainsString('data-test="card-options"', $this->page('/products'));
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '1'])->assertNotFound();
    }

    public function test_a_guest_is_sent_to_the_login_and_only_the_product_they_pressed_is_added_after_it(): void
    {
        // JS の無い時はログインへ。戻り先は App の道筋（核のログインが /p/ の下だけへ戻す）
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '1', 'back' => '/products'])
            ->assertRedirect('/account/login?return='.rawurlencode("/p/favorites/back/{$this->mug}"));
        // JS の時は 401 とログインの道筋
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '1'], json: true)->assertStatus(401)
            ->assertJsonPath('login', '/account/login?return='.rawurlencode("/p/favorites/back/{$this->mug}"));
        $this->assertSame(0, $this->rows());

        // ログインすると戻り先へ → 押した商品だけを足して商品の画面へ
        $this->shop('GET', '/account/login?return='.rawurlencode("/p/favorites/back/{$this->mug}"))->assertOk();
        $this->login('hanako@example.com')->assertRedirect("/p/favorites/back/{$this->mug}");
        $this->shop('GET', "/p/favorites/back/{$this->mug}")->assertRedirect("http://fva.localhost/products/{$this->mug}");
        $this->assertSame([$this->mug], $this->ids('hanako@example.com'));

        // 押していない商品の戻り先の URL を開いただけでは足さない（URL を踏ませて足させない）
        $this->shop('GET', "/p/favorites/back/{$this->tote}")->assertRedirect("http://fva.localhost/products/{$this->tote}");
        $this->assertSame([$this->mug], $this->ids('hanako@example.com'));
    }

    public function test_a_member_adds_and_removes_with_and_without_javascript_and_pressing_twice_keeps_one(): void
    {
        $this->login('hanako@example.com');

        // JS の無い時: 押した後の形を送り、元の画面へ戻る。2 回押しても 1 件
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '1', 'back' => '/products?sort=new'])->assertRedirect('http://fva.localhost/products?sort=new');
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '1', 'back' => '/products'])->assertRedirect('http://fva.localhost/products');
        $this->assertSame(1, $this->rows());
        $this->assertStringContainsString('aria-pressed="true"', $this->page("/products/{$this->mug}"));

        // 戻り先は自分のショップの中だけ（外のホスト・// で始まる道筋は商品の画面へ）
        foreach (['//evil.example/x', 'https://evil.example/', '/\\evil.example', "/x\r\nLocation: https://evil.example"] as $back) {
            $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '1', 'back' => $back])->assertRedirect("http://fva.localhost/products/{$this->mug}");
        }

        // JS の時: 答えで形と文字を替える
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '0'], json: true)->assertOk()
            ->assertJson(['pressed' => false, 'label' => 'お気に入りに追加']);
        $this->shop('POST', "/p/favorites/{$this->tote}", ['pressed' => '1'], json: true)->assertOk()
            ->assertJson(['pressed' => true, 'label' => 'お気に入りから削除']);
        $this->assertSame([$this->tote], $this->ids('hanako@example.com'));

        // 押した後の形が読めない値は何も変えない
        $this->shop('POST', "/p/favorites/{$this->tote}", ['pressed' => 'toggle'], json: true)->assertStatus(422);
        $this->assertSame([$this->tote], $this->ids('hanako@example.com'));

        // 同じ会員 × 商品の 2 行目は DB が拒む（同時に 2 回押した 2 つ目の守り）
        $this->inside('fva', function (): void {
            $row = (array) DB::table('favorites_items')->sole();
            $this->expectException(UniqueConstraintViolationException::class);
            DB::table('favorites_items')->insert([...$row, 'id' => strtolower((string) Str::ulid())]);
        });
    }

    public function test_only_products_on_the_shop_can_be_added_and_members_only_touch_their_own(): void
    {
        $this->tenant('fvb');
        $other = $this->inside('fvb', fn (): string => $this->listing('ほかの店の品', 900));
        $draft = $this->inside('fva', fn (): string => $this->listing('下書きの品', 900, ListingStatus::Draft));
        $this->login('taro@example.com');
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '1'], json: true)->assertOk();
        $this->member = null;
        $this->login('hanako@example.com');

        // 下書き・ほかの店の商品・知らない ID は足せない
        foreach ([$draft, $other, strtolower((string) Str::ulid())] as $listing) {
            $this->shop('POST', "/p/favorites/{$listing}", ['pressed' => '1'], json: true)->assertStatus(422)->assertJsonPath('pressed', false);
        }

        $this->assertSame([], $this->ids('hanako@example.com'));

        // ほかの会員の印は、外そうとしても変わらない（会員の印は自分の分だけ）。一覧にも出ない
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '0'], json: true)->assertOk()->assertJsonPath('pressed', false);
        $this->assertSame([$this->mug], $this->ids('taro@example.com'));
        $this->shop('GET', '/p/favorites/list')->assertOk()->assertSee('data-test="favorites-empty"', false);

        // 一覧はログインしていなければログインへ
        $this->member = null;
        $this->shop('GET', '/p/favorites/list')->assertRedirect('http://fva.localhost/account/login?return='.rawurlencode('/p/favorites/list'));
    }

    public function test_a_member_can_keep_up_to_five_hundred(): void
    {
        $this->login('hanako@example.com');
        $account = $this->account('hanako@example.com');

        // 499 件を先に持たせる（出ていない商品でも数に入る）
        $this->inside('fva', function () use ($account): void {
            $base = (array) DB::table('listings')->where('id', $this->tote)->sole();
            $now = CarbonImmutable::now();

            foreach (array_chunk(range(1, Favorites::MAX - 1), 250) as $chunk) {
                $listings = $items = [];

                foreach ($chunk as $n) {
                    $id = strtolower((string) Str::ulid());
                    $listings[] = [...$base, 'id' => $id, 'title' => "品 {$n}", 'status' => ListingStatus::Archived->value];
                    $items[] = ['id' => strtolower((string) Str::ulid()), 'customer_account_id' => $account, 'listing_id' => $id, 'created_at' => $now];
                }

                DB::table('listings')->insert($listings);
                DB::table('favorites_items')->insert($items);
            }
        });

        // 500 件目は足せて、501 件目は断る（外すと、また足せる）
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '1'], json: true)->assertOk()->assertJsonPath('pressed', true);
        $this->shop('POST', "/p/favorites/{$this->tote}", ['pressed' => '1'], json: true)->assertStatus(422)->assertJsonPath('pressed', false)
            ->assertJsonPath('message', 'お気に入りは 500 件までです。いらないものを削除してから追加してください。');
        $this->assertSame(Favorites::MAX, $this->rows());
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '0'], json: true)->assertOk();
        $this->assertSame(AddOutcome::Added, $this->inside('fva', fn () => app(Favorites::class)->add($account, $this->tote)));
        $this->assertSame(AddOutcome::Full, $this->inside('fva', fn () => app(Favorites::class)->add($account, $this->mug)));
    }

    public function test_an_unpublished_favorite_stays_in_the_list_can_be_removed_and_is_not_told(): void
    {
        $this->login('hanako@example.com');
        $this->shop('POST', "/p/favorites/{$this->mug}", ['pressed' => '1'], json: true)->assertOk();
        $this->shop('POST', "/p/favorites/{$this->tote}", ['pressed' => '1'], json: true)->assertOk();
        $this->inside('fva', fn () => DB::table('listings')->where('id', $this->tote)->update(['status' => ListingStatus::Archived->value]));

        $list = $this->shop('GET', '/p/favorites/list')->assertOk();
        $list->assertSee('白いマグカップ')->assertSee('data-test="favorites-gone"', false)->assertSee('現在は販売していません');
        $this->shop('POST', "/p/favorites/{$this->tote}", ['pressed' => '0', 'back' => '/p/favorites/list'])->assertRedirect('http://fva.localhost/p/favorites/list');
        $this->assertSame([$this->mug], $this->ids('hanako@example.com'));
        // また足すことはできない
        $this->shop('POST', "/p/favorites/{$this->tote}", ['pressed' => '1'], json: true)->assertStatus(422);
    }

    public function test_price_drops_and_restocks_are_told_once_a_day_only_to_members_who_chose_and_agreed(): void
    {
        $this->useNewsletter();
        $this->login('hanako@example.com');
        $this->favorite([$this->mug, $this->tote]);
        $this->choose(true, true);
        $this->subscribe('hanako@example.com');
        // 同意していない会員（同じ商品・同じ選択）
        $this->member = null;
        $this->login('taro@example.com');
        $this->favorite([$this->mug, $this->tote]);
        $this->choose(true, true);

        // 1 日目: 控えるだけ（比べる相手が無い）
        $this->notify();
        $this->assertSame(0, $this->mails());

        // 2 日目: マグが値下げ、トートが入荷 → 同意した 1 人へ 1 通にまとめる
        $this->travel(1)->days();
        $this->inside('fva', function (): void {
            DB::table('listings')->where('id', $this->mug)->update(['unit_amount' => 1500]);
            app(StockService::class)->receive(Listing::query()->findOrFail($this->tote), Location::query()->firstOrFail(), 2);
        });
        $this->assertSame(['changed' => 2, 'queued' => 1], $this->notify());
        $this->assertSame(1, $this->mails());
        $mail = Mail::sent(FavoriteNoticeMail::class)->sole();
        $this->assertTrue($mail->hasTo('hanako@example.com'));
        $this->assertSame([['白いマグカップ', 'price_drop', '¥1,800', '¥1,500'], ['帆布のトートバッグ', 'restock', null, null]],
            array_map(static fn (array $i): array => [$i['title'], $i['kind'], $i['before'], $i['after']], $mail->items));
        $text = $mail->render();
        $this->assertStringContainsString('値下げ: ¥1,800 → ¥1,500（税込）', $text);
        $this->assertStringContainsString('送り主: 株式会社かくざとう', $text);
        $this->assertSame('List-Unsubscribe=One-Click', $mail->headers()->text['List-Unsubscribe-Post']);
        // 記録に載るのは ID と金額だけ（名前・メールは載せない）
        $this->inside('fva', fn () => $this->assertStringNotContainsString('example.com', (string) json_encode(DB::table('favorites_notices')->get())));

        // 同じ日にもう一度流しても、もう送らない（見た値段は日に 1 回だけ書き換える）
        $this->inside('fva', fn () => DB::table('listings')->where('id', $this->mug)->update(['unit_amount' => 1200]));
        $this->assertSame(['changed' => 0, 'queued' => 0], $this->notify());
        $this->assertSame(1, $this->mails());

        // 3 日目: 前の日（2 日目の ¥1,500）と比べて下がった分を知らせる。値上げは知らせない
        $this->travel(1)->days();
        $this->notify();
        $this->assertSame(2, $this->mails());
        $this->assertSame('¥1,500', Mail::sent(FavoriteNoticeMail::class)->last()->items[0]['before']);
        $this->travel(1)->days();
        $this->inside('fva', fn () => DB::table('listings')->where('id', $this->mug)->update(['unit_amount' => 2000]));
        $this->notify();
        $this->assertSame(2, $this->mails());
    }

    public function test_nothing_is_told_without_the_choice_or_the_newsletter_app_and_restocks_are_not_told_twice(): void
    {
        $this->login('hanako@example.com');
        $this->favorite([$this->tote]);
        $this->subscribe('hanako@example.com', newsletter: false);
        $this->notify();
        $this->travel(1)->days();
        $this->inside('fva', fn () => app(StockService::class)->receive(Listing::query()->findOrFail($this->tote), Location::query()->firstOrFail(), 1));

        // 知らせを選んでいない（既定は切）→ 見るものが無い
        $this->assertSame(['changed' => 0, 'queued' => 0], $this->notify());

        // 選んでも、お知らせのメールの受け取りを答える App（メルマガ）を使っていなければ送らない
        $this->choose(false, true);
        $this->inside('fva', function (): void {
            app(StockService::class)->adjust(Listing::query()->findOrFail($this->tote), Location::query()->firstOrFail(), -1);
        });
        $this->notify();
        $this->travel(1)->days();
        $this->inside('fva', fn () => app(StockService::class)->receive(Listing::query()->findOrFail($this->tote), Location::query()->firstOrFail(), 1));
        $this->assertSame(['changed' => 1, 'queued' => 0], $this->notify());
        $this->assertSame(0, $this->mails());

        // 再入荷の App で同じ商品を待っている会員には、こちらから再入荷を知らせない（2 通にしない）
        $this->useNewsletter();
        $account = $this->account('hanako@example.com');
        $this->inside('fva', function () use ($account): void {
            app(PluginStates::class)->set('restock', true, 'test');
            app(StockService::class)->adjust(Listing::query()->findOrFail($this->tote), Location::query()->firstOrFail(), -1);
            $request = new RestockRequest(['listing_id' => $this->tote, 'variant_id' => Listing::query()->findOrFail($this->tote)->soleVariant()?->id, 'email' => 'hanako@example.com', 'token_hash' => hash('sha256', 'x'), 'status' => RestockStatus::Waiting, 'confirmed_at' => CarbonImmutable::now()]);
            $request->customer_account_id = $account;
            $request->save();
        });
        $this->travel(1)->days();
        $this->notify();
        $this->travel(1)->days();
        $this->inside('fva', fn () => app(StockService::class)->receive(Listing::query()->findOrFail($this->tote), Location::query()->firstOrFail(), 1));
        $this->assertSame(['changed' => 1, 'queued' => 0], $this->notify());
        $this->assertSame(0, $this->mails());
    }

    public function test_the_unsubscribe_link_stops_the_notices_only_when_pressed(): void
    {
        $this->useNewsletter();
        $this->login('hanako@example.com');
        $this->favorite([$this->mug]);
        $this->choose(true, false);
        $this->subscribe('hanako@example.com');
        $this->notify();
        $this->travel(1)->days();
        $this->inside('fva', fn () => DB::table('listings')->where('id', $this->mug)->update(['unit_amount' => 1000]));
        $this->notify();
        $path = (string) parse_url(Mail::sent(FavoriteNoticeMail::class)->sole()->unsubscribeUrl, PHP_URL_PATH);

        // 開いただけでは止めない。メールのソフトのワンクリック（CSRF の合い言葉なし）で止まる
        $this->member = null;
        $account = $this->account('hanako@example.com');
        $this->shop('GET', $path)->assertOk()->assertSee('data-test="favorites-unsubscribe"', false);
        $this->assertTrue($this->inside('fva', fn (): bool => app(Favorites::class)->settings($account)['price_drop']));
        $this->shop('POST', $path, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('お知らせを停止しました');
        $this->assertSame(['price_drop' => false, 'restock' => false], $this->inside('fva', fn (): array => app(Favorites::class)->settings($account)));
        $this->shop('GET', $path)->assertOk()->assertSee('すでに停止しています');
        $this->shop('GET', '/p/favorites/unsubscribe/'.str_repeat('A', 43))->assertNotFound();
    }

    public function test_cards_read_the_members_hearts_once_per_page(): void
    {
        $this->inside('fva', function (): void {
            foreach (range(1, 6) as $n) {
                $this->listing("品 {$n}", 1000 + $n);
            }
        });
        $this->login('hanako@example.com');
        $this->favorite([$this->mug]);

        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            $reads += str_contains($query->sql, 'favorites_items') ? 1 : 0;
        });
        $html = $this->page('/products');
        $this->assertSame(8, substr_count($html, 'data-test="card-options"'));
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
        // 札が 8 枚でも、会員の印を読むのは 1 回
        $this->assertSame(1, $reads);
    }

    public function test_the_admin_page_ranks_products_by_count_without_names_and_leaving_removes_everything(): void
    {
        foreach (['a@example.com', 'b@example.com'] as $email) {
            $this->member = null;
            $this->login($email);
            $this->favorite([$this->mug]);
        }

        $this->favorite([$this->tote]);
        $this->choose(true, false);

        $this->enrolledOperator();
        $list = $this->send('GET', '/api/tenants/fva/apps/favorites/records')->assertOk();
        $list->assertJsonPath('rows.0.id', $this->mug)->assertJsonPath('rows.0.cells.count', 2)->assertJsonPath('rows.0.cells.rank', 1)
            ->assertJsonPath('rows.1.id', $this->tote)->assertJsonPath('rows.1.cells.count', 1);
        $this->assertStringNotContainsString('example.com', (string) $list->getContent());
        $record = $this->send('GET', "/api/tenants/fva/apps/favorites/records/{$this->tote}")->assertOk();
        $record->assertJsonPath('record.title', '帆布のトートバッグ');
        $this->assertStringNotContainsString('example.com', (string) $record->getContent());

        // 退会すると、その会員の印・選択が消える
        $b = $this->account('b@example.com');
        $this->inside('fva', fn () => app(CustomerAccounts::class)->leave(CustomerAccount::query()->findOrFail($b), 'test'));
        $this->assertSame([], $this->ids('b@example.com'));
        $this->assertSame(0, $this->inside('fva', fn (): int => DB::table('favorites_settings')->count()));
        $this->assertSame([$this->mug], $this->ids('a@example.com'));
    }

    private function listing(string $title, int $amount, ListingStatus $status = ListingStatus::Active): string
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => $status])->id;
    }

    /**
     * @param  list<string>  $listings
     */
    private function favorite(array $listings): void
    {
        foreach ($listings as $listing) {
            $this->shop('POST', "/p/favorites/{$listing}", ['pressed' => '1'], json: true)->assertOk();
        }
    }

    private function choose(bool $priceDrop, bool $restock): void
    {
        $this->shop('POST', '/p/favorites/settings', array_filter(['price_drop' => $priceDrop ? '1' : null, 'restock' => $restock ? '1' : null]))
            ->assertRedirect('http://fva.localhost/p/favorites/list');
    }

    private function useNewsletter(): void
    {
        $this->inside('fva', fn () => app(PluginStates::class)->set('newsletter', true, 'test'));
    }

    /**
     * お知らせのメールの受け取り（メルマガの App の購読）を確かめ済みで作る
     */
    private function subscribe(string $email, bool $newsletter = true): void
    {
        $this->inside('fva', function () use ($email): void {
            $now = CarbonImmutable::now();
            (new NewsletterSubscriber(['email' => $email, 'status' => SubscriberStatus::Active, 'source' => 'form', 'consented_at' => $now]))->forceFill(['confirmed_at' => $now])->save();
        });

        if ($newsletter) {
            $this->useNewsletter();
        }
    }

    /**
     * @return array{changed: int, queued: int}
     */
    private function notify(): array
    {
        return $this->inside('fva', fn (): array => app(FavoriteNotices::class)->run(CarbonImmutable::now()));
    }

    private function mails(): int
    {
        return Mail::sent(FavoriteNoticeMail::class)->count();
    }

    /**
     * @return TestResponse<Response>
     */
    private function login(string $email): TestResponse
    {
        $this->shop('POST', '/account/login', ['email' => $email]);

        return $this->shop('POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code]);
    }

    private function account(string $email): string
    {
        return $this->inside('fva', fn (): string => (string) CustomerAccount::query()->get()->first(static fn (CustomerAccount $a): bool => $a->email === $email)?->id);
    }

    /**
     * 会員のお気に入りの商品の ID（呼ぶたびに DB を読み直す）
     *
     * @return list<string>
     *
     * @phpstan-impure
     */
    private function ids(string $email): array
    {
        $account = $this->account($email);

        return $this->inside('fva', fn (): array => DB::table('favorites_items')->where('customer_account_id', $account)->orderBy('listing_id')->pluck('listing_id')->all());
    }

    private function rows(): int
    {
        return $this->inside('fva', fn (): int => DB::table('favorites_items')->count());
    }

    private function page(string $path): string
    {
        return (string) $this->shop('GET', $path)->assertOk()->getContent();
    }

    /**
     * ショップへ送る（会員の印を持ち回る。送るたびに 7 秒進める〔人の速さ。1 分の回数の上限にテスト自身が当たらないように〕）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = [], bool $json = false): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://fva.localhost{$path}";
        $headers = $json ? ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] : [];
        $response = $method === 'GET' ? $this->get($url, $headers) : $this->post($url, $data, $headers);

        tenancy()->end();
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }
}
